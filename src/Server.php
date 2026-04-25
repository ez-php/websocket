<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

use Fiber;

/**
 * PHP 8.5 Fiber-based WebSocket server.
 *
 * Each accepted TCP connection gets its own `Fiber`.  The event loop calls
 * `stream_select()` on all open sockets and resumes the appropriate Fiber
 * whenever data is available, enabling concurrent connections without threads.
 *
 * Usage:
 *
 *   $server = new Server('0.0.0.0', 8080);
 *   $server->run(new MyHandler()); // blocks until interrupted
 *
 * The server runs until `stream_select()` returns `false` (rare system error)
 * or the process is killed.  Handle `SIGINT`/`SIGTERM` in your handler or via
 * `pcntl_signal()` before calling `run()`.
 *
 * @package EzPhp\WebSocket
 */
final class Server
{
    /**
     * Connections indexed by resource ID.
     *
     * @var array<int, Connection>
     */
    private array $connections = [];

    /**
     * Fibers indexed by resource ID.
     *
     * @var array<int, Fiber<mixed, mixed, void, mixed>>
     */
    private array $fibers = [];

    /**
     * Raw stream sockets indexed by resource ID (for stream_select).
     *
     * @var array<int, resource>
     */
    private array $sockets = [];

    private int $nextId = 1;

    /**
     * @param string $host Bind address (e.g. '0.0.0.0' or '127.0.0.1')
     * @param int    $port TCP port to listen on
     */
    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8080,
    ) {
    }

    /**
     * Returns the bind address.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Returns the listen port.
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Starts the WebSocket server and blocks until an unrecoverable error occurs.
     *
     * For each accepted connection a `Fiber` is created that handles the
     * RFC 6455 handshake, dispatches frames to `$handler`, and replies to
     * PING/CLOSE control frames automatically.
     *
     * @throws WebSocketException when the server socket cannot be created
     */
    public function run(HandlerInterface $handler): void
    {
        $serverSocket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr
        );

        if ($serverSocket === false) {
            throw new WebSocketException(
                "Cannot start WebSocket server on {$this->host}:{$this->port}: {$errstr} ({$errno})"
            );
        }

        stream_set_blocking($serverSocket, false);

        try {
            $this->loop($handler, $serverSocket);
        } finally {
            fclose($serverSocket);
        }
    }

    /**
     * Main event loop: accept connections and dispatch Fiber resumes.
     *
     * @param resource $serverSocket
     */
    private function loop(HandlerInterface $handler, $serverSocket): void
    {
        while (true) {
            // Rebuild read array each iteration (stream_select modifies it)
            $read = [$serverSocket];
            foreach ($this->sockets as $socket) {
                $read[] = $socket;
            }

            $write = null;
            $except = null;

            $count = stream_select($read, $write, $except, 0, 50_000);

            if ($count === false) {
                break;
            }

            foreach ($read as $readable) {
                if (!is_resource($readable)) {
                    continue;
                }

                if ($readable === $serverSocket) {
                    $this->acceptConnection($handler, $serverSocket);
                } else {
                    $rid = get_resource_id($readable);
                    $fiber = $this->fibers[$rid] ?? null;
                    if ($fiber !== null && $fiber->isSuspended()) {
                        $fiber->resume();
                        if ($fiber->isTerminated()) {
                            $this->removeConnection($rid);
                        }
                    }
                }
            }

            // Reap any fibers that terminated since last iteration
            foreach ($this->fibers as $rid => $fiber) {
                if ($fiber->isTerminated()) {
                    $this->removeConnection($rid);
                }
            }
        }
    }

    /**
     * Accepts one new TCP connection and starts its Fiber.
     *
     * @param resource $serverSocket
     */
    private function acceptConnection(HandlerInterface $handler, $serverSocket): void
    {
        $clientSocket = @stream_socket_accept($serverSocket, 0);

        if ($clientSocket === false) {
            return;
        }

        stream_set_blocking($clientSocket, false);

        $conn = new Connection($clientSocket, (string) $this->nextId++);
        $rid = get_resource_id($clientSocket);

        $this->connections[$rid] = $conn;
        $this->sockets[$rid] = $clientSocket;

        $fiber = new Fiber(function () use ($conn, $handler): void {
            $this->handleConnection($conn, $handler);
        });

        $this->fibers[$rid] = $fiber;
        $fiber->start();

        if ($fiber->isTerminated()) {
            $this->removeConnection($rid);
        }
    }

    /**
     * Connection lifecycle: handshake → frame loop → close.
     * Runs inside a Fiber; suspends when no data is available.
     */
    private function handleConnection(Connection $conn, HandlerInterface $handler): void
    {
        try {
            $conn->handshake();
        } catch (HandshakeException $e) {
            $handler->onError($conn, $e);
            return;
        }

        $handler->onOpen($conn);

        try {
            while ($conn->isConnected()) {
                $frame = $conn->readFrame();

                if ($frame === null) {
                    Fiber::suspend();
                    continue;
                }

                match ($frame->opcode) {
                    Opcode::TEXT, Opcode::BINARY => $handler->onMessage($conn, $frame),
                    Opcode::CLOSE => $conn->close(),
                    Opcode::PING => $conn->sendPong($frame->payload),
                    Opcode::PONG, Opcode::CONTINUATION => null,
                };
            }
        } catch (\Throwable $e) {
            $handler->onError($conn, $e);
        } finally {
            $handler->onClose($conn);
        }
    }

    /**
     * Removes all tracking state for the given resource ID.
     */
    private function removeConnection(int $rid): void
    {
        unset($this->connections[$rid], $this->fibers[$rid], $this->sockets[$rid]);
    }
}
