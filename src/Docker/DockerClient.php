<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Docker;

/**
 * Thin client for the Docker Engine API over the Unix socket.
 *
 * All calls go through cURL with CURLOPT_UNIX_SOCKET_PATH; no TCP, no
 * external dependencies. The client only exposes the primitives SGI needs.
 */
final class DockerClient
{
    /**
     * Desired API version — broadly supported by Docker 20.10+. We never send
     * a version higher than what the daemon reports (see apiVersion()), so an
     * older engine does not reject us with HTTP 400 "client version is too new".
     */
    private const DEFAULT_API_VERSION = '1.41';

    /** Negotiated version, resolved lazily on first use and cached. */
    private ?string $apiVersion = null;

    public function __construct(
        private readonly string $socket = '/var/run/docker.sock',
    ) {}

    /* ---------------------------------------------------------------- */
    /* Container queries                                                */
    /* ---------------------------------------------------------------- */

    /**
     * List containers. $filters is an associative array matching the Docker
     * filter schema, e.g. ['label' => ['sgi.token=abc']].
     *
     * @return array<int,array<string,mixed>>
     */
    public function listContainers(array $filters = [], bool $all = true): array
    {
        $query = ['all' => $all ? '1' : '0'];
        if ($filters !== []) {
            $query['filters'] = json_encode($filters, JSON_THROW_ON_ERROR);
        }
        [$status, , $body] = $this->request('GET', '/containers/json?' . http_build_query($query));
        if ($status !== 200) {
            throw new DockerException("listContainers failed (HTTP $status)" . self::errBody($body), $status);
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    public function inspect(string $id): array
    {
        [$status, , $body] = $this->request('GET', '/containers/' . rawurlencode($id) . '/json');
        if ($status !== 200) {
            throw new DockerException("inspect failed (HTTP $status)" . self::errBody($body), $status);
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * One-shot resource stats — a single snapshot returned immediately, with no
     * ~1s sampling delay. Docker does NOT populate `precpu_stats`/`system_cpu_usage`
     * in this mode, so the CPU percentage cannot be derived from a single response;
     * StatusService computes it from the delta between successive calls instead.
     *
     * @return array<string,mixed>
     */
    public function stats(string $id): array
    {
        [$status, , $body] = $this->request(
            'GET',
            '/containers/' . rawurlencode($id) . '/stats?stream=false&one-shot=true'
        );
        if ($status !== 200) {
            throw new DockerException("stats failed (HTTP $status)" . self::errBody($body), $status);
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Raw log bytes (may be multiplexed with 8-byte frame headers for
     * non-TTY containers — the caller de-frames).
     *
     * @param string|null $since Unix timestamp cursor (seconds, may carry a
     *                           fractional part). Null => use $tail instead.
     */
    public function logs(string $id, ?string $since = null, int $tail = 200): string
    {
        $query = ['stdout' => '1', 'stderr' => '1', 'timestamps' => '1'];
        if ($since !== null && $since !== '' && $since !== '0') {
            $query['since'] = $since;
        } else {
            $query['tail'] = (string) $tail;
        }
        [$status, , $body] = $this->request(
            'GET',
            '/containers/' . rawurlencode($id) . '/logs?' . http_build_query($query)
        );
        if ($status !== 200) {
            throw new DockerException("logs failed (HTTP $status)" . self::errBody($body), $status);
        }
        return $body;
    }

    /* ---------------------------------------------------------------- */
    /* Power actions                                                    */
    /* ---------------------------------------------------------------- */

    public function start(string $id): void   { $this->power($id, 'start'); }
    public function stop(string $id): void     { $this->power($id, 'stop'); }
    public function restart(string $id): void  { $this->power($id, 'restart'); }
    public function kill(string $id): void      { $this->power($id, 'kill'); }

    private function power(string $id, string $action): void
    {
        [$status] = $this->request('POST', '/containers/' . rawurlencode($id) . '/' . $action);
        // 204 = done, 304 = already in target state — both are success for us.
        if ($status !== 204 && $status !== 304) {
            throw new DockerException("$action failed (HTTP $status)", $status);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Helper containers (backups)                                      */
    /* ---------------------------------------------------------------- */

    /** Ensure an image is present locally; pull it otherwise (best effort). */
    public function ensureImage(string $image): void
    {
        [$status] = $this->request('GET', '/images/' . rawurlencode($image) . '/json');
        if ($status === 200) {
            return;
        }
        // Pull. The body is a stream of JSON progress lines we simply drain.
        [$pullStatus] = $this->request(
            'POST',
            '/images/create?' . http_build_query(['fromImage' => $image, 'tag' => 'latest'])
        );
        if ($pullStatus !== 200) {
            throw new DockerException("could not pull image '$image' (HTTP $pullStatus)", $pullStatus);
        }
    }

    /**
     * Create a container from a spec.
     *
     * @param array<string,mixed> $spec
     * @return string new container id
     */
    public function createContainer(array $spec): string
    {
        [$status, , $body] = $this->request('POST', '/containers/create', $spec);
        if ($status !== 201) {
            throw new DockerException("create failed (HTTP $status): $body", $status);
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return (string) $data['Id'];
    }

    /** Remove a container (best effort). */
    public function removeContainer(string $id, bool $force = true): void
    {
        $this->request(
            'DELETE',
            '/containers/' . rawurlencode($id) . '?' . http_build_query(['force' => $force ? '1' : '0'])
        );
    }

    /** Start a container and block until it exits; returns the exit code. */
    public function runToCompletion(string $id): int
    {
        [$startStatus] = $this->request('POST', '/containers/' . rawurlencode($id) . '/start');
        if ($startStatus !== 204 && $startStatus !== 304) {
            throw new DockerException("helper start failed (HTTP $startStatus)", $startStatus);
        }
        [$waitStatus, , $body] = $this->request('POST', '/containers/' . rawurlencode($id) . '/wait');
        if ($waitStatus !== 200) {
            throw new DockerException("helper wait failed (HTTP $waitStatus)", $waitStatus);
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return (int) ($data['StatusCode'] ?? -1);
    }

    /* ---------------------------------------------------------------- */
    /* Transport                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Resolve the API version to talk to the daemon. Newer engines reject a
     * version that is too old *or* too new with HTTP 400, so we adopt the
     * version the daemon itself reports (always supported by that daemon).
     * Falls back to DEFAULT_API_VERSION if the probe fails.
     */
    private function apiVersion(): string
    {
        if ($this->apiVersion !== null) {
            return $this->apiVersion;
        }
        $version = self::DEFAULT_API_VERSION;
        // Version-less request: always routes to the daemon's current API.
        [$status, , $body] = $this->send('GET', '/version', null);
        if ($status === 200) {
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['ApiVersion']) && is_string($data['ApiVersion']) && $data['ApiVersion'] !== '') {
                $version = $data['ApiVersion'];
            }
        }
        return $this->apiVersion = $version;
    }

    /**
     * Turn a non-2xx Docker response body into a readable suffix. Docker error
     * bodies are JSON like {"message":"..."}; fall back to the raw text.
     */
    private static function errBody(string $body): string
    {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return ': ' . $data['message'];
        }
        $body = trim($body);
        return $body === '' ? '' : ': ' . substr($body, 0, 300);
    }

    /**
     * @param array<string,mixed>|null $json body to send as JSON
     * @return array{0:int,1:string,2:string} [statusCode, headers, body]
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        return $this->send($method, '/v' . $this->apiVersion() . $path, $json);
    }

    /**
     * Raw transport. $absPath is used verbatim after the host, e.g.
     * "/v1.51/containers/json" or "/version" (un-versioned).
     *
     * @param array<string,mixed>|null $json body to send as JSON
     * @return array{0:int,1:string,2:string} [statusCode, headers, body]
     */
    private function send(string $method, string $absPath, ?array $json = null): array
    {
        $ch = curl_init();
        $url = 'http://localhost' . $absPath;

        $headers = ['Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_URL              => $url,
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_HEADER           => true,
            CURLOPT_CONNECTTIMEOUT   => 5,
            CURLOPT_TIMEOUT          => 120,
        ]);

        if ($json !== null) {
            $payload = json_encode($json, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DockerException("docker socket error: $err", 0);
        }
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
    }
}
