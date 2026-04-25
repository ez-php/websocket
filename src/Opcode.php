<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * RFC 6455 WebSocket frame opcodes.
 *
 * Data frames:
 *   - CONTINUATION (0x0) — fragment continuation; not handled by this minimal implementation
 *   - TEXT          (0x1) — UTF-8 text payload
 *   - BINARY        (0x2) — arbitrary binary payload
 *
 * Control frames (must not be fragmented, payload ≤ 125 bytes):
 *   - CLOSE  (0x8) — initiate or acknowledge connection close
 *   - PING   (0x9) — keepalive probe; server must reply with PONG
 *   - PONG   (0xA) — keepalive response
 *
 * @package EzPhp\WebSocket
 */
enum Opcode: int
{
    case CONTINUATION = 0x0;
    case TEXT = 0x1;
    case BINARY = 0x2;
    case CLOSE = 0x8;
    case PING = 0x9;
    case PONG = 0xA;
}
