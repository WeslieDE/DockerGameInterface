<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Docker;

/**
 * Raw Unix-socket transport for the one operation cURL cannot do cleanly:
 * writing to a container's stdin via `POST /containers/{id}/attach`.
 *
 * Docker hijacks the HTTP connection (101 Switching Protocols) and turns it
 * into a bidirectional byte stream. We only need the stdin direction: connect,
 * send the attach request, then write "<command>\n" straight to the socket.
 */
final class SocketStream
{
    public function __construct(
        private readonly string $socket = '/var/run/docker.sock',
    ) {}

    /**
     * Send a single line of input to the container's stdin. The container must
     * have been started with stdin open (`-i` / stdin_open: true).
     *
     * @throws DockerException on connection or protocol failure
     */
    public function sendStdin(string $id, string $line): void
    {
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('unix://' . $this->socket, $errno, $errstr, 5);
        if ($fp === false) {
            throw new DockerException("attach: socket connect failed: $errstr ($errno)", 0);
        }
        stream_set_timeout($fp, 5);

        // Version-less path: the daemon routes it to its current API version,
        // avoiding "client version too old/new" (HTTP 400) on newer engines.
        $path = '/containers/' . rawurlencode($id)
            . '/attach?' . http_build_query([
                'stream' => '1',
                'stdin'  => '1',
                'stdout' => '0',
                'stderr' => '0',
            ]);

        $req = "POST $path HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/vnd.docker.raw-stream\r\n"
            . "Connection: Upgrade\r\n"
            . "Upgrade: tcp\r\n"
            . "\r\n";

        if (@fwrite($fp, $req) === false) {
            fclose($fp);
            throw new DockerException('attach: failed to write request', 0);
        }

        // Read the response status line; expect 101 (Switching Protocols) or
        // 200 (older daemons that answer with a hijacked 200 stream).
        $statusLine = fgets($fp);
        if ($statusLine === false || !preg_match('#HTTP/1\.[01]\s+(\d{3})#', $statusLine, $m)) {
            fclose($fp);
            throw new DockerException('attach: no HTTP status from daemon', 0);
        }
        $code = (int) $m[1];
        if ($code !== 101 && $code !== 200) {
            fclose($fp);
            throw new DockerException("attach rejected (HTTP $code)", $code);
        }

        // Drain the remaining response headers up to the blank line.
        while (($h = fgets($fp)) !== false) {
            if ($h === "\r\n" || $h === "\n") {
                break;
            }
        }

        // The connection is now the container's stdin. Write the command.
        if (@fwrite($fp, rtrim($line, "\r\n") . "\n") === false) {
            fclose($fp);
            throw new DockerException('attach: failed to write stdin', 0);
        }
        fflush($fp);
        fclose($fp);
    }
}
