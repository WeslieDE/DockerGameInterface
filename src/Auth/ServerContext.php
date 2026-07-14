<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Auth;

/**
 * Immutable, per-request view of the container a token resolved to.
 * Everything downstream (services) works from this — never from client input.
 */
final class ServerContext
{
    /**
     * @param string               $token   the authenticated server token
     * @param string               $id      resolved container id (never leaves the backend)
     * @param array<string,mixed>  $inspect full `docker inspect` result (cached for the request)
     */
    public function __construct(
        public readonly string $token,
        public readonly string $id,
        public readonly array $inspect,
    ) {}

    /** @return array<string,string> */
    public function labels(): array
    {
        return $this->inspect['Config']['Labels'] ?? [];
    }

    public function label(string $key, ?string $default = null): ?string
    {
        return $this->labels()[$key] ?? $default;
    }

    /** Display name: sgi.name label, else the container name without leading slash. */
    public function displayName(): string
    {
        $name = $this->label('sgi.name');
        if ($name !== null && $name !== '') {
            return $name;
        }
        return ltrim((string) ($this->inspect['Name'] ?? 'server'), '/');
    }

    /**
     * Path inside the game container that gets backed up: the sgi.backup.path
     * label, falling back to the destination of the first named-volume mount
     * that is not our own /backup mount.
     */
    public function backupPath(): ?string
    {
        $label = $this->label('sgi.backup.path');
        if ($label !== null && $label !== '') {
            return $label;
        }
        foreach ($this->inspect['Mounts'] ?? [] as $mount) {
            if (($mount['Type'] ?? '') === 'volume' && ($mount['Destination'] ?? '') !== '/backup') {
                return $mount['Destination'];
            }
        }
        return null;
    }
}
