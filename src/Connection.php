<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

use Fiber;

/**
 * Stream-socket-backed WebSocket connection.
 *
 * Lifecycle managed by `Server`:
 *   1. `Server` accepts a TCP socket, sets it to non-blocking, creates a `Connection`.
 *   2. A `Fiber` is started for this connection; the first thing it does is call `handshake()`.
 *   3. After the handshake succeeds, the `Fiber` enters the frame-reading loop.
 *   4. The `Server` event loop resumes the `Fiber` whenever the socket is readable.
 *
 * Blocking mode is intentionally NOT set in this constructor — the `Server` controls
 * it so that tests can use blocking socket pairs without interference.
 *
 * @package EzPhp\WebSocket
 */
final class Connection implements ConnectionInterface
{
    /**
     * Maximum number of bytes accepted while accumulating the HTTP upgrade
     * request during {@see handshake()}. Guards against a client that never
     * sends a terminating `\r\n\r\n`, which would otherwise grow the buffer
     * without bound (memory-exhaustion / Slowloris-style DoS).
     */
    private const MAX_HANDSHAKE_BYTES = 8192;

    /** @var resource */
    private $socket;

    private string $buffer = '';

    private bool $connected = false;

    /** @var array<string, string> Upgrade-request headers, keyed by lowercased name. */
    private array $requestHeaders = [];

    /**
     * @param resource $socket Stream socket (blocking mode left to caller)
     * @param string   $id     Unique identifier for this connection
     */
    public function __construct(
        $socket,
        private readonly string $id,
    ) {
        $this->socket = $socket;
    }

    /**
     * Returns the unique connection identifier.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Returns `true` while the WebSocket connection is open.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Returns the underlying stream socket resource.
     *
     * Used by `Server` to register the socket with `stream_select()`.
     *
     * The native return type is `mixed` because PHP has no `resource` type
     * hint; the precise contract is the `@return resource` tag, which PHPStan
     * enforces. Callers receive a live stream-socket resource.
     *
     * @return resource
     */
    public function socket(): mixed
    {
        return $this->socket;
    }

    /**
     * Performs the HTTP→WebSocket upgrade handshake (RFC 6455 §4).
     *
     * Reads HTTP request headers, validates `Sec-WebSocket-Key`, and sends
     * the `101 Switching Protocols` response.  When called from inside a
     * `Fiber` and the socket has no data yet, the Fiber is suspended until
     * the `Server` event loop detects the socket is readable and resumes it.
     *
     * @throws HandshakeException when the upgrade request is invalid or the
     *                            connection closes before headers are complete
     */
    public function handshake(): void
    {
        $raw = '';

        while (!str_contains($raw, "\r\n\r\n")) {
            if (strlen($raw) >= self::MAX_HANDSHAKE_BYTES) {
                throw new HandshakeException('WebSocket upgrade request exceeds the maximum handshake size.');
            }

            $data = fread($this->socket, 4096);

            if ($data === false) {
                throw new HandshakeException('Connection closed during WebSocket handshake.');
            }

            if ($data === '' && feof($this->socket)) {
                throw new HandshakeException('Connection closed during WebSocket handshake.');
            }

            $raw .= $data;

            if (!str_contains($raw, "\r\n\r\n")) {
                if (Fiber::getCurrent() !== null) {
                    Fiber::suspend();
                }
            }
        }

        $this->requestHeaders = $this->parseHeaders($raw);

        $this->assertValidUpgradeRequest();

        $key = $this->requestHeaders['sec-websocket-key'] ?? '';
        $decodedKey = base64_decode($key, true);

        if ($decodedKey === false || strlen($decodedKey) !== 16) {
            throw new HandshakeException('Invalid Sec-WebSocket-Key header in upgrade request.');
        }

        $accept = base64_encode(
            sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
        );

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n"
            . "\r\n";

        fwrite($this->socket, $response);
        $this->connected = true;
    }

    /**
     * Attempts to read one complete WebSocket frame from the socket.
     *
     * Returns `null` when no complete frame is available yet (non-blocking
     * socket returned no data, or the buffer contains only a partial frame).
     * Returns `null` and sets `isConnected() = false` when the peer has closed.
     *
     * The caller (`Server`) is responsible for suspending and resuming the
     * `Fiber` between calls.
     *
     * @throws WebSocketException on unknown opcode or protocol violation
     */
    public function readFrame(): ?Frame
    {
        if (!$this->connected) {
            return null;
        }

        $data = fread($this->socket, 65536);

        if ($data === false) {
            $this->connected = false;
            return null;
        }

        if ($data === '' && feof($this->socket)) {
            $this->connected = false;
            return null;
        }

        if ($data !== '') {
            $this->buffer .= $data;
        }

        return Frame::parse($this->buffer);
    }

