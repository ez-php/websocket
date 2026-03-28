<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\Server;
use EzPhp\WebSocket\WebSocketException;

/**
 * Unit-level tests for the Server class.
 *
 * Full end-to-end tests (connect a real WebSocket client, send messages, verify
 * the handler fires) require running the server in a separate process and are
 * out of scope for this test suite. Those belong in integration tests.
 *
 * @covers \EzPhp\WebSocket\Server
 */
final class ServerTest extends TestCase
{
    public function testConstructorAndAccessors(): void
    {
        $server = new Server('127.0.0.1', 9090);

        self::assertSame('127.0.0.1', $server->host());
        self::assertSame(9090, $server->port());
    }

    public function testDefaultHostAndPort(): void
    {
        $server = new Server();

        self::assertSame('0.0.0.0', $server->host());
        self::assertSame(8080, $server->port());
    }

    public function testRunThrowsWhenPortAlreadyInUse(): void
    {
        // Bind a server socket to block the port, then try to start our Server
        $blocker = @stream_socket_server('tcp://127.0.0.1:19876');

        if ($blocker === false) {
            $this->markTestSkipped('Cannot bind test port 19876');
        }

        $this->expectException(WebSocketException::class);

        $handler = new class () implements HandlerInterface {
            public function onOpen(ConnectionInterface $conn): void
            {
            }

            public function onMessage(ConnectionInterface $conn, Frame $frame): void
            {
            }

            public function onClose(ConnectionInterface $conn): void
            {
            }

            public function onError(ConnectionInterface $conn, \Throwable $e): void
            {
            }
        };

        $server = new Server('127.0.0.1', 19876);

        try {
            $server->run($handler);
        } finally {
            fclose($blocker);
        }
    }
}
