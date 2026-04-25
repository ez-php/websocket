<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * Handles lifecycle events for WebSocket connections managed by `Server`.
 *
 * Implement this interface to define application behaviour:
 *
 *   class ChatHandler implements HandlerInterface {
 *       public function onOpen(ConnectionInterface $conn): void {
 *           $conn->send('Welcome!');
 *       }
 *       public function onMessage(ConnectionInterface $conn, Frame $frame): void {
 *           $conn->send('Echo: ' . $frame->payload);
 *       }
 *       public function onClose(ConnectionInterface $conn): void { }
 *       public function onError(ConnectionInterface $conn, \Throwable $e): void { }
 *   }
 *
 *   $server = new Server('0.0.0.0', 8080);
 *   $server->run(new ChatHandler());
 *
 * @package EzPhp\WebSocket
 */
interface HandlerInterface
{
    /**
     * Called once after the WebSocket handshake completes successfully.
     * The connection is fully open and ready to send/receive messages.
     */
    public function onOpen(ConnectionInterface $conn): void;

    /**
     * Called for every complete TEXT or BINARY frame received from the client.
     * PING frames are handled automatically by the server (PONG reply sent).
     * CLOSE frames trigger `onClose()` after acknowledgement.
     */
    public function onMessage(ConnectionInterface $conn, Frame $frame): void;

    /**
     * Called when a connection is closed, regardless of whether the close was
     * initiated by the server or the client.  The connection is already closed
     * when this is called — do not attempt to send messages.
     */
    public function onClose(ConnectionInterface $conn): void;

    /**
     * Called when an unhandled exception occurs during handshake or frame processing.
     * The connection may still be open when this is called (e.g. for non-fatal errors).
     */
    public function onError(ConnectionInterface $conn, \Throwable $e): void;
}
