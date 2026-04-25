<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\ChannelManager;
use EzPhp\WebSocket\ConnectionInterface;

/**
 * @covers \EzPhp\WebSocket\ChannelManager
 */
final class ChannelManagerTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Test double
    // ──────────────────────────────────────────────────────────────

    /** @return ConnectionInterface&\PHPUnit\Framework\MockObject\Stub */
    private function mockConn(string $id, bool $connected = true): ConnectionInterface
    {
        $conn = $this->createStub(ConnectionInterface::class);
        $conn->method('id')->willReturn($id);
        $conn->method('isConnected')->willReturn($connected);
        return $conn;
    }

    // ──────────────────────────────────────────────────────────────
    // subscribe / unsubscribe
    // ──────────────────────────────────────────────────────────────

    public function testSubscribeAddsToChannel(): void
    {
        $mgr = new ChannelManager();
        $conn = $this->mockConn('1');

        $mgr->subscribe('general', $conn);

        self::assertContains('general', $mgr->channels());
        self::assertCount(1, $mgr->connections('general'));
    }

    public function testSubscribeIsIdempotent(): void
    {
        $mgr = new ChannelManager();
        $conn = $this->mockConn('1');

        $mgr->subscribe('general', $conn);
        $mgr->subscribe('general', $conn);

        self::assertCount(1, $mgr->connections('general'));
    }

    public function testUnsubscribeRemovesFromChannel(): void
    {
        $mgr = new ChannelManager();
        $conn = $this->mockConn('1');

        $mgr->subscribe('general', $conn);
        $mgr->unsubscribe('general', $conn);

        self::assertNotContains('general', $mgr->channels());
        self::assertCount(0, $mgr->connections('general'));
    }

    public function testUnsubscribeNonMemberIsNoOp(): void
    {
        $mgr = new ChannelManager();
        $conn = $this->mockConn('1');

        $mgr->unsubscribe('general', $conn); // channel does not exist yet

        self::assertSame([], $mgr->channels());
    }

    public function testUnsubscribeAllRemovesFromAllChannels(): void
    {
        $mgr = new ChannelManager();
        $conn = $this->mockConn('1');

        $mgr->subscribe('a', $conn);
        $mgr->subscribe('b', $conn);
        $mgr->subscribe('c', $conn);

        $mgr->unsubscribeAll($conn);

        self::assertSame([], $mgr->channels());
    }

    public function testChannelRemovedWhenEmpty(): void
    {
        $mgr = new ChannelManager();
        $a = $this->mockConn('1');
        $b = $this->mockConn('2');

        $mgr->subscribe('room', $a);
        $mgr->subscribe('room', $b);
        $mgr->unsubscribe('room', $a);

        self::assertContains('room', $mgr->channels());

        $mgr->unsubscribe('room', $b);

        self::assertNotContains('room', $mgr->channels());
    }

    // ──────────────────────────────────────────────────────────────
    // channels / connections / count
    // ──────────────────────────────────────────────────────────────

    public function testChannelsListsAllActiveChannels(): void
    {
        $mgr = new ChannelManager();
        $mgr->subscribe('x', $this->mockConn('1'));
        $mgr->subscribe('y', $this->mockConn('2'));
        $mgr->subscribe('z', $this->mockConn('3'));

        self::assertEqualsCanonicalizing(['x', 'y', 'z'], $mgr->channels());
    }

    public function testConnectionsReturnsSubscribersForChannel(): void
    {
        $mgr = new ChannelManager();
        $a = $this->mockConn('1');
        $b = $this->mockConn('2');

        $mgr->subscribe('room', $a);
        $mgr->subscribe('room', $b);

        $conns = $mgr->connections('room');
        self::assertCount(2, $conns);
    }

    public function testConnectionsReturnsEmptyForUnknownChannel(): void
    {
        $mgr = new ChannelManager();
        self::assertSame([], $mgr->connections('unknown'));
    }

    public function testCountReturnsSubscriberCount(): void
    {
        $mgr = new ChannelManager();
        $mgr->subscribe('r', $this->mockConn('1'));
        $mgr->subscribe('r', $this->mockConn('2'));

        self::assertSame(2, $mgr->count('r'));
    }

    public function testCountReturnsZeroForUnknownChannel(): void
    {
        $mgr = new ChannelManager();
        self::assertSame(0, $mgr->count('unknown'));
    }

    // ──────────────────────────────────────────────────────────────
    // broadcast
    // ──────────────────────────────────────────────────────────────

    public function testBroadcastSendsToAllConnectedSubscribers(): void
    {
        $mgr = new ChannelManager();

        $a = $this->createMock(ConnectionInterface::class);
        $a->method('id')->willReturn('1');
        $a->method('isConnected')->willReturn(true);
        $a->expects(self::once())->method('send')->with('hello');

        $b = $this->createMock(ConnectionInterface::class);
        $b->method('id')->willReturn('2');
        $b->method('isConnected')->willReturn(true);
        $b->expects(self::once())->method('send')->with('hello');

        $mgr->subscribe('room', $a);
        $mgr->subscribe('room', $b);
        $mgr->broadcast('room', 'hello');
    }

    public function testBroadcastSkipsDisconnectedConnections(): void
    {
        $mgr = new ChannelManager();

        $alive = $this->createMock(ConnectionInterface::class);
        $alive->method('id')->willReturn('1');
        $alive->method('isConnected')->willReturn(true);
        $alive->expects(self::once())->method('send');

        $dead = $this->createMock(ConnectionInterface::class);
        $dead->method('id')->willReturn('2');
        $dead->method('isConnected')->willReturn(false);
        $dead->expects(self::never())->method('send');

        $mgr->subscribe('room', $alive);
        $mgr->subscribe('room', $dead);
        $mgr->broadcast('room', 'msg');
    }

    public function testBroadcastPrunesDisconnectedConnections(): void
    {
        $mgr = new ChannelManager();

        $dead = $this->createStub(ConnectionInterface::class);
        $dead->method('id')->willReturn('1');
        $dead->method('isConnected')->willReturn(false);

        $mgr->subscribe('room', $dead);
        $mgr->broadcast('room', 'msg');

        // Dead connection pruned, channel empty, removed
        self::assertNotContains('room', $mgr->channels());
    }

    public function testBroadcastToUnknownChannelIsNoOp(): void
    {
        $mgr = new ChannelManager();
        $mgr->broadcast('nonexistent', 'msg'); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testBroadcastToMultipleChannelsIsIndependent(): void
    {
        $mgr = new ChannelManager();

        $a = $this->createMock(ConnectionInterface::class);
        $a->method('id')->willReturn('1');
        $a->method('isConnected')->willReturn(true);
        $a->expects(self::once())->method('send')->with('msg-a');

        $b = $this->createMock(ConnectionInterface::class);
        $b->method('id')->willReturn('2');
        $b->method('isConnected')->willReturn(true);
        $b->expects(self::once())->method('send')->with('msg-b');

        $mgr->subscribe('chan-a', $a);
        $mgr->subscribe('chan-b', $b);

        $mgr->broadcast('chan-a', 'msg-a');
        $mgr->broadcast('chan-b', 'msg-b');
    }
}
