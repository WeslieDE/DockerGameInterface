<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Service;

use tk\weslie\SGI\Auth\ServerContext;
use tk\weslie\SGI\Docker\DockerClient;

/**
 * Builds the /api/status payload from Docker inspect + stats.
 *
 * Docker exposes infrastructure metrics only; game metrics (ping, players)
 * are not available and are returned as null so the frontend shows "—".
 */
final class StatusService
{
    public function __construct(
        private readonly DockerClient $docker,
    ) {}

    /** @return array<string,mixed> */
    public function status(ServerContext $ctx): array
    {
        $inspect = $ctx->inspect;
        $state   = $inspect['State'] ?? [];
        $online  = (bool) ($state['Running'] ?? false);

        $resources = [
            'ping' => null,                       // game-specific, not from Docker
            'ram'  => ['used' => 0, 'max' => 0],
            'cpu'  => null,                        // % is derived client-side from cpuSample
            'cpuSample' => null,                   // raw CPU counter, see cpuSample()
            'disk' => null,                        // optional, not computed in v1
        ];

        if ($online) {
            try {
                $stats = $this->docker->stats($ctx->id);
                $resources['ram']       = $this->memory($stats);
                $resources['cpuSample'] = $this->cpuSample($stats);
            } catch (\Throwable) {
                // Stats can briefly fail during transitions — keep zeros.
            }
        }

        return [
            'online'    => $online,
            'name'      => $ctx->displayName(),
            'address'   => $this->address($inspect),
            'version'   => $inspect['Config']['Image'] ?? null,
            'players'   => null,                    // game-specific
            'uptime'    => $this->uptime($state, $online),
            'resources' => $resources,
        ];
    }

    /* ---------------------------------------------------------------- */

    /** @param array<string,mixed> $stats @return array{used:int,max:int} (MB) */
    private function memory(array $stats): array
    {
        $mem   = $stats['memory_stats'] ?? [];
        $usage = (int) ($mem['usage'] ?? 0);
        // Subtract page cache so the number matches `docker stats`.
        $cache = (int) ($mem['stats']['inactive_file'] ?? $mem['stats']['cache'] ?? 0);
        $used  = max(0, $usage - $cache);
        $limit = (int) ($mem['limit'] ?? 0);

        return [
            'used' => (int) round($used / 1048576),
            'max'  => (int) round($limit / 1048576),
        ];
    }

    /**
     * Raw CPU counter for client-side percentage calculation.
     *
     * One-shot Docker stats carry no usable `precpu_stats`/`system_cpu_usage`,
     * so a single response cannot yield a percentage. Instead we expose the
     * cumulative CPU time (`total_usage`, in nanoseconds across all cores) and
     * the sample timestamp; the frontend remembers the previous sample and
     * derives the percentage from the growth over the elapsed time. This keeps
     * the request stateless and latency-free.
     *
     * @param array<string,mixed> $stats
     * @return array{total:float,time:float}|null total = CPU-ns, time = unix seconds
     */
    private function cpuSample(array $stats): ?array
    {
        $total = (float) ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return ['total' => $total, 'time' => $this->sampleTime($stats['read'] ?? null)];
    }

    /**
     * Sample timestamp as a float unix time (µs precision). Prefers Docker's own
     * `read` field so the interval is free of client/network jitter; PHP caps at
     * microseconds, so nanosecond digits are truncated. Falls back to local time.
     */
    private function sampleTime(mixed $read): float
    {
        if (is_string($read) && $read !== '' && $read[0] !== '0') {
            $norm = preg_replace('/\.(\d{6})\d+/', '.$1', $read); // 9 → 6 fractional digits
            $dt   = date_create_immutable(is_string($norm) ? $norm : $read);
            if ($dt !== false) {
                return (float) $dt->format('U.u');
            }
        }
        return microtime(true);
    }

    /** @param array<string,mixed> $state Seconds since StartedAt. */
    private function uptime(array $state, bool $online): int
    {
        if (!$online) {
            return 0;
        }
        $started = strtotime((string) ($state['StartedAt'] ?? ''));
        if ($started === false || $started <= 0) {
            return 0;
        }
        return max(0, time() - $started);
    }

    /** First published port as "host:port", else null. @param array<string,mixed> $inspect */
    private function address(array $inspect): ?string
    {
        $ports = $inspect['NetworkSettings']['Ports'] ?? [];
        foreach ($ports as $bindings) {
            if (is_array($bindings) && isset($bindings[0]['HostPort'])) {
                $host = $bindings[0]['HostIp'] ?? '';
                $host = ($host === '' || $host === '0.0.0.0') ? '' : $host . ':';
                return $host . $bindings[0]['HostPort'];
            }
        }
        return null;
    }
}
