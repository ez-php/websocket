<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\WebSocketException;

/**
 * @covers \EzPhp\WebSocket\Frame
 */
final class FrameTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // encode()
    // ──────────────────────────────────────────────────────────────

    public function testEncodeTextFrameShortPayload(): void
    {
        $frame = new Frame(Opcode::TEXT, 'hello');
        $encoded = $frame->encode();

        // Byte 0: FIN(1) + opcode TEXT(1) = 0x81
        self::assertSame(0x81, ord($encoded[0]));
        // Byte 1: no mask (server→client) + length 5
        self::assertSame(5, ord($encoded[1]));
        self::assertSame('hello', substr($encoded, 2));
    }

    public function testEncodeEmptyPayload(): void
    {
        $frame = new Frame(Opcode::TEXT, '');
        $encoded = $frame->encode();

        self::assertSame(0x81, ord($encoded[0]));
        self::assertSame(0, ord($encoded[1]));
        self::assertSame(2, strlen($encoded));
    }

    public function testEncodeBinaryFrame(): void
    {
        $frame = new Frame(Opcode::BINARY, "\x01\x02\x03");
        $encoded = $frame->encode();

        // Byte 0: FIN(1) + opcode BINARY(2) = 0x82
        self::assertSame(0x82, ord($encoded[0]));
        self::assertSame(3, ord($encoded[1]));
    }

    public function testEncodePingFrame(): void
    {
        $frame = new Frame(Opcode::PING, '');
        self::assertSame(0x89, ord($frame->encode()[0]));
    }

    public function testEncodePongFrame(): void
    {
        $frame = new Frame(Opcode::PONG, 'data');
        self::assertSame(0x8A, ord($frame->encode()[0]));
    }

    public function testEncodeCloseFrame(): void
    {
        $frame = new Frame(Opcode::CLOSE, pack('n', 1000));
        self::assertSame(0x88, ord($frame->encode()[0]));
    }

    public function testEncode126ByteExtendedLength(): void
    {
        $payload = str_repeat('x', 126);
        $frame = new Frame(Opcode::TEXT, $payload);
        $encoded = $frame->encode();

        self::assertSame(0x81, ord($encoded[0]));
        // Byte 1 = 126 signals 2-byte extended length
        self::assertSame(126, ord($encoded[1]));
        // Bytes 2-3: big-endian 16-bit length = 126
        self::assertSame(0, ord($encoded[2]));
        self::assertSame(126, ord($encoded[3]));
        self::assertSame($payload, substr($encoded, 4));
    }

    public function testEncodeFinBitClearedForFragment(): void
    {
        $frame = new Frame(Opcode::CONTINUATION, 'part', fin: false);
        $encoded = $frame->encode();

        // FIN bit clear: byte 0 = 0x00 | opcode(0) = 0x00
        self::assertSame(0x00, ord($encoded[0]));
    }

    // ──────────────────────────────────────────────────────────────
    // parse() — unmasked (server-to-server or test payloads)
    // ──────────────────────────────────────────────────────────────

    public function testParseRoundTrip(): void
    {
        $original = new Frame(Opcode::TEXT, 'hello world');
        $encoded = $original->encode();

        $frame = Frame::parse($encoded);

        self::assertNotNull($frame);
        self::assertSame(Opcode::TEXT, $frame->opcode);
        self::assertSame('hello world', $frame->payload);
        self::assertTrue($frame->fin);
        self::assertSame('', $encoded); // buffer fully consumed
    }

    public function testParseReturnsNullOnInsufficientData(): void
    {
        $buffer = "\x81"; // only 1 byte, need at least 2
        self::assertNull(Frame::parse($buffer));
        self::assertSame("\x81", $buffer); // buffer unchanged
    }

    public function testParseReturnsNullOnIncompletePayload(): void
    {
        // FIN+TEXT, length=10, but only 3 payload bytes present
        $buffer = "\x81\x0A" . 'abc';
        self::assertNull(Frame::parse($buffer));
        self::assertSame("\x81\x0A" . 'abc', $buffer);
    }

    public function testParseThrowsOnUnknownOpcode(): void
    {
        // Opcode 0x3 is reserved/unknown
        $buffer = "\x83\x00";
        $this->expectException(WebSocketException::class);
        Frame::parse($buffer);
    }

    public function testParseConsumesExactlyOneFrame(): void
    {
        $frame1 = new Frame(Opcode::TEXT, 'first');
        $frame2 = new Frame(Opcode::TEXT, 'second');
        $buffer = $frame1->encode() . $frame2->encode();

        $parsed1 = Frame::parse($buffer);
        self::assertNotNull($parsed1);
        self::assertSame('first', $parsed1->payload);

        $parsed2 = Frame::parse($buffer);
        self::assertNotNull($parsed2);
        self::assertSame('second', $parsed2->payload);

        self::assertSame('', $buffer);
    }

    // ──────────────────────────────────────────────────────────────
    // parse() — masked client frames (client→server)
    // ──────────────────────────────────────────────────────────────

    /**
     * Builds a masked WebSocket frame as a client would send it.
     *
     * @param array{int,int,int,int} $mask
     */
    private function buildMaskedFrame(Opcode $opcode, string $payload, array $mask): string
    {
        $b0 = 0x80 | $opcode->value; // FIN + opcode
        $len = strlen($payload);
        $b1 = 0x80 | $len;          // MASK bit + length (≤125 for test simplicity)

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr((ord($payload[$i]) ^ $mask[$i % 4]) & 0xFF);
        }

        return chr($b0 & 0xFF) . chr($b1 & 0xFF)
            . chr((int) $mask[0] & 0xFF) . chr((int) $mask[1] & 0xFF)
            . chr((int) $mask[2] & 0xFF) . chr((int) $mask[3] & 0xFF)
            . $masked;
    }

    public function testParseMaskedTextFrame(): void
    {
        $mask = [0x37, 0xfa, 0x21, 0x3d];
        $buffer = $this->buildMaskedFrame(Opcode::TEXT, 'Hello', $mask);

        $frame = Frame::parse($buffer);

        self::assertNotNull($frame);
        self::assertSame(Opcode::TEXT, $frame->opcode);
        self::assertSame('Hello', $frame->payload);
    }

    public function testParseMaskedEmptyPayload(): void
    {
        $b0 = chr(0x81); // FIN + TEXT
        $b1 = chr(0x80); // MASK bit + length 0
        $maskBytes = chr(0x01) . chr(0x02) . chr(0x03) . chr(0x04);
        $buffer = $b0 . $b1 . $maskBytes;

        $frame = Frame::parse($buffer);
        self::assertNotNull($frame);
        self::assertSame('', $frame->payload);
    }

    public function testParse126ExtendedLength(): void
    {
        $payload = str_repeat('A', 200);
        $len = strlen($payload);

        // Build 126-extended unmasked frame manually
        $b0 = chr(0x82); // FIN + BINARY
        $b1 = chr(126);  // no mask, extended length indicator
        $extLen = chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        $buffer = $b0 . $b1 . $extLen . $payload;

        $frame = Frame::parse($buffer);
        self::assertNotNull($frame);
        self::assertSame(Opcode::BINARY, $frame->opcode);
        self::assertSame(200, strlen($frame->payload));
    }

    public function testParseReturnsNullFor126WithInsufficientExtLenBytes(): void
    {
        $buffer = chr(0x82) . chr(126) . chr(0); // need 2 ext bytes, only 1 present
        self::assertNull(Frame::parse($buffer));
    }
}
