<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Auth;

use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Http\HttpException;

/**
 * Token → container resolution.
 *
 * The token arrives as a Bearer header (or, for browser-initiated downloads
 * that cannot set headers, a `token` query parameter). It maps to exactly one
 * container via the `sgi.token` label. The client never sends a container id;
 * the id is resolved server-side on every request.
 */
final class TokenAuth
{
    public function __construct(
        private readonly DockerClient $docker,
    ) {}

    /**
     * Read the token from the current request.
     * @param array<string,string> $query
     */
    public function extractToken(array $server, array $query): string
    {
        $header = $server['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            // Some Apache setups drop Authorization from $_SERVER.
            $all = apache_request_headers();
            $header = $all['Authorization'] ?? ($all['authorization'] ?? '');
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        // Fallback for file downloads opened in a new tab (no custom headers).
        if (isset($query['token']) && $query['token'] !== '') {
            return (string) $query['token'];
        }
        throw new HttpException(401, 'missing token');
    }

    /**
     * Resolve a token to its container. Enforces exactly-one-match.
     *
     * @throws HttpException 401 (no match) / 409 (ambiguous)
     */
    public function resolve(string $token): ServerContext
    {
        if ($token === '') {
            throw new HttpException(401, 'empty token');
        }
        $containers = $this->docker->listContainers(
            ['label' => ['sgi.token=' . $token]],
            all: true
        );

        if (count($containers) === 0) {
            throw new HttpException(401, 'invalid server token');
        }
        if (count($containers) > 1) {
            throw new HttpException(409, 'token maps to multiple containers');
        }

        $id = (string) $containers[0]['Id'];
        $inspect = $this->docker->inspect($id);

        return new ServerContext($token, $id, $inspect);
    }
}
