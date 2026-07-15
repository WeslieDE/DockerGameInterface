<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Service;

use tk\weslie\SGI\Compose\ComposeParser;
use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Docker\DockerException;
use tk\weslie\SGI\Http\HttpException;

/**
 * Admin operations, guarded by the master password (see TokenAuth::isMaster).
 *
 * Provides the cross-server view the normal token flow deliberately lacks:
 * list every SGI-managed container, delete one completely (container + its
 * backups), and provision a new one from an uploaded docker-compose file.
 *
 * Consistent with SGI's least-privilege model, the client still never sends a
 * container id — servers are addressed by their sgi.token, resolved here.
 */
final class AdminService
{
    /** Host base path for the enforced per-server bind mounts. */
    private const GAMESERVER_VOLUME_ROOT = '/home/GameServerVolumes';

    /** SGI's own backup volume, auto-injected at /backup (the one named volume). */
    private const BACKUP_VOLUME = 'sgi_backup';

    /** Token charset — unambiguous uppercase (no 0/O/1/I). */
    private const TOKEN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly DockerClient $docker,
        private readonly BackupService $backups,
        private readonly ComposeParser $parser,
    ) {}

    /* ---------------------------------------------------------------- */
    /* Overview                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Every container carrying an sgi.token label. The token is intentionally
     * exposed here — this endpoint is admin-only.
     *
     * @return list<array<string,mixed>>
     */
    public function listServers(): array
    {
        // Label-existence filter (no value) → all SGI-managed containers.
        $containers = $this->docker->listContainers(['label' => ['sgi.token']], all: true);

        $out = [];
        foreach ($containers as $c) {
            $labels = $c['Labels'] ?? [];
            $token  = (string) ($labels['sgi.token'] ?? '');
            if ($token === '') {
                continue;
            }
            $out[] = [
                'token' => $token,
                'name'  => $labels['sgi.name'] ?? ltrim((string) ($c['Names'][0] ?? ''), '/'),
                'image' => (string) ($c['Image'] ?? ''),
                'state' => (string) ($c['State'] ?? ''),
                'status'=> (string) ($c['Status'] ?? ''),
                'ports' => $this->publishedPorts($c['Ports'] ?? []),
            ];
        }
        // Stable, name-sorted output.
        usort($out, static fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Delete                                                           */
    /* ---------------------------------------------------------------- */

    /** Remove a server's container AND all of its backups. */
    public function deleteServer(string $token): void
    {
        $id = $this->resolveToken($token);
        $this->docker->removeContainer($id, force: true);
        // Backups are keyed by token, independent of the container id.
        $this->backups->purge($token);
    }

    /* ---------------------------------------------------------------- */
    /* Provision                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Create and start a container from an uploaded compose file.
     *
     * @return array{token:string,name:string,image:string,ports:list<array<string,mixed>>}
     */
    public function createFromCompose(string $yaml): array
    {
        $svc   = $this->parser->parse($yaml);
        $token = $this->generateToken();

        $this->docker->ensureImage($svc['image']);

        // Ports that Docker already publishes; grown as we retry on conflicts.
        $blocked = $this->usedHostPorts();

        $name = $this->containerName($svc, $token);

        // Create + start with a bounded retry: the pre-scan only knows Docker's
        // own bindings, so a non-Docker listener on the host can still make the
        // start fail. On such a failure we block the just-tried host ports and
        // reallocate upward.
        $lastError = null;
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $assigned = $this->allocatePorts($svc['ports'], $blocked);
            $spec     = $this->buildSpec($svc, $token, $assigned);

            $id = $this->docker->createContainer($spec, $name);
            try {
                $this->docker->start($id);
                return [
                    'token' => $token,
                    'name'  => $svc['labels']['sgi.name'] ?? $svc['service'],
                    'image' => $svc['image'],
                    'ports' => array_map(
                        static fn($p) => ['host' => $p['host'], 'container' => $p['container'], 'proto' => $p['proto']],
                        $assigned
                    ),
                ];
            } catch (DockerException $e) {
                // Roll back the created-but-not-started container.
                $this->docker->removeContainer($id, force: true);
                $lastError = $e;
                // Only a host-port/bind conflict is worth retrying (with the
                // just-tried ports blocked). Any other failure won't improve by
                // bumping ports, so stop immediately.
                if ($svc['ports'] === [] || !$this->isPortConflict($e->getMessage())) {
                    break;
                }
                foreach ($assigned as $p) {
                    $blocked[$p['host']] = true;
                }
            }
        }

        throw new HttpException(
            409,
            'could not start container' . ($lastError ? ': ' . $lastError->getMessage() : '')
        );
    }

    /* ---------------------------------------------------------------- */
    /* Spec building                                                    */
    /* ---------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $svc
     * @param list<array{host:int,container:int,proto:string}> $ports
     * @return array<string,mixed>
     */
    private function buildSpec(array $svc, string $token, array $ports): array
    {
        $labels = $svc['labels'];
        $labels['sgi.token'] = $token;
        if (!isset($labels['sgi.name']) || $labels['sgi.name'] === '') {
            $labels['sgi.name'] = $svc['service'];
        }

        // Volumes → enforced per-token bind mounts. Any host/named source from
        // the compose file is discarded; the container target is preserved.
        $binds = [];
        $backupPath = null;
        foreach ($svc['volumes'] as $vol) {
            $host = $this->bindSource($token, $vol['target']);
            $binds[] = $host . ':' . $vol['target'] . ($vol['readonly'] ? ':ro' : '');
            $backupPath ??= $vol['target'];   // first volume drives backups
        }
        // Inject SGI's shared backup volume unless already present.
        if (!$this->hasBackupMount($binds)) {
            $binds[] = self::BACKUP_VOLUME . ':/backup';
        }
        // Make backups work without a named-volume fallback.
        if ($backupPath !== null && (!isset($labels['sgi.backup.path']) || $labels['sgi.backup.path'] === '')) {
            $labels['sgi.backup.path'] = $backupPath;
        }

        $hostConfig = ['Binds' => $binds];

        $exposed      = [];
        $portBindings = [];
        foreach ($ports as $p) {
            $key = $p['container'] . '/' . $p['proto'];
            $exposed[$key] = new \stdClass();
            $portBindings[$key] = [['HostPort' => (string) $p['host']]];
        }
        if ($portBindings !== []) {
            $hostConfig['PortBindings'] = $portBindings;
        }

        $restart = $this->restartPolicy($svc['restart']);
        if ($restart !== null) {
            $hostConfig['RestartPolicy'] = ['Name' => $restart];
        }

        $spec = [
            'Image'      => $svc['image'],
            // Console needs an open stdin and no TTY (docker attach). Default it
            // on for SGI-managed servers unless the compose explicitly opts out
            // (openStdin === false); null means "unspecified" → default true.
            'OpenStdin'  => $svc['openStdin'] ?? true,
            'Tty'        => $svc['tty'],
            'Labels'     => $labels,
            'HostConfig' => $hostConfig,
        ];
        if ($svc['env'] !== []) {
            $spec['Env'] = $svc['env'];
        }
        if ($svc['command'] !== null) {
            $spec['Cmd'] = $svc['command'];
        }
        if ($exposed !== []) {
            $spec['ExposedPorts'] = $exposed;
        }
        return $spec;
    }

    /** Heuristic: does a Docker start error look like a host-port conflict? */
    private function isPortConflict(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'port is already allocated')
            || str_contains($m, 'address already in use')
            || str_contains($m, 'bind for')
            || str_contains($m, 'failed to bind');
    }

    /** Per-token host path for a container target, e.g. /data → …/<token>/data. */
    private function bindSource(string $token, string $target): string
    {
        $sub = trim($target, '/');
        // Guard against traversal in a compose-supplied target.
        if ($sub === '' || str_contains('/' . $sub . '/', '/../')) {
            throw new HttpException(400, "unsafe volume target: $target");
        }
        return self::GAMESERVER_VOLUME_ROOT . '/' . $token . '/' . $sub;
    }

    /** @param list<string> $binds */
    private function hasBackupMount(array $binds): bool
    {
        foreach ($binds as $b) {
            $parts = explode(':', $b);
            if (($parts[1] ?? '') === '/backup') {
                return true;
            }
        }
        return false;
    }

    /** Map a compose restart value to a Docker RestartPolicy name (or null). */
    private function restartPolicy(?string $restart): ?string
    {
        return match ($restart) {
            'always'         => 'always',
            'unless-stopped' => 'unless-stopped',
            'on-failure'     => 'on-failure',
            'no', null, ''   => null,
            default          => null,
        };
    }

    /* ---------------------------------------------------------------- */
    /* Ports                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Assign a free host port to each requested mapping, starting at the desired
     * port and counting up past anything already taken (or assigned within this
     * same call). $blocked is a set keyed by port number.
     *
     * @param list<array{host:int,container:int,proto:string}> $ports
     * @param array<int,bool> $blocked
     * @return list<array{host:int,container:int,proto:string}>
     */
    private function allocatePorts(array $ports, array $blocked): array
    {
        $taken = $blocked;
        $out = [];
        foreach ($ports as $p) {
            $host = $p['host'];
            while ($host <= 65535 && isset($taken[$host])) {
                $host++;
            }
            if ($host > 65535) {
                throw new HttpException(409, 'no free host port available');
            }
            $taken[$host] = true;
            $out[] = ['host' => $host, 'container' => $p['container'], 'proto' => $p['proto']];
        }
        return $out;
    }

    /**
     * Host ports currently published by running containers.
     *
     * @return array<int,bool> set keyed by port number
     */
    private function usedHostPorts(): array
    {
        $used = [];
        foreach ($this->docker->listContainers([], all: false) as $c) {
            foreach ($c['Ports'] ?? [] as $port) {
                $public = (int) ($port['PublicPort'] ?? 0);
                if ($public > 0) {
                    $used[$public] = true;
                }
            }
        }
        return $used;
    }

    /**
     * Published-port view for the overview list.
     *
     * @param array<int,array<string,mixed>> $ports
     * @return list<array<string,mixed>>
     */
    private function publishedPorts(array $ports): array
    {
        $out = [];
        foreach ($ports as $port) {
            if (!isset($port['PublicPort'])) {
                continue;
            }
            $out[] = [
                'host'      => (int) $port['PublicPort'],
                'container' => (int) ($port['PrivatePort'] ?? 0),
                'proto'     => (string) ($port['Type'] ?? 'tcp'),
            ];
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                          */
    /* ---------------------------------------------------------------- */

    /** Resolve a token to exactly one container id (401/409 like TokenAuth). */
    private function resolveToken(string $token): string
    {
        if ($token === '') {
            throw new HttpException(400, 'empty token');
        }
        $containers = $this->docker->listContainers(['label' => ['sgi.token=' . $token]], all: true);
        if (count($containers) === 0) {
            throw new HttpException(404, 'no server for that token');
        }
        if (count($containers) > 1) {
            throw new HttpException(409, 'token maps to multiple containers');
        }
        return (string) $containers[0]['Id'];
    }

    /** A fresh token "XXXXXXX-XXXX-XXXX" not already used by any container. */
    private function generateToken(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $token = $this->randomGroup(7) . '-' . $this->randomGroup(4) . '-' . $this->randomGroup(4);
            if ($this->docker->listContainers(['label' => ['sgi.token=' . $token]], all: true) === []) {
                return $token;
            }
        }
        throw new HttpException(500, 'could not generate a unique token');
    }

    private function randomGroup(int $len): string
    {
        $alpha = self::TOKEN_ALPHABET;
        $max = strlen($alpha) - 1;
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $alpha[random_int(0, $max)];
        }
        return $s;
    }

    /**
     * A valid, likely-unique container name derived from the service name plus a
     * token fragment (Docker names must be unique and match a strict pattern).
     *
     * @param array<string,mixed> $svc
     */
    private function containerName(array $svc, string $token): string
    {
        $base = (string) ($svc['containerName'] ?? $svc['service']);
        $base = strtolower(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $base) ?? '');
        $base = trim($base, '-_.');
        if (strlen($base) < 2) {
            $base = 'sgi-server';
        }
        $suffix = strtolower(explode('-', $token)[0]);
        return $base . '-' . $suffix;
    }
}
