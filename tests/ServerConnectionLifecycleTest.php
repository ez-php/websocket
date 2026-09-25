<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Connection;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\Server;
use Fiber;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Collects the handler callbacks a lifecycle test observed.
 */
final class WsLifecycleLog
{
    /** @var list<string> */
    public array $events = [];
}

/**
 * Thrown by WsStopLoopHandler to leave Server::loop(), which otherwise never returns.
 */
final class WsStopLoop extends \RuntimeException
{
}

/**
 * Handler that lets a test drive Server::loop() to completion: `$onOpen` runs inside the loop
 * once the WebSocket handshake finished (the test uses it to send its frames), and a `stop`
 * message escapes the loop with WsStopLoop.
 */
final class WsStopLoopHandler implements HandlerInterface
{
    /**
     * @param \Closure(): void $onOpen
     */
    public function __construct(private readonly \Closure $onOpen, private readonly WsLifecycleLog $log)
    {
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        ($this->onOpen)();
    }

    public function onMessage(ConnectionInterface $conn, Frame $frame): void
    {
        $this->log->events[] = $frame->payload;

        if ($frame->payload === 'stop') {
            throw new WsStopLoop('stop');
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
    }

    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
        if ($e instanceof WsStopLoop) {
            throw $e;
        }
    }
}

/**
 * Drives `Server`'s per-connection lifecycle in-process, without the blocking event loop:
 * `handleConnection()` (handshake → frame loop → close) over a connected socket pair, and
 * `acceptConnection()` against a real loopback listener.
 *
 * `Server::run()` never returns, so end-to-end runs need a child process; these tests reach
 * the private lifecycle methods directly, which keeps them deterministic and visible to the
 * coverage driver.
 */
final class ServerConnectionLifecycleTest extends TestCase
{
    private const string KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    private WsLifecycleLog $log;

    private HandlerInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new WsLifecycleLog();

