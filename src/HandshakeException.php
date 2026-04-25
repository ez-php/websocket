<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * Thrown when the HTTP→WebSocket upgrade handshake fails.
 *
 * Common causes: missing `Sec-WebSocket-Key` header, client closed the
 * connection before sending the request, or a non-WebSocket HTTP request.
 *
 * @package EzPhp\WebSocket
 */
final class HandshakeException extends WebSocketException
{
}
