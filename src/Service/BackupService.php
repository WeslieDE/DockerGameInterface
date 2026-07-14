<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Service;

use tk\weslie\SGI\Auth\ServerContext;
use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Http\HttpException;

/**
 * Backups as `.tar.gz` files in the shared named volume `sgi_backup`, mounted
 * in this container at /backup, one sub-folder per token: /backup/<token>/.
 *
 * SGI never mounts game volumes into itself. Creating/restoring runs an
 * ephemeral `alpine` helper that reads the game data via `--volumes-from`
 * (VolumesFrom) and writes to the same sgi_backup volume. Listing, download
 * and delete work directly on /backup/<token>/, which SGI has mounted itself.
 *
 * Isolation: every filesystem op is hard-clamped to /backup/<token>/ of the
 * logged-in token; ids/names are validated to a safe basename (no traversal).
 */
final class BackupService
{
    private const HELPER_IMAGE = 'alpine';
    private const MOUNT_ROOT    = '/backup';
    private const BACKUP_VOLUME = 'sgi_backup';

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

    /** @return array{id:string} */
    public function create(ServerContext $ctx): array
    {
        $path = $this->requireBackupPath($ctx);
        $token = $this->safeToken($ctx->token);
        $file  = 'backup-' . date('Ymd-His') . '.tar.gz';

        $this->docker->ensureImage(self::HELPER_IMAGE);

        // Read game data read-only, write the archive into sgi_backup.
        $script = sprintf(
            'set -e; mkdir -p /out/%s; tar czf /out/%s/%s -C %s .',
            $token,
            $token,
            $file,
            $this->shArg($path)
        );

        $this->runHelper($script, $ctx->id, readOnly: true);

        return ['id' => $file];
    }

    public function restore(ServerContext $ctx, string $id): void
    {
        $id    = $this->safeId($id);
        $path  = $this->requireBackupPath($ctx);
        $token = $this->safeToken($ctx->token);

        // Confirm the archive exists before touching the container.
        $this->pathFor($ctx, $id);

        $this->docker->ensureImage(self::HELPER_IMAGE);

        // Stop, wipe target dir, unpack, start again.
        try {
            $this->docker->stop($ctx->id);
        } catch (\Throwable) {
            // Already stopped is fine.
        }

        $target = $this->shArg($path);
        $script = sprintf(
            'set -e; rm -rf %s/* %s/.[!.]* %s/..?* 2>/dev/null || true; tar xzf /out/%s/%s -C %s',
            $target,
            $target,
            $target,
            $token,
            $id,
            $target
        );
        $this->runHelper($script, $ctx->id, readOnly: false);

        $this->docker->start($ctx->id);
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
    private function runHelper(string $script, ?string $gameId, bool $readOnly): void
    {
        $hostConfig = [
            'AutoRemove' => false,
            'Binds'      => [self::BACKUP_VOLUME . ':/out'],
        ];
        if ($gameId !== null) {
            $hostConfig['VolumesFrom'] = [$gameId . ($readOnly ? ':ro' : '')];
        }
        $spec = [
            'Image'      => self::HELPER_IMAGE,
            'Cmd'        => ['sh', '-c', $script],
            'HostConfig' => $hostConfig,
        ];

        $helperId = $this->docker->createContainer($spec);
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
