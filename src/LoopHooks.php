<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

use Closure;

/**
 * Class LoopHooks
 *
 * Extension points of a stream_select() event loop (Server, and
 * ez-php/websocket-tls's TlsServer): extra streams to watch for readability —
 * e.g. a Redis Pub/Sub socket feeding events from other processes — and tick
 * callbacks that run on every loop iteration (at least every ~50 ms), for
 * timers such as a protocol's initialisation timeout.
 *
 * Callbacks run on the loop's own call stack, outside any connection Fiber; an
 * exception thrown by one stops the server, like any other loop error.
 *
 * @package EzPhp\WebSocket
 */
final class LoopHooks
{
    /**
     * @var array<int, array{resource, Closure(resource): void}>
     */
    private array $streams = [];

    /**
     * @var list<Closure(): void>
     */
    private array $ticks = [];

    /**
     * Watch a stream; `$onReadable($stream)` runs whenever stream_select() reports it readable.
     *
     * @param resource                $stream
     * @param Closure(resource): void $onReadable
     *
     * @return void
     */
    public function addReadStream(mixed $stream, Closure $onReadable): void
    {
        $this->streams[get_resource_id($stream)] = [$stream, $onReadable];
    }

    /**
     * Stop watching a stream.
     *
     * @param resource $stream
     *
     * @return void
     */
    public function removeReadStream(mixed $stream): void
    {
        unset($this->streams[get_resource_id($stream)]);
    }

    /**
     * Run `$callback` on every loop iteration.
     *
     * @param Closure(): void $callback
     *
     * @return void
     */
    public function onTick(Closure $callback): void
    {
        $this->ticks[] = $callback;
    }

    /**
     * The watched streams, to add to the loop's stream_select() read set.
     *
     * @return list<resource>
     */
    public function readStreams(): array
    {
        $streams = [];

        foreach ($this->streams as $rid => [$stream]) {
            if (is_resource($stream)) {
                $streams[] = $stream;
            } else {
                unset($this->streams[$rid]); // closed elsewhere
            }
        }

        return $streams;
    }

    /**
     * Dispatch a readable stream to its callback; false when it is not a hook stream.
     *
     * @param resource $stream
     *
     * @return bool
     */
    public function dispatchReadable(mixed $stream): bool
    {
        $entry = $this->streams[get_resource_id($stream)] ?? null;

        if ($entry === null) {
            return false;
        }

        ($entry[1])($stream);

        return true;
    }

    /**
     * Run every tick callback once.
     *
     * @return void
     */
    public function tick(): void
    {
        foreach ($this->ticks as $tick) {
            $tick();
        }
    }
}