        $this->handler = new class ($this->log) implements HandlerInterface {
            public function __construct(private readonly WsLifecycleLog $log)
            {
            }

            public function onOpen(ConnectionInterface $conn): void
            {
                $this->log->events[] = 'open';
            }

            public function onMessage(ConnectionInterface $conn, Frame $frame): void
            {
                $this->log->events[] = 'message:' . $frame->payload;

                if ($frame->payload === 'boom') {
                    throw new \RuntimeException('handler failed');
                }

                $conn->send('echo:' . $frame->payload);
            }

            public function onClose(ConnectionInterface $conn): void
            {
                $this->log->events[] = 'close';
            }

            public function onError(ConnectionInterface $conn, \Throwable $e): void
            {
                $this->log->events[] = 'error:' . $e::class;
            }
        };
    }

    public function test_full_lifecycle_upgrade_message_ping_close(): void
    {
        [$peer, $fiber] = $this->startConnection();

        $this->assertStringContainsString('101 Switching Protocols', $this->handshake($peer, $fiber));
        self::assertSame(['open'], $this->log->events);

        fwrite($peer, $this->maskedFrame(Opcode::TEXT, 'hi'));
        $fiber->resume();
        self::assertSame('echo:hi', $this->readServerFrame($peer)->payload);

        fwrite($peer, $this->maskedFrame(Opcode::PING, 'p'));
        $fiber->resume();
        $pong = $this->readServerFrame($peer);
        self::assertSame(Opcode::PONG, $pong->opcode);
        self::assertSame('p', $pong->payload);

        fwrite($peer, $this->maskedFrame(Opcode::PONG, ''));
        fwrite($peer, $this->maskedFrame(Opcode::CLOSE, ''));
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['open', 'message:hi', 'close'], $this->log->events);
    }

    public function test_binary_frames_reach_the_handler(): void
    {
        [$peer, $fiber] = $this->startConnection();
        $this->handshake($peer, $fiber);

        fwrite($peer, $this->maskedFrame(Opcode::BINARY, 'bin'));
        $fiber->resume();

        self::assertContains('message:bin', $this->log->events);
    }

    public function test_a_fragmented_message_is_refused_with_1003_instead_of_delivered_truncated(): void
    {
        [$peer, $fiber] = $this->startConnection();
        $this->handshake($peer, $fiber);

        fwrite($peer, $this->rawFrame(Opcode::TEXT, 'part-1', fin: false));
        fwrite($peer, $this->rawFrame(Opcode::CONTINUATION, 'part-2', fin: true));
        $fiber->resume();

        $close = $this->readServerFrame($peer);
        self::assertSame(Opcode::CLOSE, $close->opcode);
        self::assertSame(1003, unpack('n', $close->payload)[1] ?? null);
        self::assertNotContains('message:part-1', $this->log->events);
        self::assertTrue($fiber->isTerminated());
        self::assertSame('close', end($this->log->events));
    }

    public function test_a_stray_continuation_frame_is_refused_with_1003(): void
    {
        [$peer, $fiber] = $this->startConnection();
        $this->handshake($peer, $fiber);

        fwrite($peer, $this->rawFrame(Opcode::CONTINUATION, 'orphan', fin: true));
        $fiber->resume();

        $close = $this->readServerFrame($peer);
        self::assertSame(Opcode::CLOSE, $close->opcode);
        self::assertSame(1003, unpack('n', $close->payload)[1] ?? null);
        self::assertTrue($fiber->isTerminated());
    }

    public function test_an_unmasked_client_frame_is_refused_with_1002(): void
    {
        [$peer, $fiber] = $this->startConnection();
        $this->handshake($peer, $fiber);

        fwrite($peer, $this->rawFrame(Opcode::TEXT, 'plain', fin: true, masked: false));
        $fiber->resume();

        $close = $this->readServerFrame($peer);
        self::assertSame(Opcode::CLOSE, $close->opcode);
        self::assertSame(1002, unpack('n', $close->payload)[1] ?? null);
        self::assertNotContains('message:plain', $this->log->events);
        self::assertTrue($fiber->isTerminated());
    }

    public function test_invalid_upgrade_request_reports_an_error_and_never_opens(): void
    {
        [$peer, $fiber] = $this->startConnection();

        fwrite($peer, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['error:EzPhp\WebSocket\HandshakeException'], $this->log->events);
    }

    public function test_a_handler_exception_is_reported_and_the_connection_still_closes(): void
    {
        [$peer, $fiber] = $this->startConnection();
        $this->handshake($peer, $fiber);

        fwrite($peer, $this->maskedFrame(Opcode::TEXT, 'boom'));
        $fiber->resume();

        self::assertSame(['open', 'message:boom', 'error:RuntimeException', 'close'], $this->log->events);
    }

    public function test_a_peer_that_disconnects_mid_session_ends_the_fiber(): void
    {
        [$peer, $fiber] = $this->startConnection();
        $this->handshake($peer, $fiber);

        fclose($peer);
        $fiber->resume(); // readFrame() notices EOF and marks the connection closed, then yields once more
        $fiber->resume(); // the loop condition is re-checked on the next resume

        self::assertTrue($fiber->isTerminated());
        self::assertSame('close', end($this->log->events));
    }

    public function test_accept_connection_registers_the_client_and_starts_its_fiber(): void
    {
        $server = new Server('127.0.0.1', 0);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        stream_set_blocking($listener, false);

        $address = (string) stream_socket_get_name($listener, false);
        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 2);
        self::assertNotFalse($client, (string) $errstr);

        (new ReflectionMethod($server, 'acceptConnection'))->invoke($server, $this->handler, $listener);

        $connections = $this->property($server, 'connections');
        self::assertCount(1, $connections);
        self::assertCount(1, $this->property($server, 'fibers'));
        self::assertCount(1, $this->property($server, 'sockets'));

        // The client speaks: the server-side fiber completes the handshake when resumed.
        fwrite($client, $this->upgradeRequest());
        $fibers = $this->property($server, 'fibers');
        $fiber = reset($fibers);
        self::assertInstanceOf(Fiber::class, $fiber);
        $fiber->resume();

        self::assertContains('open', $this->log->events);

        fclose($client);
        fclose($listener);
    }

    public function test_accept_connection_without_a_pending_client_does_nothing(): void
    {
        $server = new Server('127.0.0.1', 0);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        stream_set_blocking($listener, false);

        (new ReflectionMethod($server, 'acceptConnection'))->invoke($server, $this->handler, $listener);

        self::assertSame([], $this->property($server, 'connections'));

        fclose($listener);
    }

    public function test_remove_connection_forgets_all_state_for_the_resource(): void
    {
        $server = new Server('127.0.0.1', 0);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        stream_set_blocking($listener, false);
        $client = stream_socket_client('tcp://' . stream_socket_get_name($listener, false), $errno, $errstr, 2);
        self::assertNotFalse($client, (string) $errstr);

        (new ReflectionMethod($server, 'acceptConnection'))->invoke($server, $this->handler, $listener);
        $rid = array_key_first($this->property($server, 'connections'));
        self::assertIsInt($rid);

        (new ReflectionMethod($server, 'removeConnection'))->invoke($server, $rid);

        self::assertSame([], $this->property($server, 'connections'));
        self::assertSame([], $this->property($server, 'fibers'));
        self::assertSame([], $this->property($server, 'sockets'));

        fclose($client);
        fclose($listener);
    }

    public function test_loop_accepts_a_client_serves_its_frames_and_can_be_left_by_the_handler(): void
    {
        $server = new Server('127.0.0.1', 0);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        stream_set_blocking($listener, false);

        $client = stream_socket_client('tcp://' . stream_socket_get_name($listener, false), $errno, $errstr, 2);
        self::assertNotFalse($client, (string) $errstr);
        fwrite($client, $this->upgradeRequest());

        $log = new WsLifecycleLog();
        $handler = new WsStopLoopHandler(function () use ($client): void {
            fwrite($client, $this->maskedFrame(Opcode::TEXT, 'first'));
            fwrite($client, $this->maskedFrame(Opcode::TEXT, 'stop'));
        }, $log);

        try {
            (new ReflectionMethod($server, 'loop'))->invoke($server, $handler, $listener);
            self::fail('loop() should have been left through WsStopLoop.');
        } catch (WsStopLoop) {
            self::assertSame(['first', 'stop'], $log->events);
        } finally {
            fclose($client);
            fclose($listener);
        }
    }

    /**
     * Start `handleConnection()` in a Fiber over a connected socket pair.
     *
     * @return array{0: resource, 1: Fiber<mixed, mixed, mixed, mixed>} The peer end and the (suspended) fiber.
     */
    private function startConnection(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$peer, $serverSide] = $pair;
        stream_set_blocking($serverSide, false);
        stream_set_blocking($peer, false);

        $server = new Server('127.0.0.1', 0);
        $conn = new Connection($serverSide, '1');
        $method = new ReflectionMethod($server, 'handleConnection');

        $fiber = new Fiber(static function () use ($method, $server, $conn): void {
            $method->invoke($server, $conn, $GLOBALS['ez_lifecycle_handler']);
        });

        $GLOBALS['ez_lifecycle_handler'] = $this->handler;
        $fiber->start();

        return [$peer, $fiber];
    }

    /**
     * @param resource                              $peer
     * @param Fiber<mixed, mixed, mixed, mixed>     $fiber
     */
    private function handshake(mixed $peer, Fiber $fiber): string
    {
        fwrite($peer, $this->upgradeRequest());
        $fiber->resume();

        $head = (string) fread($peer, 4096);
        self::assertStringContainsString("\r\n\r\n", $head);

        return $head;
    }

    private function upgradeRequest(): string
    {
        return "GET /chat HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . self::KEY . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
    }

    /**
     * @param resource $peer
     */
    private function readServerFrame(mixed $peer): Frame
    {
        $buffer = '';
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $frame = Frame::parse($buffer);

            if ($frame !== null) {
                return $frame;
            }

            $chunk = fread($peer, 4096);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            } else {
                usleep(1000);
            }
        }

        self::fail('No complete frame from the server.');
    }

    private function rawFrame(Opcode $opcode, string $payload, bool $fin, bool $masked = true): string
    {
        $b0 = ($fin ? 0x80 : 0x00) | $opcode->value;

        if (!$masked) {
            return chr($b0) . chr(strlen($payload) & 0x7F) . $payload;
        }

        $mask = "\x0a\x0b\x0c\x0d";
        $body = '';

        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $body .= $payload[$i] ^ $mask[$i % 4];
        }

        return chr($b0) . chr((0x80 | strlen($payload)) & 0xFF) . $mask . $body;
    }

    private function maskedFrame(Opcode $opcode, string $payload): string
    {
        $mask = "\x0a\x0b\x0c\x0d";
        $masked = '';

        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return chr((0x80 | $opcode->value) & 0xFF) . chr((0x80 | strlen($payload)) & 0xFF) . $mask . $masked;
    }

    /**
     * @return array<int, mixed>
     */
    private function property(Server $server, string $name): array
    {
        $value = (new ReflectionProperty($server, $name))->getValue($server);
        self::assertIsArray($value);

        return $value;
    }
}
