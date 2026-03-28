<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * Manages named pub/sub channels for WebSocket connections.
 *
 * Channels are created on first subscribe and removed when they become empty.
 * Broadcasting automatically prunes connections that are no longer connected.
 *
 * Usage in a handler:
 *
 *   public function onOpen(ConnectionInterface $conn): void {
 *       $this->channels->subscribe('general', $conn);
 *   }
 *   public function onMessage(ConnectionInterface $conn, Frame $frame): void {
 *       $this->channels->broadcast('general', $frame->payload);
 *   }
 *   public function onClose(ConnectionInterface $conn): void {
 *       $this->channels->unsubscribeAll($conn);
 *   }
 *
 * @package EzPhp\WebSocket
 */
final class ChannelManager
{
    /**
     * Channels indexed by name; each channel maps connection ID → connection.
     *
     * @var array<string, array<string, ConnectionInterface>>
     */
    private array $channels = [];

    /**
     * Subscribes `$connection` to `$channel`.
     * Calling this more than once for the same connection/channel is idempotent.
     */
    public function subscribe(string $channel, ConnectionInterface $connection): void
    {
        $this->channels[$channel][$connection->id()] = $connection;
    }

    /**
     * Removes `$connection` from `$channel`.
     * If the channel becomes empty it is deleted.
     */
    public function unsubscribe(string $channel, ConnectionInterface $connection): void
    {
        unset($this->channels[$channel][$connection->id()]);

        if (isset($this->channels[$channel]) && $this->channels[$channel] === []) {
            unset($this->channels[$channel]);
        }
    }

    /**
     * Removes `$connection` from every channel it is subscribed to.
     * Typically called in `HandlerInterface::onClose()`.
     */
    public function unsubscribeAll(ConnectionInterface $connection): void
    {
        foreach (array_keys($this->channels) as $channel) {
            $this->unsubscribe($channel, $connection);
        }
    }

    /**
     * Sends a text message to every connected subscriber of `$channel`.
     * Disconnected connections are pruned from the channel automatically.
     *
     * @param string $channel Channel name (no-op if the channel does not exist)
     * @param string $message UTF-8 text payload
     */
    public function broadcast(string $channel, string $message): void
    {
        if (!isset($this->channels[$channel])) {
            return;
        }

        foreach ($this->channels[$channel] as $id => $connection) {
            if ($connection->isConnected()) {
                $connection->send($message);
            } else {
                unset($this->channels[$channel][$id]);
            }
        }

        if ($this->channels[$channel] === []) {
            unset($this->channels[$channel]);
        }
    }

    /**
     * Returns all active connections subscribed to `$channel`.
     *
     * @return list<ConnectionInterface>
     */
    public function connections(string $channel): array
    {
        return array_values($this->channels[$channel] ?? []);
    }

    /**
     * Returns the names of all channels that have at least one subscriber.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * Returns the number of connections subscribed to `$channel`.
     */
    public function count(string $channel): int
    {
        return count($this->channels[$channel] ?? []);
    }
}
