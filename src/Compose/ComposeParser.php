<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Compose;

use tk\weslie\SGI\Http\HttpException;

/**
 * Parses an uploaded docker-compose YAML into a normalised, provider-neutral
 * service description. Pure parsing only — no Docker calls, no port allocation,
 * no token/label injection (that happens in AdminService).
 *
 * Hard rule: the compose file must define EXACTLY ONE service (SGI is
 * one-token-one-container). A YAML with zero or multiple services is rejected.
 *
 * Supported per-service keys: image (required), ports, environment, volumes,
 * command, labels, restart, stdin_open, tty, container_name. Everything else in
 * the compose file is ignored on purpose.
 *
 * Uses the native ext-yaml (yaml_parse); a userland YAML library would violate
 * SGI's "no external PHP dependencies" rule.
 */
final class ComposeParser
{
    /**
     * @return array{
     *   service:string, image:string, containerName:?string,
     *   ports:list<array{host:int,container:int,proto:string}>,
     *   env:list<string>, volumes:list<array{target:string,readonly:bool}>,
     *   command:?array<int,string>, labels:array<string,string>,
     *   restart:?string, openStdin:?bool, tty:bool
     * }
     */
    public function parse(string $yaml): array
    {
        if (!function_exists('yaml_parse')) {
            throw new HttpException(500, 'server is missing the yaml extension');
        }
        if (trim($yaml) === '') {
            throw new HttpException(400, 'empty compose file');
        }

        // yaml_parse emits a warning + returns false on malformed input; silence
        // the warning and turn the false into a clean 400.
        $data = @yaml_parse($yaml);
        if (!is_array($data)) {
            throw new HttpException(400, 'invalid YAML');
        }

        $services = $data['services'] ?? null;
        if (!is_array($services) || $services === []) {
            throw new HttpException(400, 'compose defines no services');
        }
        if (count($services) !== 1) {
            throw new HttpException(400, 'compose must define exactly one service');
        }

        $name = (string) array_key_first($services);
        $svc  = $services[$name];
        if (!is_array($svc)) {
            throw new HttpException(400, "service '$name' is malformed");
        }

        $image = isset($svc['image']) ? trim((string) $svc['image']) : '';
        if ($image === '') {
            // We cannot build images here — an explicit image is required.
            throw new HttpException(400, "service '$name' must specify an image");
        }

        return [
            'service'       => $name,
            'image'         => $image,
            'containerName' => isset($svc['container_name']) ? (string) $svc['container_name'] : null,
            'ports'         => $this->parsePorts($svc['ports'] ?? []),
            'env'           => $this->parseEnv($svc['environment'] ?? []),
            'volumes'       => $this->parseVolumes($svc['volumes'] ?? []),
            'command'       => $this->parseCommand($svc['command'] ?? null),
            'labels'        => $this->parseLabels($svc['labels'] ?? []),
            'restart'       => isset($svc['restart']) ? (string) $svc['restart'] : null,
            // null = not specified (AdminService defaults stdin open for the console).
            'openStdin'     => array_key_exists('stdin_open', $svc) ? (bool) $svc['stdin_open'] : null,
            'tty'           => (bool) ($svc['tty'] ?? false),
        ];
    }

    /* ---------------------------------------------------------------- */

