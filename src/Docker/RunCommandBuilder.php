<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Docker;

/**
 * Reconstructs a `docker run` command line from a `docker inspect` result.
 *
 * Best-effort, human-readable reproduction command written into a backup's
 * SGI.Info.txt so the container can be recreated from scratch. It reads only the
 * inspect data SGI already holds — no Docker calls of its own.
 *
 * Deliberate omissions: the image's own ENTRYPOINT is left implicit (Docker
 * re-applies it automatically, and re-specifying multi-arg entrypoints via
 * `--entrypoint` produces an invalid command line). Everything else that SGI
 * or a game image commonly needs — env, ports, volumes, labels, restart
 * policy, resource limits — is emitted explicitly.
 */
final class RunCommandBuilder
{
    /** @param array<string,mixed> $inspect full `docker inspect` result */
    public function build(array $inspect): string
    {
        $config = self::arr($inspect['Config'] ?? []);
        $host   = self::arr($inspect['HostConfig'] ?? []);

        // Each element is one already-shell-safe flag group; joined with a
        // trailing "\" so the command reads as one flag per line.
        $parts = ['docker run -d'];

        $name = ltrim((string) ($inspect['Name'] ?? ''), '/');
        if ($name !== '') {
            $parts[] = '--name ' . $this->q($name);
        }

        // Custom hostname only — Docker defaults it to the container id prefix.
        $hostname = (string) ($config['Hostname'] ?? '');
        $idPrefix = substr((string) ($inspect['Id'] ?? ''), 0, 12);
        if ($hostname !== '' && $hostname !== $idPrefix) {
            $parts[] = '--hostname ' . $this->q($hostname);
        }

        $restart = self::arr($host['RestartPolicy'] ?? []);
        $rpName  = (string) ($restart['Name'] ?? '');
        if ($rpName !== '' && $rpName !== 'no') {
            $max = (int) ($restart['MaximumRetryCount'] ?? 0);
            $parts[] = '--restart ' . $rpName . ($rpName === 'on-failure' && $max > 0 ? ':' . $max : '');
        }

        $network = (string) ($host['NetworkMode'] ?? '');
        if ($network !== '' && $network !== 'default') {
            $parts[] = '--network ' . $this->q($network);
        }

        if (!empty($host['Privileged'])) {
            $parts[] = '--privileged';
        }
        if (!empty($config['Tty'])) {
            $parts[] = '-t';
        }
        if (!empty($config['OpenStdin'])) {
            $parts[] = '-i';
        }

        $user = (string) ($config['User'] ?? '');
        if ($user !== '') {
            $parts[] = '--user ' . $this->q($user);
        }
        $workdir = (string) ($config['WorkingDir'] ?? '');
        if ($workdir !== '') {
            $parts[] = '-w ' . $this->q($workdir);
        }

        // Environment (every KEY=value the container carries, so it reproduces
        // exactly — including any image defaults).
        foreach (self::list($config['Env'] ?? []) as $env) {
            $parts[] = '-e ' . $this->q((string) $env);
        }

        // Published ports.
        foreach (self::arr($host['PortBindings'] ?? []) as $portProto => $bindings) {
            [$port, $proto] = array_pad(explode('/', (string) $portProto, 2), 2, 'tcp');
            $suffix = $proto === 'udp' ? '/udp' : '';
            foreach (self::list($bindings) as $bind) {
                $bind    = self::arr($bind);
                $hostIp  = (string) ($bind['HostIp'] ?? '');
                $hostPrt = (string) ($bind['HostPort'] ?? '');
                $spec = $hostPrt === ''
                    ? $port
                    : (($hostIp !== '' && $hostIp !== '0.0.0.0') ? $hostIp . ':' : '') . $hostPrt . ':' . $port;
                $parts[] = '-p ' . $this->q($spec . $suffix);
            }
        }

        // Volumes and binds (Mounts is authoritative for both named volumes and
        // host binds; named volumes reproduce under their own name).
        foreach (self::list($inspect['Mounts'] ?? []) as $mount) {
            $mount = self::arr($mount);
            $dest  = (string) ($mount['Destination'] ?? '');
            if ($dest === '') {
                continue;
            }
            $src = ($mount['Type'] ?? '') === 'volume'
                ? (string) ($mount['Name'] ?? '')
                : (string) ($mount['Source'] ?? '');
            $ro  = ($mount['RW'] ?? true) ? '' : ':ro';
            $parts[] = '-v ' . $this->q(($src !== '' ? $src . ':' : '') . $dest . $ro);
        }

        // Capabilities, devices, extra hosts, DNS.
        foreach (self::list($host['CapAdd'] ?? []) as $cap) {
            $parts[] = '--cap-add ' . $this->q((string) $cap);
        }
        foreach (self::list($host['CapDrop'] ?? []) as $cap) {
            $parts[] = '--cap-drop ' . $this->q((string) $cap);
        }
        foreach (self::list($host['Devices'] ?? []) as $dev) {
            $dev  = self::arr($dev);
            $spec = (string) ($dev['PathOnHost'] ?? '') . ':' . (string) ($dev['PathInContainer'] ?? '');
            $perm = (string) ($dev['CgroupPermissions'] ?? '');
            if ($perm !== '' && $perm !== 'rwm') {
                $spec .= ':' . $perm;
            }
            $parts[] = '--device ' . $this->q($spec);
        }
        foreach (self::list($host['ExtraHosts'] ?? []) as $eh) {
            $parts[] = '--add-host ' . $this->q((string) $eh);
        }
        foreach (self::list($host['Dns'] ?? []) as $dns) {
            $parts[] = '--dns ' . $this->q((string) $dns);
        }

        // Resource limits.
        $mem = (int) ($host['Memory'] ?? 0);
        if ($mem > 0) {
            $parts[] = '--memory ' . $mem;
        }
        $nanoCpus = (int) ($host['NanoCpus'] ?? 0);
        if ($nanoCpus > 0) {
            // Trim trailing zeros, e.g. 1500000000 -> "1.5".
            $parts[] = '--cpus ' . rtrim(rtrim(number_format($nanoCpus / 1_000_000_000, 3, '.', ''), '0'), '.');
        }

        // Labels (includes SGI's own sgi.* labels, required to recreate a
        // container SGI can manage again). Compose's own bookkeeping labels are
        // skipped — they describe a compose project, not this container, and
        // re-applying them to a hand-run container would be misleading.
        foreach (self::arr($config['Labels'] ?? []) as $key => $value) {
            if (str_starts_with((string) $key, 'com.docker.compose.')) {
                continue;
            }
            $pair = (string) $value === '' ? (string) $key : $key . '=' . $value;
            $parts[] = '--label ' . $this->q($pair);
        }

        // Image, then the command override (if any).
        $parts[] = $this->q((string) ($config['Image'] ?? ''));
        foreach (self::list($config['Cmd'] ?? []) as $arg) {
            $parts[] = $this->q((string) $arg);
        }

        return implode(" \\\n  ", $parts);
    }

    /* ---------------------------------------------------------------- */

    /**
     * Shell-quote a value for the reproduction command. Bare tokens that are
     * already safe are left unquoted for readability; anything else is wrapped
     * in single quotes with embedded quotes escaped.
     */
    private function q(string $value): string
    {
        if ($value !== '' && preg_match('#^[A-Za-z0-9._:/=@%+,-]+$#', $value)) {
            return $value;
        }
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /** @return array<mixed> */
    private static function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    /** @return list<mixed> */
    private static function list(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }
}