    /**
     * Sends a UTF-8 text frame to the client.
     */
    public function send(string $data): void
    {
        $this->writeFrame(new Frame(Opcode::TEXT, $data));
    }

    /**
     * Sends a binary frame to the client.
     */
    public function sendBinary(string $data): void
    {
        $this->writeFrame(new Frame(Opcode::BINARY, $data));
    }

    /**
     * Sends a PONG frame in response to a PING.
     *
     * @param string $payload Echo the PING payload back (RFC 6455 §5.5.3)
     */
    public function sendPong(string $payload = ''): void
    {
        $this->writeFrame(new Frame(Opcode::PONG, $payload));
    }

    /**
     * Sends a close frame and marks the connection as closed.
     *
     * @param string|null $reason Optional human-readable close reason
     */
    public function close(?string $reason = null): void
    {
        $this->closeWith(1000, $reason);
    }

    /**
     * Sends a close frame with the given RFC 6455 status code and closes the socket.
     *
     * Used by Server for protocol-level refusals (1002 protocol error, 1003
     * unsupported data); close() is the normal-closure (1000) shorthand.
     *
     * @param int         $code   Close status code (RFC 6455 §7.4.1)
     * @param string|null $reason Optional UTF-8 reason text
     */
    public function closeWith(int $code, ?string $reason = null): void
    {
        if (!$this->connected) {
            return;
        }

        $payload = pack('n', $code);
        if ($reason !== null) {
            $payload .= $reason;
        }

        $this->writeFrame(new Frame(Opcode::CLOSE, $payload));
        $this->connected = false;
        fclose($this->socket);
    }

    /**
     * Returns the upgrade-request headers, keyed by lowercased name.
     *
     * Populated by `handshake()`; empty before the handshake completes.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        return $this->requestHeaders;
    }

    /**
     * Returns a single upgrade-request header value (case-insensitive) or `null`.
     */
    public function header(string $name): ?string
    {
        return $this->requestHeaders[strtolower($name)] ?? null;
    }

    /**
     * Writes an encoded frame to the socket.
     */
    private function writeFrame(Frame $frame): void
    {
        if ($this->connected) {
            fwrite($this->socket, $frame->encode());
        }
    }

    /**
     * Validates the required RFC 6455 §4.2.1 upgrade-request headers.
     *
     * Verifying only that `Sec-WebSocket-Key` is present (the previous
     * behaviour) let any plain HTTP request carrying that one header be
     * accepted as a WebSocket upgrade. This checks `Upgrade: websocket`,
     * `Connection: Upgrade` (comma-separated tokens allowed), and
     * `Sec-WebSocket-Version: 13` as well.
     *
     * @throws HandshakeException when a required header is missing or has
     *                            an unexpected value
     */
    private function assertValidUpgradeRequest(): void
    {
        $upgrade = strtolower($this->requestHeaders['upgrade'] ?? '');

        if ($upgrade !== 'websocket') {
            throw new HandshakeException('Missing or invalid Upgrade header in upgrade request.');
        }

        $connectionTokens = array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', $this->requestHeaders['connection'] ?? '')
        );

        if (!in_array('upgrade', $connectionTokens, true)) {
            throw new HandshakeException('Missing or invalid Connection header in upgrade request.');
        }

        if (($this->requestHeaders['sec-websocket-version'] ?? '') !== '13') {
            throw new HandshakeException('Missing or unsupported Sec-WebSocket-Version header in upgrade request.');
        }

        if (!isset($this->requestHeaders['sec-websocket-key'])) {
            throw new HandshakeException('Missing Sec-WebSocket-Key header in upgrade request.');
        }
    }

    /**
     * Parses the HTTP header block of the raw upgrade request into a map
     * keyed by lowercased header name. The request line and anything past
     * the terminating CRLFCRLF are ignored. Duplicate headers keep the last
     * value seen.
     *
     * @param string $raw Raw bytes read during the handshake.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $end = strpos($raw, "\r\n\r\n");
        $headerBlock = $end === false ? $raw : substr($raw, 0, $end);

        $lines = explode("\r\n", $headerBlock);
        array_shift($lines); // drop the request line (e.g. "GET /chat HTTP/1.1")

        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
