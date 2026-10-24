<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

use DeflateContext;
use InflateContext;

/**
 * Class PerMessageDeflate
 *
 * The negotiated permessage-deflate extension (RFC 7692) for one connection:
 * extension negotiation for both ends and the per-message codec.
 *
 * - compress(): raw DEFLATE with a sync flush, the trailing `00 00 ff ff`
 *   removed (§7.2.1); the sender sets RSV1 on the message's first frame only.
 * - decompress(): appends the trailer back and inflates in small input slices,
 *   failing as soon as the output passes the size limit — a 16 MiB message
 *   limit must hold for the inflated size, not just the compressed bytes.
 * - Control frames are never compressed; that is the caller's job.
 *
 * Window size: our compressor honours the peer's `*_max_window_bits` (9–15; 8
 * is declined because zlib silently raises a raw-deflate window of 8 to 9). The
 * decompressor always uses a 15-bit window, which inflates any smaller one, so
 * we never need to ask the peer to limit its window.
 *
 * @package EzPhp\WebSocket
 */
final class PerMessageDeflate
{
    public const string EXTENSION = 'permessage-deflate';

    /**
     * Compressed input fed to inflate_add() per call. Deflate expands at most
     * ~1032:1, so the output can overshoot the limit by about 1 MiB before the
     * check stops it.
     */
    private const int INFLATE_SLICE_BYTES = 1024;

    private const string TAIL = "\x00\x00\xff\xff";

    private ?DeflateContext $deflater = null;

    private ?InflateContext $inflater = null;

    /**
     * @param bool $resetCompressor   Our side agreed to no context takeover: a fresh compressor per message.
     * @param int  $windowBits        Our compressor's window (9–15).
     * @param int  $level             zlib level.
     * @param string $responseHeader  The `Sec-WebSocket-Extensions` value describing the agreement.
     */
    private function __construct(
        private readonly bool $resetCompressor,
        private readonly int $windowBits,
        private readonly int $level,
        public readonly string $responseHeader,
    ) {
    }

    /**
     * Server side: accept the first acceptable permessage-deflate offer of a
     * `Sec-WebSocket-Extensions` request header. Offers with unknown or invalid
     * parameters are skipped (RFC 7692 §5). Null when none is acceptable.
     *
     * @param string                  $offers The request header value ('' when absent).
     * @param PerMessageDeflateConfig $config
     *
     * @return self|null
     */
    public static function accept(string $offers, PerMessageDeflateConfig $config): ?self
    {
        foreach (self::parse($offers) as [$name, $params]) {
            if ($name !== self::EXTENSION) {
                continue;
            }

            $windowBits = 15;
            $response = [self::EXTENSION];
            $valid = true;

            foreach ($params as $param => $value) {
                $valid = match ($param) {
                    'server_no_context_takeover', 'client_no_context_takeover' => $value === null,
                    'client_max_window_bits' => $value === null || self::isWindowBits($value, 8),
                    'server_max_window_bits' => $value !== null && self::isWindowBits($value, 9),
                    default => false,
                };

                if (!$valid) {
                    break;
                }

                if ($param === 'server_max_window_bits' && $value !== null) {
                    $windowBits = (int) $value;
                }
            }

            if (!$valid) {
                continue;
            }

            $resetCompressor = $config->serverNoContextTakeover || array_key_exists('server_no_context_takeover', $params);

            if ($resetCompressor) {
                $response[] = 'server_no_context_takeover';
            }

            if ($config->clientNoContextTakeover || array_key_exists('client_no_context_takeover', $params)) {
                $response[] = 'client_no_context_takeover';
            }

            if ($windowBits !== 15) {
                $response[] = "server_max_window_bits={$windowBits}";
            }

            return new self($resetCompressor, $windowBits, $config->level, implode('; ', $response));
        }

        return null;
    }

    /**
     * Client side: the `Sec-WebSocket-Extensions` request header value to offer.
     *
     * @param PerMessageDeflateConfig $config
     *
     * @return string
     */
    public static function offer(PerMessageDeflateConfig $config): string
    {
        return implode('; ', array_filter([
            self::EXTENSION,
            $config->serverNoContextTakeover ? 'server_no_context_takeover' : null,
            $config->clientNoContextTakeover ? 'client_no_context_takeover' : null,
            'client_max_window_bits',
        ]));
    }

