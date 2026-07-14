<?php
declare(strict_types=1);

namespace tk\weslie\SGI\Service;

use tk\weslie\SGI\Auth\ServerContext;
use tk\weslie\SGI\Docker\DockerClient;
use tk\weslie\SGI\Docker\SocketStream;

/**
 * Console output (docker logs) and input (docker attach → stdin).
 *
 * Statelessness: the cursor `after` is the timestamp of the last line the
 * client already has, formatted as `<seconds>.<nanoseconds>`. We ask Docker
 * for `since=<after>` and return `last` = the newest timestamp, which the
 * frontend echoes back on the next poll. No server-side state is kept.
 *
 * The cursor is carried at full nanosecond precision (as an integer count of
 * nanoseconds internally). Docker log timestamps are nanosecond-precise and
 * its `since` filter is *inclusive*, so it always re-sends the boundary line.
 * We drop that line again with an exact integer comparison — anything lossy
 * (e.g. a float rounded to microseconds) makes the guard miss and the last
 * line gets re-appended on every poll.
 */
final class ConsoleService
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly SocketStream $stream,
    ) {}

    /**
     * @return array{lines:array<int,array{seq:float,ts:string,text:string}>,last:string}
     */
    public function read(ServerContext $ctx, string $after): array
    {
        $first = ($after === '' || $after === '0');
        $raw   = $this->docker->logs($ctx->id, $first ? null : $after, tail: 200);

        $tty  = (bool) ($ctx->inspect['Config']['Tty'] ?? false);
        $text = $tty ? $raw : $this->deframe($raw);

        // Normalise CRLF so line splitting is clean; lone carriage returns are
        // handled per-line below (see the body). Without this a body starting
        // with a stray `\r` renders as an extra break in the `pre-wrap` console.
        $text = str_replace("\r\n", "\n", $text);

        $afterNs = $first ? 0 : $this->cursorToNanos($after);
        $lines   = [];
        $lastNs  = $afterNs;

        foreach (explode("\n", $text) as $rawLine) {
            $rawLine = rtrim($rawLine, "\r");
            if ($rawLine === '') {
                continue;
            }
            [$ns, $ts, $body] = $this->splitTimestamp($rawLine);

            // Strip any remaining carriage returns (cursor-reset control chars,
            // common in game-server output). This web console is line-based, so
            // a `\r` inside a line would otherwise show as a spurious newline.
            $body = str_replace("\r", '', $body);

            // Strip ANSI escape sequences (colour codes etc.). The ESC byte is
            // invisible in the browser, so e.g. "\x1b[m" would leak through as a
            // stray "[m". Remove them so the console shows plain text.
            $body = $this->stripAnsi($body);

            // Docker's `since` is inclusive, so the boundary line comes back on
            // every poll — drop anything we've already delivered. Exact integer
            // nanoseconds here; a lossy compare would re-append the last line.
            if (!$first && $ns > 0 && $ns <= $afterNs) {
                continue;
            }
            $lines[] = ['seq' => $this->fmtCursor($ns), 'ts' => $ts, 'text' => $body];
            if ($ns > $lastNs) {
                $lastNs = $ns;
            }
        }

        return ['lines' => $lines, 'last' => $this->fmtCursor($lastNs)];
    }

    public function command(ServerContext $ctx, string $command): void
    {
        $this->stream->sendStdin($ctx->id, $command);
    }

    /* ---------------------------------------------------------------- */

    /**
     * Strip Docker's 8-byte stdout/stderr frame headers (non-TTY containers).
     * Falls back to returning the input untouched if it isn't framed.
     */
    private function deframe(string $raw): string
    {
        $out = '';
        $len = strlen($raw);
        $i   = 0;
        while ($i + 8 <= $len) {
            $type = ord($raw[$i]);
            // A valid header has stream type 0/1/2 and zero padding bytes.
            if ($type > 2 || $raw[$i + 1] !== "\0" || $raw[$i + 2] !== "\0" || $raw[$i + 3] !== "\0") {
                return $raw; // not framed — treat as raw stream
            }
            $size = unpack('N', substr($raw, $i + 4, 4))[1];
            $out .= substr($raw, $i + 8, $size);
            $i += 8 + $size;
        }
        return $out !== '' ? $out : $raw;
    }

    /**
     * Remove ANSI/VT100 escape sequences from a line of text.
     *
     * Covers the common families: CSI (colour/SGR like ESC[0m, cursor moves),
     * OSC (window-title, terminated by BEL or ESC\), and any stray lone ESC.
     */
    private function stripAnsi(string $s): string
    {
        // CSI: ESC [ <param bytes 0x30-0x3F> <intermediate 0x20-0x2F> <final 0x40-0x7E>
        $s = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $s) ?? $s;
        // OSC: ESC ] ... (BEL or ST)
        $s = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $s) ?? $s;
        // Any remaining escape byte (incl. two-char sequences like ESC( ).
        return str_replace("\x1b", '', $s);
    }

    /**
     * Split "2024-01-01T12:00:00.123456789Z rest of line" into
     * [epochNanos, "HH:MM:SS", "rest of line"].
     *
     * @return array{0:int,1:string,2:string}
     */
    private function splitTimestamp(string $line): array
    {
        $sp = strpos($line, ' ');
        if ($sp === false) {
            return [0, '', $line];
        }
        $stamp = substr($line, 0, $sp);
        $body  = substr($line, $sp + 1);

        $ns = $this->parseRfc3339Ns($stamp);
        if ($ns === null) {
            return [0, '', $line]; // no timestamp prefix after all
        }
        return [$ns, date('H:i:s', intdiv($ns, 1_000_000_000)), $body];
    }

    /**
     * RFC3339 (nanosecond) → integer nanoseconds since the epoch, or null if
     * unparseable. Keeps full precision (needs 64-bit PHP, as on the container).
     */
    private function parseRfc3339Ns(string $s): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $s)) {
            return null;
        }
        $base = strtotime($s);
        if ($base === false) {
            return null;
        }
        $frac = 0;
        if (preg_match('/\.(\d+)/', $s, $m)) {
            // Pad/truncate the fraction to exactly 9 digits (nanoseconds).
            $frac = (int) substr($m[1] . '000000000', 0, 9);
        }
        return $base * 1_000_000_000 + $frac;
    }

    /** Cursor string "<sec>.<nanos>" (from fmtCursor) → integer nanoseconds. */
    private function cursorToNanos(string $cursor): int
    {
        if ($cursor === '' || $cursor === '0') {
            return 0;
        }
        $dot = strpos($cursor, '.');
        if ($dot === false) {
            return (int) $cursor * 1_000_000_000;
        }
        $sec  = (int) substr($cursor, 0, $dot);
        $frac = (int) substr(substr($cursor, $dot + 1) . '000000000', 0, 9);
        return $sec * 1_000_000_000 + $frac;
    }

    /**
     * Integer nanoseconds → "<sec>.<9-digit-nanos>". This doubles as Docker's
     * `since` value (fractional seconds) and as the client's opaque cursor.
     */
    private function fmtCursor(int $ns): string
    {
        if ($ns <= 0) {
            return '0';
        }
        return intdiv($ns, 1_000_000_000)
            . '.' . str_pad((string) ($ns % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }
}
