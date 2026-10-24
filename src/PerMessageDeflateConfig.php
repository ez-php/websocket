<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * Opt-in configuration for the permessage-deflate extension (RFC 7692).
 *
 * Passing an instance to `Server`/`TlsServer` (or the websocket-client's `Client`)
 * offers or accepts compression; omitting it keeps every message uncompressed.
 * Context takeover (reusing the LZ77 window across messages) compresses better
 * but keeps one zlib context of up to ~256 KiB per direction and connection
 * alive; the `*NoContextTakeover` flags trade ratio for memory.
 *
 * Requires ext-zlib.
 *
 * @package EzPhp\WebSocket
 */
final readonly class PerMessageDeflateConfig
{
    /**
     * @param bool $serverNoContextTakeover The server resets its compressor after every message.
     * @param bool $clientNoContextTakeover The client resets its compressor after every message.
     * @param int  $level                   zlib compression level, -1 (default) or 0–9.
     *
     * @throws WebSocketException When ext-zlib is missing or the level is out of range.
     */
    public function __construct(
        public bool $serverNoContextTakeover = false,
        public bool $clientNoContextTakeover = false,
        public int $level = -1,
    ) {
        if (!function_exists('deflate_init')) {
            throw new WebSocketException('permessage-deflate requires the zlib extension.');
        }

        if ($level < -1 || $level > 9) {
            throw new WebSocketException("Invalid zlib compression level {$level}; expected -1 to 9.");
        }
    }
}
