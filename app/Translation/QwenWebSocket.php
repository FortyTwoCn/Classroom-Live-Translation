<?php

declare(strict_types=1);

namespace App\Translation;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Ws;

/** Keep Workerman's framing, but never echo rejected HTTP handshake headers to logs. */
final class QwenWebSocket extends Ws
{
    public static function input(string $buffer, AsyncTcpConnection $connection): int
    {
        if (!isset($connection->context->handshakeStep)) {
            $connection->destroy();
            return -1;
        }
        if ($connection->context->handshakeStep !== 1) {
            return parent::input($buffer, $connection);
        }
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) >= 16384) self::reject($connection, 400);
            return 0;
        }
        $header = substr($buffer, 0, $end + 4);
        if ($end >= 16384 || !preg_match('/^HTTP\/1\.[01] ([0-9]{3}) [^\r\n]*\r\n/', $header, $status)) {
            self::reject($connection, 400);
            return 0;
        }
        if ($status[1] !== '101') {
            self::reject($connection, (int) $status[1]);
            return 0;
        }
        // Workerman safeEcho writes directly to its output stream, bypassing PHP output
        // buffering. Validate rejected handshakes before entering its raw-header diagnostics.
        $expected = base64_encode(sha1(($connection->context->websocketSecKey ?? '').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/(?:^|\r\n)Sec-WebSocket-Accept: *(.*?)\r\n/i', $header, $accept) || !hash_equals($expected, $accept[1])) {
            self::reject($connection, 400);
            return 0;
        }
        foreach (array_slice(explode("\r\n", substr($header, 0, -4)), 1) as $line) {
            if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+:[^\r\n]*$/D', $line)) {
                self::reject($connection, 400);
                return 0;
            }
        }
        return parent::input($buffer, $connection);
    }

    private static function reject(AsyncTcpConnection $connection, int $status): void
    {
        if ($connection->onError !== null) {
            ($connection->onError)($connection, $status, 'upstream_http_handshake_rejected');
        }
        $connection->destroy();
    }
}
