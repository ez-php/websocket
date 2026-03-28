<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Opcode;

/**
 * @covers \EzPhp\WebSocket\Opcode
 */
final class OpcodeTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame(0x0, Opcode::CONTINUATION->value);
        self::assertSame(0x1, Opcode::TEXT->value);
        self::assertSame(0x2, Opcode::BINARY->value);
        self::assertSame(0x8, Opcode::CLOSE->value);
        self::assertSame(0x9, Opcode::PING->value);
        self::assertSame(0xA, Opcode::PONG->value);
    }

    public function testFromInt(): void
    {
        self::assertSame(Opcode::TEXT, Opcode::from(1));
        self::assertSame(Opcode::BINARY, Opcode::from(2));
        self::assertSame(Opcode::CLOSE, Opcode::from(8));
        self::assertSame(Opcode::PING, Opcode::from(9));
        self::assertSame(Opcode::PONG, Opcode::from(10));
    }

    public function testTryFromReturnsNullForUnknown(): void
    {
        self::assertNull(Opcode::tryFrom(0x3));
        self::assertNull(Opcode::tryFrom(0x7));
        self::assertNull(Opcode::tryFrom(0xB));
        self::assertNull(Opcode::tryFrom(0xF));
    }
}
