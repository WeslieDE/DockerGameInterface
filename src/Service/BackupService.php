<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Service;

use tk\weslie\SGI\Auth\ServerContext;
use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Docker\RunCommandBuilder;
use tk\weslie\SGI\Http\HttpException;

/**
 * Backups as `.tar.gz` files in the shared named volume `sgi_backup`, mounted
 * in this container at /backup, one sub-folder per token: /backup/<token>/.
 *
 * SGI never mounts game volumes into itself. Creating/restoring runs an
 * ephemeral helper that reads the game data via `--volumes-from` (VolumesFrom)
 * and writes to the same sgi_backup volume. Listing, download and delete work
 * directly on /backup/<token>/, which SGI has mounted itself.
 *
 * create() and restore() both stop the game container before touching its data
 * and restore its prior state afterwards (running -> restarted, stopped -> left
 * stopped). To keep that stop/work/restore sequence atomic regardless of the PHP
 * request lifetime, each runs entirely inside the helper: a `docker:cli` image
 * with the Docker socket bound in drives the game container itself. That socket
 * is the one deliberate break from the otherwise socket-free helper isolation,
 * and is used by create()/restore() only.
 *
 * Both are fire-and-forget and stamp LABEL_TARGET, so isRunningFor() reports an
 * operation in progress for either — which is what locks Start/Restart (in the
 * UI and server-side) for the duration.
 *
 * Isolation: every filesystem op is hard-clamped to /backup/<token>/ of the
 * logged-in token; ids/names are validated to a safe basename (no traversal).
 */
final class BackupService
{
    private const HELPER_IMAGE = 'alpine';
    // Small alpine-based image that ships the docker CLI; used for create(),
    // where the helper controls the game container over the mounted socket.
    private const DOCKER_IMAGE  = 'docker:cli';
    private const MOUNT_ROOT    = '/backup';
    private const BACKUP_VOLUME = 'sgi_backup';

    // Label stamped on the create() helper so a running backup can be detected
    // from Docker itself — authoritative and self-cleaning: the helper vanishes
    // when the backup ends, even if the triggering PHP request already died.
    private const LABEL_TARGET = 'sgi.backup.target';

    public function __construct(
        private readonly DockerClient $docker,
    ) {}

    /* ---------------------------------------------------------------- */
    /* Listing / download / delete (filesystem only)                    */
    /* ---------------------------------------------------------------- */

