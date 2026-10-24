<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Connection;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\ConnectionLifecycle;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebSocket\MessageTooBigException;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\PerMessageDeflate;
use EzPhp\WebSocket\PerMessageDeflateConfig;
use EzPhp\WebSocket\WebSocketException;
use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PerMessageDeflate::class)]
#[CoversClass(PerMessageDeflateConfig::class)]
#[CoversClass(MessageTooBigException::class)]
#[UsesClass(Connection::class)]
#[UsesClass(ConnectionLifecycle::class)]
#[UsesClass(Frame::class)]
final class WebSocketPerMessageDeflateTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messages = new \ArrayObject();
    }

    private static string $readBuffer = '';

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function offers(): iterable
    {
        yield 'plain offer' => ['permessage-deflate', 'permessage-deflate'];
        yield 'browser offer' => ['permessage-deflate; client_max_window_bits', 'permessage-deflate'];
        yield 'server window bits echoed' => ['permessage-deflate; server_max_window_bits=10', 'permessage-deflate; server_max_window_bits=10'];
        yield 'context takeover flags echoed' => ['permessage-deflate; server_no_context_takeover; client_no_context_takeover', 'permessage-deflate; server_no_context_takeover; client_no_context_takeover'];
        yield 'first acceptable offer wins' => ['x-webkit-deflate-frame, permessage-deflate; server_max_window_bits=8, permessage-deflate; client_max_window_bits=12', 'permessage-deflate'];
        yield 'quoted value' => ['permessage-deflate; server_max_window_bits="12"', 'permessage-deflate; server_max_window_bits=12'];
        yield 'unknown parameter declined' => ['permessage-deflate; foo', null];
        yield 'duplicated parameter declined' => ['permessage-deflate; client_max_window_bits; client_max_window_bits', null];
        yield 'flag with value declined' => ['permessage-deflate; server_no_context_takeover=1', null];
        yield 'server window bits without value declined' => ['permessage-deflate; server_max_window_bits', null];
        yield 'out of range declined' => ['permessage-deflate; client_max_window_bits=16', null];
        yield 'no offer' => ['', null];
    }

    #[DataProvider('offers')]
    public function testServerNegotiation(string $offer, ?string $expected): void
    {
        self::assertSame($expected, PerMessageDeflate::accept($offer, new PerMessageDeflateConfig())?->responseHeader);
    }

    public function testServerConfigForcesNoContextTakeover(): void
    {
        $agreement = PerMessageDeflate::accept('permessage-deflate', new PerMessageDeflateConfig(serverNoContextTakeover: true, clientNoContextTakeover: true));

        self::assertSame('permessage-deflate; server_no_context_takeover; client_no_context_takeover', $agreement?->responseHeader);
    }

    public function testClientOfferAndResponse(): void
    {
        $config = new PerMessageDeflateConfig(clientNoContextTakeover: true);

        self::assertSame('permessage-deflate; client_no_context_takeover; client_max_window_bits', PerMessageDeflate::offer($config));
        self::assertSame('permessage-deflate', PerMessageDeflate::offer(new PerMessageDeflateConfig()) === 'permessage-deflate; client_max_window_bits' ? 'permessage-deflate' : 'x');

        $agreement = PerMessageDeflate::fromResponse('permessage-deflate; client_max_window_bits=10; server_max_window_bits=8', $config);
        self::assertSame('hello', PerMessageDeflate::accept('permessage-deflate', new PerMessageDeflateConfig())?->decompress($agreement->compress('hello'), 100));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badResponses(): iterable
    {
        yield 'other extension' => ['x-foo'];
        yield 'two extensions' => ['permessage-deflate, permessage-deflate'];
        yield 'unknown parameter' => ['permessage-deflate; foo'];
        yield 'client window 8' => ['permessage-deflate; client_max_window_bits=8'];
        yield 'client window without value' => ['permessage-deflate; client_max_window_bits'];
    }

    #[DataProvider('badResponses')]
    public function testClientRejectsInvalidResponses(string $response): void
    {
        $this->expectException(HandshakeException::class);

        PerMessageDeflate::fromResponse($response, new PerMessageDeflateConfig());
    }

    public function testRoundTripWithAndWithoutContextTakeover(): void
    {
        foreach ([false, true] as $noContextTakeover) {
            $sender = PerMessageDeflate::accept('permessage-deflate', new PerMessageDeflateConfig(serverNoContextTakeover: $noContextTakeover));
            $receiver = PerMessageDeflate::fromResponse('permessage-deflate', new PerMessageDeflateConfig());
            self::assertNotNull($sender);

            $payload = str_repeat('{"event":"tick","data":42}', 50);
            $first = $sender->compress($payload);
            $second = $sender->compress($payload);

            self::assertLessThan(strlen($payload), strlen($first));
            self::assertFalse(str_ends_with($first, "\x00\x00\xff\xff"));
            self::assertSame($payload, $receiver->decompress($first, 1 << 20));
            self::assertSame($payload, $receiver->decompress($second, 1 << 20));
            self::assertSame('', $receiver->decompress($sender->compress(''), 10));

            // With context takeover the repeat refers back to the first message and is tiny.
            if (!$noContextTakeover) {
                self::assertLessThan(strlen($first), strlen($second));
            } else {
                self::assertSame(strlen($first), strlen($second));
            }
        }
    }

    public function testDecompressionIsLimitedOnTheInflatedSize(): void
    {
        $sender = PerMessageDeflate::fromResponse('permessage-deflate', new PerMessageDeflateConfig());
        $bomb = $sender->compress(str_repeat("\0", 2_000_000));
        $receiver = PerMessageDeflate::fromResponse('permessage-deflate', new PerMessageDeflateConfig());

        self::assertLessThan(5000, strlen($bomb));

        $this->expectException(MessageTooBigException::class);
        $receiver->decompress($bomb, 100_000);
    }

    public function testInvalidDeflateDataThrows(): void
    {
        $this->expectException(WebSocketException::class);

        PerMessageDeflate::fromResponse('permessage-deflate', new PerMessageDeflateConfig())->decompress("\xff\xff\xff\xff", 100);
    }

    public function testInvalidLevelIsRejected(): void
    {
        $this->expectException(WebSocketException::class);

        new PerMessageDeflateConfig(level: 10);
    }

    // ── Lifecycle over a socket pair ────────────────────────────────────────────

    /**
     * @return array{resource, Fiber<mixed, mixed, mixed, mixed>, string}
     */
    private function connect(?PerMessageDeflateConfig $config, string $offer, int $maxMessageBytes = 1 << 20): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$peer, $serverSide] = $pair;
        stream_set_blocking($serverSide, false);
        stream_set_blocking($peer, false);

        $handler = new class ($this->messages) implements HandlerInterface {
            /**
             * @param \ArrayObject<int, string> $messages
             */
            public function __construct(private readonly \ArrayObject $messages)
            {
            }

            public function onOpen(ConnectionInterface $conn): void
            {
            }

            public function onMessage(ConnectionInterface $conn, Frame $frame): void
            {
                $this->messages[] = $frame->payload;
                $conn->send('echo:' . $frame->payload);
            }

            public function onClose(ConnectionInterface $conn): void
            {
            }

            public function onError(ConnectionInterface $conn, \Throwable $e): void
            {
                $this->messages[] = 'error:' . $e->getMessage();
            }
        };

        $conn = new Connection($serverSide, '1', [], $config);
        $fiber = new Fiber(static function () use ($conn, $handler, $maxMessageBytes): void {
            (new ConnectionLifecycle($maxMessageBytes))->run($conn, $handler);
        });
        $fiber->start();
        self::$readBuffer = '';

        fwrite($peer, "GET / HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n"
            . ($offer !== '' ? "Sec-WebSocket-Extensions: {$offer}\r\n" : '') . "\r\n");
        $fiber->resume();

        return [$peer, $fiber, (string) fread($peer, 4096)];
    }

    private static function clientFrame(Opcode $opcode, string $payload, bool $fin = true, bool $rsv1 = false): string
    {
        $mask = "\x01\x02\x03\x04";
        $length = strlen($payload);
        $header = chr(($fin ? 0x80 : 0) | ($rsv1 ? 0x40 : 0) | $opcode->value);
        $header .= $length <= 125 ? chr(0x80 | $length) : ($length <= 65535 ? chr(0x80 | 126) . pack('n', $length) : chr(0x80 | 127) . pack('J', $length));

        return $header . $mask . ($payload ^ str_repeat($mask, intdiv($length, 4) + 1));
    }

    /**
     * @param resource $peer
     */
    private static function serverFrame(mixed $peer): Frame
    {
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            if (($frame = Frame::parse(self::$readBuffer)) !== null) {
                return $frame;
            }

            self::$readBuffer .= (string) fread($peer, 65536);
        }

        self::fail('No frame from the server.');
    }

    public function testCompressedMessagesAreInflatedAndRepliesCompressed(): void
    {
        [$peer, $fiber, $head] = $this->connect(new PerMessageDeflateConfig(), 'permessage-deflate; client_max_window_bits');
        self::assertStringContainsString("Sec-WebSocket-Extensions: permessage-deflate\r\n", $head);

        $client = PerMessageDeflate::fromResponse('permessage-deflate', new PerMessageDeflateConfig());
        $compressed = $client->compress('hello compressed world');

        // Fragmented: RSV1 on the first frame only.
        fwrite($peer, self::clientFrame(Opcode::TEXT, substr($compressed, 0, 5), fin: false, rsv1: true));
        fwrite($peer, self::clientFrame(Opcode::CONTINUATION, substr($compressed, 5)));
        fwrite($peer, self::clientFrame(Opcode::TEXT, 'plain'));
        $fiber->resume();

        self::assertSame(['hello compressed world', 'plain'], $this->messages->getArrayCopy());

        $reply = self::serverFrame($peer);
        self::assertTrue($reply->rsv1);
        self::assertSame('echo:hello compressed world', $client->decompress($reply->payload, 1000));

        fwrite($peer, self::clientFrame(Opcode::PING, 'p'));
        $fiber->resume();
        self::serverFrame($peer); // second echo
        self::assertFalse(self::serverFrame($peer)->rsv1, 'control frames are never compressed');
    }

    public function testWithoutConfigTheOfferIsIgnoredAndRsv1Refused(): void
    {
        [$peer, $fiber, $head] = $this->connect(null, 'permessage-deflate');
        self::assertStringNotContainsString('Sec-WebSocket-Extensions', $head);

        fwrite($peer, self::clientFrame(Opcode::TEXT, 'x', rsv1: true));
        $fiber->resume();

        $close = self::serverFrame($peer);
        self::assertSame(Opcode::CLOSE, $close->opcode);
        self::assertSame(1002, unpack('n', $close->payload)[1] ?? null);
    }

    public function testRsv1OnAContinuationIsRefused(): void
    {
        [$peer, $fiber] = $this->connect(new PerMessageDeflateConfig(), 'permessage-deflate');

        fwrite($peer, self::clientFrame(Opcode::TEXT, 'a', fin: false));
        fwrite($peer, self::clientFrame(Opcode::CONTINUATION, 'b', rsv1: true));
        $fiber->resume();

        self::assertSame(1002, unpack('n', self::serverFrame($peer)->payload)[1] ?? null);
    }

    public function testInflatedSizeOverTheLimitClosesWith1009(): void
    {
        [$peer, $fiber] = $this->connect(new PerMessageDeflateConfig(), 'permessage-deflate', maxMessageBytes: 10_000);
        $client = PerMessageDeflate::fromResponse('permessage-deflate', new PerMessageDeflateConfig());

        fwrite($peer, self::clientFrame(Opcode::BINARY, $client->compress(str_repeat('a', 50_000)), rsv1: true));
        $fiber->resume();

        self::assertSame(1009, unpack('n', self::serverFrame($peer)->payload)[1] ?? null);
        self::assertSame([], $this->messages->getArrayCopy());
    }

    public function testUndecodableDataClosesWith1007(): void
    {
        [$peer, $fiber] = $this->connect(new PerMessageDeflateConfig(), 'permessage-deflate');

        fwrite($peer, self::clientFrame(Opcode::BINARY, "\xff\xff\xff\xff", rsv1: true));
        $fiber->resume();

        self::assertSame(1007, unpack('n', self::serverFrame($peer)->payload)[1] ?? null);
    }

    public function testRsv2IsAProtocolViolation(): void
    {
        $buffer = chr(0x80 | 0x20 | Opcode::TEXT->value) . chr(0);

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessage('RSV2/RSV3');

        Frame::parse($buffer);
    }
}
