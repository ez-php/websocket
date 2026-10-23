<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\LoopHooks;
use EzPhp\WebSocket\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Throwable;

/**
 * LoopHooks on their own, and wired into Server::run(): a watched stream's
 * callback fires when it becomes readable, ticks run every iteration. A tick
 * that throws ends run(), which is how the test leaves the loop.
 */
#[CoversClass(LoopHooks::class)]
#[CoversClass(Server::class)]
final class WebSocketLoopHooksTest extends TestCase
{
    /**
     * @return array{resource, resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }

    public function test_dispatch_and_removal(): void
    {
        [$a, $b] = $this->pair();
        $hooks = new LoopHooks();
        $seen = [];
        $hooks->addReadStream($a, static function ($stream) use (&$seen): void {
            $seen[] = $stream;
        });

        $this->assertSame([$a], $hooks->readStreams());
        $this->assertTrue($hooks->dispatchReadable($a));
        $this->assertFalse($hooks->dispatchReadable($b));
        $this->assertSame([$a], $seen);

        $hooks->removeReadStream($a);
        $this->assertSame([], $hooks->readStreams());
    }

    public function test_closed_streams_are_dropped_and_ticks_run_in_order(): void
    {
        [$a] = $this->pair();
        $hooks = new LoopHooks();
        $hooks->addReadStream($a, static function (): void {
        });
        fclose($a);

        $order = [];
        $hooks->onTick(static function () use (&$order): void {
            $order[] = 1;
        });
        $hooks->onTick(static function () use (&$order): void {
            $order[] = 2;
        });
        $hooks->tick();

        $this->assertSame([], $hooks->readStreams());
        $this->assertSame([1, 2], $order);
    }

    public function test_server_loop_watches_hook_streams_and_runs_ticks(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $server = new Server('127.0.0.1', $port);
        [$read, $write] = $this->pair();
        fwrite($write, 'event');
        $received = '';
        $ticks = 0;

        $server->hooks()->addReadStream($read, static function ($stream) use (&$received): void {
            $received .= (string) fread($stream, 1024);
        });
        $server->hooks()->onTick(static function () use (&$received, &$ticks): void {
            $ticks++;

            if ($received !== '' || $ticks > 100) {
                throw new RuntimeException('stop');
            }
        });

        try {
            $server->run(new class () implements HandlerInterface {
                public function onOpen(ConnectionInterface $conn): void
                {
                }

                public function onMessage(ConnectionInterface $conn, Frame $frame): void
                {
                }

                public function onClose(ConnectionInterface $conn): void
                {
                }

                public function onError(ConnectionInterface $conn, Throwable $e): void
                {
                }
            });
            $this->fail('run() should have been stopped by the tick.');
        } catch (RuntimeException $e) {
            $this->assertSame('stop', $e->getMessage());
        }

        $this->assertSame('event', $received);
        $this->assertGreaterThanOrEqual(1, $ticks);
    }
}
