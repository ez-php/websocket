<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * Represents an open WebSocket connection to a single client.
 *
 * Implementations are provided by `Connection` (stream-socket-backed).
 * Custom implementations can be used in tests or to wrap other transports.
 *
 * @package EzPhp\WebSocket
 */
interface ConnectionInterface
{
    /**
     * Returns the unique identifier for this connection.
     * The format is implementation-defined; typically a sequential integer string.
     */
    public function id(): string;

    /**
     * Returns `true` as long as the connection is open and usable.
     */
    public function isConnected(): bool;

    /**
     * Sends a UTF-8 text message to the client.
     */
    public function send(string $data): void;

    /**
     * Sends a binary message to the client.
     */
    public function sendBinary(string $data): void;

    /**
     * Initiates a clean WebSocket close handshake with status code 1000.
     * After calling `close()`, `isConnected()` returns `false`.
     *
     * @param string|null $reason Optional close reason appended after the status code
     */
    public function close(?string $reason = null): void;

    /**
     * Returns the HTTP headers sent with the upgrade request, keyed by
     * lowercased header name. Empty until `handshake()` has completed.
     *
     * Handlers use this in `onOpen()` to inspect `origin`, `cookie`, or
     * authentication headers and decide whether to keep or `close()` the
     * connection (e.g. Origin checks to prevent Cross-Site WebSocket
     * Hijacking). This module performs no authorization itself.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array;

    /**
     * Returns a single upgrade-request header value by name (case-insensitive),
     * or `null` when the header was not present.
     */
    public function header(string $name): ?string;
}