    /**
     * Accepts short syntax ("HOST:CONTAINER", "CONTAINER", "IP:HOST:CONTAINER",
     * "CONT/proto", "HOST:CONT/proto") and long syntax
     * ({target, published, protocol}). A missing host port defaults to the
     * container port. Port ranges ("8000-8010") are rejected (single container).
     *
     * @param mixed $ports
     * @return list<array{host:int,container:int,proto:string}>
     */
    private function parsePorts(mixed $ports): array
    {
        if ($ports === [] || $ports === null) {
            return [];
        }
        if (!is_array($ports)) {
            throw new HttpException(400, 'ports must be a list');
        }

        $out = [];
        foreach ($ports as $entry) {
            if (is_array($entry)) {
                // Long syntax.
                $container = (int) ($entry['target'] ?? 0);
                $host      = (int) ($entry['published'] ?? $entry['target'] ?? 0);
                $proto     = strtolower((string) ($entry['protocol'] ?? 'tcp'));
            } else {
                [$host, $container, $proto] = $this->parseShortPort((string) $entry);
            }
            if ($container < 1 || $container > 65535 || $host < 1 || $host > 65535) {
                throw new HttpException(400, "invalid port mapping: " . json_encode($entry));
            }
            $proto = ($proto === 'udp') ? 'udp' : 'tcp';
            $out[] = ['host' => $host, 'container' => $container, 'proto' => $proto];
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:string} [host, container, proto] */
    private function parseShortPort(string $spec): array
    {
        $spec = trim($spec);
        $proto = 'tcp';
        if (str_contains($spec, '/')) {
            [$spec, $proto] = explode('/', $spec, 2);
        }
        if (str_contains($spec, '-')) {
            throw new HttpException(400, "port ranges are not supported: $spec");
        }

        $parts = explode(':', $spec);
        // "container" | "host:container" | "ip:host:container"
        $count = count($parts);
        if ($count === 1) {
            $container = (int) $parts[0];
            $host      = $container;
        } elseif ($count === 2) {
            $host      = (int) $parts[0];
            $container = (int) $parts[1];
        } elseif ($count === 3) {
            // Ignore the bind IP; SGI publishes on all interfaces.
            $host      = (int) $parts[1];
            $container = (int) $parts[2];
        } else {
            throw new HttpException(400, "invalid port mapping: $spec");
        }
        return [$host, $container, $proto];
    }

    /**
     * Environment as either a map ({KEY: value}) or a list ("KEY=value").
     * Returns the Docker `Env` list form ("KEY=value").
     *
     * @param mixed $env
     * @return list<string>
     */
    private function parseEnv(mixed $env): array
    {
        if ($env === [] || $env === null) {
            return [];
        }
        $out = [];
        if ($this->isList($env)) {
            foreach ($env as $line) {
                $line = (string) $line;
                if ($line !== '') {
                    $out[] = $line;
                }
            }
        } elseif (is_array($env)) {
            foreach ($env as $key => $value) {
                $out[] = $key . '=' . $this->scalar($value);
            }
        }
        return $out;
    }

    /**
     * Volumes in short syntax ("src:dst[:ro]") or long syntax
     * ({target, read_only}). Only the container target and the read-only flag
     * are kept — the host/named source is discarded because AdminService forces
     * every mount onto a per-token bind path.
     *
     * @param mixed $volumes
     * @return list<array{target:string,readonly:bool}>
     */
    private function parseVolumes(mixed $volumes): array
    {
        if ($volumes === [] || $volumes === null) {
            return [];
        }
        if (!is_array($volumes)) {
            throw new HttpException(400, 'volumes must be a list');
        }

        $out = [];
        foreach ($volumes as $entry) {
            if (is_array($entry)) {
                $target   = trim((string) ($entry['target'] ?? ''));
                $readonly = (bool) ($entry['read_only'] ?? false);
            } else {
                $parts    = explode(':', (string) $entry);
                // src:dst[:mode] — the container target is the 2nd field, or the
                // 1st for an anonymous "/dst" volume.
                $target   = count($parts) >= 2 ? trim($parts[1]) : trim($parts[0]);
                $readonly = isset($parts[2]) && str_contains($parts[2], 'ro');
            }
            if ($target === '' || $target[0] !== '/') {
                throw new HttpException(400, "volume target must be an absolute path: " . json_encode($entry));
            }
            $out[] = ['target' => $target, 'readonly' => $readonly];
        }
        return $out;
    }

    /**
     * Command as a string (shell form) or a list (exec form).
     *
     * @param mixed $command
     * @return array<int,string>|null
     */
    private function parseCommand(mixed $command): ?array
    {
        if ($command === null || $command === '') {
            return null;
        }
        if (is_array($command)) {
            return array_values(array_map(fn($c) => (string) $c, $command));
        }
        // Shell form — let /bin/sh split it, matching compose semantics.
        return ['/bin/sh', '-c', (string) $command];
    }

    /**
     * Labels as a map ({key: value}) or a list ("key=value").
     *
     * @param mixed $labels
     * @return array<string,string>
     */
    private function parseLabels(mixed $labels): array
    {
        if ($labels === [] || $labels === null) {
            return [];
        }
        $out = [];
        if ($this->isList($labels)) {
            foreach ($labels as $line) {
                $line = (string) $line;
                $eq = strpos($line, '=');
                if ($eq === false) {
                    $out[$line] = '';
                } else {
                    $out[substr($line, 0, $eq)] = substr($line, $eq + 1);
                }
            }
        } elseif (is_array($labels)) {
            foreach ($labels as $key => $value) {
                $out[(string) $key] = $this->scalar($value);
            }
        }
        return $out;
    }

    /** A zero-indexed sequential array (YAML sequence) vs a map. */
    private function isList(mixed $v): bool
    {
        return is_array($v) && array_is_list($v);
    }

    /** Render a YAML scalar as a string (bools as true/false, not 1/''). */
    private function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }
}