    /** @return array<int,array<string,mixed>> */
    public function list(ServerContext $ctx): array
    {
        $dir = $this->tokenDir($ctx);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (!$this->isBackupFile($file)) {
                continue;
            }
            $path = $dir . '/' . $file;
            $out[] = [
                'id'      => $file,
                'name'    => preg_replace('/\.tar\.gz$/', '', $file),
                'size'    => $this->humanSize((int) filesize($path)),
                'created' => date('c', (int) filemtime($path)),
                'status'  => 'ok',
            ];
        }
        // Newest first.
        usort($out, static fn($a, $b) => strcmp($b['created'], $a['created']));
        return $out;
    }

    /**
     * Disk-usage figures for the Backups page:
     *  - disk free / total for the volume that holds the backups
     *  - total bytes used by *this* container's (token's) backups
     *
     * @return array<string,mixed>
     */
    public function stats(ServerContext $ctx): array
    {
        // Free/total for the filesystem backing the sgi_backup volume. Measured
        // at MOUNT_ROOT (always present) rather than the per-token dir, which
        // may not exist yet before the first backup.
        $free  = @disk_free_space(self::MOUNT_ROOT);
        $total = @disk_total_space(self::MOUNT_ROOT);
        $free  = is_float($free) ? (int) $free : null;
        $total = is_float($total) ? (int) $total : null;
        $diskUsed = ($free !== null && $total !== null) ? $total - $free : null;

        // Sum this token's backups only.
        $used  = 0;
        $count = 0;
        $dir = $this->tokenDir($ctx);
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $file) {
                if (!$this->isBackupFile($file)) {
                    continue;
                }
                $used += (int) filesize($dir . '/' . $file);
                $count++;
            }
        }

        return [
            'disk' => [
                'free'    => $free,
                'total'   => $total,
                'used'    => $diskUsed,
                'freeH'   => $free !== null ? $this->humanSize($free) : null,
                'totalH'  => $total !== null ? $this->humanSize($total) : null,
                'usedH'   => $diskUsed !== null ? $this->humanSize($diskUsed) : null,
            ],
            'backups' => [
                'count' => $count,
                'bytes' => $used,
                'sizeH' => $this->humanSize($used),
            ],
        ];
    }

    /** Absolute path of a validated backup file, or throws 404. */
    public function pathFor(ServerContext $ctx, string $id): string
    {
        $id = $this->safeId($id);
        $path = $this->tokenDir($ctx) . '/' . $id;
        if (!is_file($path)) {
            throw new HttpException(404, 'backup not found');
        }
        return $path;
    }

    public function delete(ServerContext $ctx, string $id): void
    {
        // Confirm the archive exists (throws 404) and validate the id.
        $this->pathFor($ctx, $id);
        $id    = $this->safeId($id);
        $token = $this->safeToken($ctx->token);

        // Files in sgi_backup are written by the root helper, so www-data
        // (Apache) cannot unlink them directly — the token dir is root-owned.
        // Delete through the same privileged helper, clamped to /out/<token>/.
        $this->docker->ensureImage(self::HELPER_IMAGE);
        $script = sprintf('rm -f /out/%s/%s', $token, $id);
        $this->runHelper($script, null, readOnly: true);
    }

    /* ---------------------------------------------------------------- */
    /* Create / restore (helper containers)                             */
    /* ---------------------------------------------------------------- */

    /** @return array{id:string,status:string} */
    public function create(ServerContext $ctx): array
    {
        $path  = $this->requireBackupPath($ctx);
        $token = $this->safeToken($ctx->token);
        $file  = 'backup-' . date('Ymd-His') . '.tar.gz';

        // One backup per container at a time — do not stack helpers that would
        // each stop/start the same container.
        if ($this->isRunningFor($ctx->id)) {
            throw new HttpException(409, 'a backup is already running');
        }

        $this->docker->ensureImage(self::DOCKER_IMAGE);

        // The whole sequence — remember the state, stop the container, archive
        // the quiesced data, then restore the previous state — runs INSIDE the
        // helper (docker CLI over the mounted socket). This way it does not
        // depend on the PHP request surviving to the end: once the helper is
        // started, Docker runs it to completion, so the container is always
        // brought back to its prior state (running -> restarted, stopped -> left
        // stopped) even if PHP times out or dies mid-request.
        $id   = $this->shArg($ctx->id);
        $dest = sprintf('/out/%s/%s', $token, $file);

        // SGI.Info.txt bundled into every archive: the reproduction `docker run`
        // command at the very top, a separator, then the container's console
        // output. The header is built here (from the inspect data SGI already
        // holds); the log tail is captured inside the helper via `docker logs`
        // so we do not have to marshal 500 lines through the shell.
        $header = $this->shArg($this->infoHeader($ctx));

        $q = $this->shArg($path);

        $lines = [
            // "true"/"false" — captured before we touch the container.
            'running=$(docker inspect -f \'{{.State.Running}}\' ' . $id . ')',
            // Write SGI.Info.txt (run command + separator), then append last 500
            // console lines. Done before the stop so it reflects the running
            // state; `docker logs` still works afterwards, but this keeps it
            // deterministic. Never let a log hiccup fail the backup.
            'printf \'%s\' ' . $header . ' > /tmp/SGI.Info.txt',
            'docker logs --tail 500 ' . $id . ' >> /tmp/SGI.Info.txt 2>&1 || true',
            // Ensure it is really stopped before reading; abort if stop fails
            // (the container then stays in its original running state).
            'if [ "$running" = "true" ]; then docker stop ' . $id . ' >/dev/null || exit 1; fi',
            'mkdir -p /out/' . $token,
            // Bundle SGI.Info.txt into the archive AT THE ROOT next to the data.
            // We cannot use a second `tar -C` for this: the helper runs BusyBox
            // tar (alpine/docker:cli), whose option parser makes only the LAST -C
            // win for every member — so `-C data . -C /tmp SGI.Info.txt` archived
            // just /tmp (i.e. only the info file). BusyBox tar also lacks `-r`
            // (append), so instead we drop the file into the (now stopped,
            // rw-mounted) data dir, archive it in with a single -C, then remove
            // it again. The `|| true` guards keep a data backup from ever failing
            // over the info file.
            'cp /tmp/SGI.Info.txt ' . $q . '/SGI.Info.txt 2>/dev/null || true',
            'tar czf ' . $dest . ' -C ' . $q . ' .',
            'rc=$?',
            'rm -f ' . $q . '/SGI.Info.txt 2>/dev/null || true',
            // Always restore a previously running container, even if tar failed.
            'if [ "$running" = "true" ]; then docker start ' . $id . ' >/dev/null; fi',
            'exit $rc',
        ];

        // Fire-and-forget: start the helper and return immediately. Backups can
        // take a long time; blocking the request until completion would time out
        // (the cURL /wait as well as the browser) and would keep the user's
        // session request hanging. Progress is tracked afterwards purely via
        // isRunningFor() (the LABEL_TARGET label); AutoRemove lets Docker discard
        // the helper once it exits, so no follow-up request is needed.
        //
        // Data is mounted read-write (not the usual :ro) so the helper can drop
        // SGI.Info.txt into the data dir, archive it in, and remove it again —
        // see the tar note above. Safe here: the container is stopped for the
        // duration and the file is cleaned up within the same helper run. The
        // socket lets the helper stop/start the game container itself.
        $this->runHelper(
            implode('; ', $lines),
            $ctx->id,
            readOnly: false,
            image: self::DOCKER_IMAGE,
            withDockerSocket: true,
            labels: [self::LABEL_TARGET => $ctx->id],
            wait: false,
        );

        return ['id' => $file, 'status' => 'started'];
    }

    /**
     * True while a create() backup for this game container is still in progress,
     * i.e. its labelled helper container is still running. Read straight from
     * Docker so it stays correct across separate HTTP requests and even if the
     * PHP request that started the backup has since died.
     */
    public function isRunningFor(string $gameId): bool
    {
        $helpers = $this->docker->listContainers([
            'label'  => [self::LABEL_TARGET . '=' . $gameId],
            'status' => ['running'],
        ]);
        return $helpers !== [];
    }

    public function restore(ServerContext $ctx, string $id): array
    {
        $id    = $this->safeId($id);
        $path  = $this->requireBackupPath($ctx);
        $token = $this->safeToken($ctx->token);

        // Confirm the archive exists before touching the container.
        $this->pathFor($ctx, $id);

        // One backup/restore per container at a time — do not stack helpers that
        // would each stop/start the same container (and fight over its data).
        if ($this->isRunningFor($ctx->id)) {
            throw new HttpException(409, 'a backup or restore is already running');
        }

        $this->docker->ensureImage(self::DOCKER_IMAGE);

        // Like create(), the whole sequence — remember the state, stop the
        // container, wipe + unpack the archive over the quiesced data, then
        // restore the previous state — runs INSIDE the helper (docker CLI over
        // the mounted socket). Once started, Docker runs it to completion, so the
        // container is always brought back to its prior state (running ->
        // restarted, stopped -> left stopped) even if PHP times out or dies.
        $cid    = $this->shArg($ctx->id);
        $target = $this->shArg($path);

        $lines = [
            // "true"/"false" — captured before we touch the container.
            'running=$(docker inspect -f \'{{.State.Running}}\' ' . $cid . ')',
            // Ensure it is really stopped before writing; abort if stop fails
            // (the container then stays in its original running state, untouched).
            'if [ "$running" = "true" ]; then docker stop ' . $cid . ' >/dev/null || exit 1; fi',
            // Wipe the target dir, then unpack the archive into it.
            'rm -rf ' . $target . '/* ' . $target . '/.[!.]* ' . $target . '/..?* 2>/dev/null || true',
            'tar xzf /out/' . $token . '/' . $id . ' -C ' . $target,
            'rc=$?',
            // Drop the bundled SGI.Info.txt so it does not leak into the live game
            // data (it is metadata, not state).
            'rm -f ' . $target . '/SGI.Info.txt 2>/dev/null || true',
            // Always restore a previously running container, even if unpack failed.
            'if [ "$running" = "true" ]; then docker start ' . $cid . ' >/dev/null; fi',
            'exit $rc',
        ];

        // Fire-and-forget, exactly like create(): start the helper and return.
        // Progress is tracked purely via isRunningFor() (the LABEL_TARGET label),
        // which locks Start/Restart until the restore finishes.
        $this->runHelper(
            implode('; ', $lines),
            $ctx->id,
            readOnly: false,
            image: self::DOCKER_IMAGE,
            withDockerSocket: true,
            labels: [self::LABEL_TARGET => $ctx->id],
            wait: false,
        );

        return ['id' => $id, 'status' => 'started'];
    }

    /* ---------------------------------------------------------------- */
    /* Internals                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Run a one-shot alpine helper with sgi_backup at /out and, when $gameId is
     * given, the game container's volumes mounted (read-only if $readOnly).
     * Pure /out operations (e.g. delete) pass $gameId = null. Throws on
     * non-zero exit.
     */
    private function runHelper(
        string $script,
        ?string $gameId,
        bool $readOnly,
        string $image = self::HELPER_IMAGE,
        bool $withDockerSocket = false,
        array $labels = [],
        bool $wait = true,
    ): void {
        $binds = [self::BACKUP_VOLUME . ':/out'];
        if ($withDockerSocket) {
            // Lets the helper stop/start the game container itself. Only used by
            // create(); other helpers keep the socket-free isolation.
            $binds[] = '/var/run/docker.sock:/var/run/docker.sock';
        }
        $hostConfig = [
            // Detached helpers ($wait === false) clean themselves up when they
            // exit; waited helpers are removed explicitly below (so /wait can
            // still read their exit code).
            'AutoRemove' => !$wait,
            'Binds'      => $binds,
        ];
        if ($gameId !== null) {
            $hostConfig['VolumesFrom'] = [$gameId . ($readOnly ? ':ro' : '')];
        }
        $spec = [
            'Image'      => $image,
            'Cmd'        => ['sh', '-c', $script],
            'HostConfig' => $hostConfig,
        ];
        if ($labels !== []) {
            $spec['Labels'] = $labels;
        }

        $helperId = $this->docker->createContainer($spec);

        if (!$wait) {
            // Start and return; Docker runs it to completion on its own and
            // (via AutoRemove) discards it afterwards. If the start itself fails
            // the exception surfaces synchronously, which is what we want.
            $this->docker->start($helperId);
            return;
        }

        try {
            $code = $this->docker->runToCompletion($helperId);
        } finally {
            $this->docker->removeContainer($helperId);
        }
        if ($code !== 0) {
            throw new HttpException(500, "backup helper exited with code $code");
        }
    }

    private function tokenDir(ServerContext $ctx): string
    {
        return self::MOUNT_ROOT . '/' . $this->safeToken($ctx->token);
    }

    private function requireBackupPath(ServerContext $ctx): string
    {
        $path = $ctx->backupPath();
        if ($path === null || $path === '') {
            throw new HttpException(409, 'no backup path (set sgi.backup.path or attach a named volume)');
        }
        return $path;
    }

    /**
     * Top of the archived SGI.Info.txt: the reproduction `docker run` command,
     * followed by a separator that heads the console log the helper appends.
     */
    private function infoHeader(ServerContext $ctx): string
    {
        $runCmd = (new RunCommandBuilder())->build($ctx->inspect);
        $rule   = str_repeat('=', 72);

        return "# docker run command to recreate this container\n"
            . $runCmd . "\n\n"
            . $rule . "\n"
            . "Console output (last 500 lines)\n"
            . $rule . "\n\n";
    }

    /** Token used as a folder name — restrict to a safe charset. */
    private function safeToken(string $token): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $token)) {
            throw new HttpException(400, 'token contains characters not usable as a backup folder');
        }
        return $token;
    }

    /** Backup id must be a bare "<name>.tar.gz" basename (no traversal). */
    private function safeId(string $id): string
    {
        $id = basename($id);
        if (!$this->isBackupFile($id)) {
            throw new HttpException(400, 'invalid backup id');
        }
        return $id;
    }

    private function isBackupFile(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $name);
    }

    private function shArg(string $value): string
    {
        // Paths come from a trusted label/inspect, but quote defensively.
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($n, 1)) . ' ' . $units[$i];
    }
}
