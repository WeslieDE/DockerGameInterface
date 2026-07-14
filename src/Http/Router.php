<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Http;

use tk\weslie\SGI\Auth\TokenAuth;
use tk\weslie\SGI\Docker\DockerException;
use tk\weslie\SGI\Service\BackupService;
use tk\weslie\SGI\Service\ConsoleService;
use tk\weslie\SGI\Service\PowerService;
use tk\weslie\SGI\Service\StatusService;

/**
 * Front controller. Resolves the token to a container on every request, then
 * dispatches to a service. The client never supplies a container id.
 */
final class Router
{
    public function __construct(
        private readonly TokenAuth $auth,
        private readonly StatusService $status,
        private readonly PowerService $power,
        private readonly ConsoleService $console,
        private readonly BackupService $backups,
    ) {}

    public function dispatch(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path   = $this->path();
            $this->route($method, $path);
        } catch (HttpException $e) {
            $this->json(['error' => $e->getMessage()], $e->status);
        } catch (DockerException $e) {
            $this->json(['error' => 'docker: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            $this->json(['error' => 'internal error'], 500);
        }
    }

    /* ---------------------------------------------------------------- */

    private function route(string $method, string $path): void
    {
        // Every route is authenticated: resolve token → container context.
        $ctx = $this->auth->resolve($this->auth->extractToken($_SERVER, $_GET));

        // ---- Status ----
        if ($method === 'GET' && $path === '/status') {
            $this->json($this->status->status($ctx));
            return;
        }

        // ---- Power ----
        if ($method === 'POST' && in_array($path, ['/start', '/stop', '/restart', '/kill'], true)) {
            $this->power->run($ctx, ltrim($path, '/'));
            $this->json(['ok' => true]);
            return;
        }

        // ---- Console ----
        if ($method === 'GET' && $path === '/console') {
            $after = (string) ($_GET['after'] ?? '0');
            $this->json($this->console->read($ctx, $after));
            return;
        }
        if ($method === 'POST' && $path === '/command') {
            $body    = $this->jsonBody();
            $command = trim((string) ($body['command'] ?? ''));
            if ($command === '') {
                throw new HttpException(400, 'empty command');
            }
            $this->console->command($ctx, $command);
            $this->json(['ok' => true]);
            return;
        }

        // ---- Backups ----
        if ($path === '/backups' && $method === 'GET') {
            $this->json($this->backups->list($ctx));
            return;
        }
        if ($path === '/backups' && $method === 'POST') {
            $this->json($this->backups->create($ctx), 201);
            return;
        }
        if (preg_match('#^/backups/([^/]+)/restore$#', $path, $m) && $method === 'POST') {
            $this->backups->restore($ctx, rawurldecode($m[1]));
            $this->json(['ok' => true]);
            return;
        }
        if (preg_match('#^/backups/([^/]+)/download$#', $path, $m) && $method === 'GET') {
            $this->download($this->backups->pathFor($ctx, rawurldecode($m[1])));
            return;
        }
        if (preg_match('#^/backups/([^/]+)$#', $path, $m) && $method === 'DELETE') {
            $this->backups->delete($ctx, rawurldecode($m[1]));
            $this->json(['ok' => true]);
            return;
        }

        throw new HttpException(404, 'no such endpoint');
    }

    /* ---------------------------------------------------------------- */

    /** Path relative to /api, e.g. "/status". */
    private function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Strip everything up to and including the first "/api" segment.
        $rel = preg_replace('#^.*?/api#', '', $uri, 1);
        $rel = rtrim($rel, '/');
        return $rel === '' ? '/' : $rel;
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid JSON body');
        }
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed>|list<mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function download(string $path): void
    {
        http_response_code(200);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }
}
