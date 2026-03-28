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
    /** @var resource */
    private $socket;

    private string $buffer = '';

    private bool $connected = false;

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
            $data = fread($this->socket, 4096);

            if ($data === false) {
                throw new HandshakeException('Connection closed during WebSocket handshake.');
            }

            $raw .= $data;

            if (!str_contains($raw, "\r\n\r\n")) {
                if (Fiber::getCurrent() !== null) {
                    Fiber::suspend();
                }
            }
        }

        if (!preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $raw, $matches)) {
            throw new HandshakeException('Missing Sec-WebSocket-Key header in upgrade request.');
        }

        $accept = base64_encode(
            sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
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
        if (!$this->connected) {
            return;
        }

        // Status code 1000 = Normal Closure
        $payload = pack('n', 1000);
        if ($reason !== null) {
            $payload .= $reason;
        }

        $this->writeFrame(new Frame(Opcode::CLOSE, $payload));
        $this->connected = false;
        fclose($this->socket);
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
}