    /**
     * Client side: the agreement from the server's `Sec-WebSocket-Extensions`
     * response header, which must name exactly permessage-deflate with valid parameters.
     *
     * @param string                  $response
     * @param PerMessageDeflateConfig $config   What was offered.
     *
     * @return self
     * @throws HandshakeException When the server answered with anything else.
     */
    public static function fromResponse(string $response, PerMessageDeflateConfig $config): self
    {
        $extensions = self::parse($response);

        if (count($extensions) !== 1 || $extensions[0][0] !== self::EXTENSION) {
            throw new HandshakeException("Server negotiated unsupported WebSocket extensions: {$response}");
        }

        $params = $extensions[0][1];
        $windowBits = 15;

        foreach ($params as $param => $value) {
            $valid = match ($param) {
                'server_no_context_takeover', 'client_no_context_takeover' => $value === null,
                'server_max_window_bits' => $value !== null && self::isWindowBits($value, 8),
                'client_max_window_bits' => $value !== null && self::isWindowBits($value, 9),
                default => false,
            };

            if (!$valid) {
                throw new HandshakeException("Server answered permessage-deflate with an invalid parameter: {$param}");
            }

            if ($param === 'client_max_window_bits' && $value !== null) {
                $windowBits = (int) $value;
            }
        }

        $resetCompressor = $config->clientNoContextTakeover || array_key_exists('client_no_context_takeover', $params);

        return new self($resetCompressor, $windowBits, $config->level, $response);
    }

    /**
     * Compress one message payload (without the trailing `00 00 ff ff`).
     *
     * @param string $payload
     *
     * @return string
     */
    public function compress(string $payload): string
    {
        if ($this->deflater === null || $this->resetCompressor) {
            $deflater = deflate_init(ZLIB_ENCODING_RAW, ['window' => $this->windowBits, 'level' => $this->level]);

            if ($deflater === false) {
                throw new WebSocketException('Could not initialise the permessage-deflate compressor.');
            }

            $this->deflater = $deflater;
        }

        $compressed = deflate_add($this->deflater, $payload, ZLIB_SYNC_FLUSH);

        if ($compressed === false) {
            throw new WebSocketException('permessage-deflate compression failed.');
        }

        return str_ends_with($compressed, self::TAIL) ? substr($compressed, 0, -4) : $compressed;
    }

    /**
     * Decompress one message payload, stopping once the output exceeds $maxBytes.
     *
     * @param string $payload  The reassembled compressed payload.
     * @param int    $maxBytes Largest inflated size accepted.
     *
     * @return string
     * @throws MessageTooBigException When the inflated message exceeds $maxBytes.
     * @throws WebSocketException     When the payload is not valid DEFLATE data.
     */
    public function decompress(string $payload, int $maxBytes): string
    {
        if ($this->inflater === null) {
            $inflater = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);

            if ($inflater === false) {
                throw new WebSocketException('Could not initialise the permessage-deflate decompressor.');
            }

            $this->inflater = $inflater;
        }

        $input = $payload . self::TAIL;
        $output = '';

        set_error_handler(static fn (): bool => true);

        try {
            foreach (str_split($input, self::INFLATE_SLICE_BYTES) as $slice) {
                $chunk = inflate_add($this->inflater, $slice, ZLIB_SYNC_FLUSH);

                if ($chunk === false) {
                    throw new WebSocketException('Invalid permessage-deflate data.');
                }

                $output .= $chunk;

                if (strlen($output) > $maxBytes) {
                    throw new MessageTooBigException('Decompressed message exceeds the maximum allowed size.');
                }
            }
        } finally {
            restore_error_handler();
        }

        return $output;
    }

    /**
     * Parse a `Sec-WebSocket-Extensions` value into [name, params] pairs; a
     * duplicated parameter makes the whole extension entry invalid (dropped).
     *
     * @param string $header
     *
     * @return list<array{string, array<string, string|null>}>
     */
    private static function parse(string $header): array
    {
        $extensions = [];

        foreach (explode(',', $header) as $entry) {
            $parts = array_map(trim(...), explode(';', $entry));
            $name = strtolower(array_shift($parts));

            if ($name === '') {
                continue;
            }

            $params = [];

            foreach ($parts as $part) {
                $pair = explode('=', $part, 2);
                $param = strtolower(trim($pair[0]));
                $value = $pair[1] ?? null;

                if ($param === '' || array_key_exists($param, $params)) {
                    continue 2;
                }

                $params[$param] = $value === null ? null : trim(trim($value), '"');
            }

            $extensions[] = [$name, $params];
        }

        return $extensions;
    }

    private static function isWindowBits(string $value, int $min): bool
    {
        return preg_match('/^\d{1,2}$/', $value) === 1 && (int) $value >= $min && (int) $value <= 15;
    }
}
