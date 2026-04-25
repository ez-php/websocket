<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * A single RFC 6455 WebSocket frame.
 *
 * Handles both parsing (client→server, masked) and encoding (server→client, unmasked).
 * Only single-frame (FIN=1) messages are produced by `encode()`; fragmented messages
 * received from clients are exposed as-is through `$fin`.
 *
 * @package EzPhp\WebSocket
 */
final class Frame
{
    /**
     * @param Opcode $opcode  Frame type
     * @param string $payload Decoded (unmasked) payload bytes
     * @param bool   $fin     Whether this is the final fragment (FIN bit)
     */
    public function __construct(
        public readonly Opcode $opcode,
        public readonly string $payload,
        public readonly bool $fin = true,
    ) {
    }

    /**
     * Parse one WebSocket frame from the front of `$buffer`.
     *
     * On success removes the consumed bytes from `$buffer` and returns the Frame.
     * Returns `null` when the buffer does not yet contain a complete frame.
     * Client-to-server frames are always masked; the mask is applied automatically.
     *
     * @param string $buffer Mutable read buffer (modified in-place on success)
     *
     * @throws WebSocketException on unknown opcode or protocol violation
     */
    public static function parse(string &$buffer): ?self
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);

        $fin = ($b0 & 0x80) !== 0;
        $opcodeInt = $b0 & 0x0F;
        $opcode = Opcode::tryFrom($opcodeInt);

        if ($opcode === null) {
            throw new WebSocketException("Unknown WebSocket opcode: 0x{$opcodeInt}.");
        }

        $masked = ($b1 & 0x80) !== 0;
        $payloadLen = $b1 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $payloadLen = (ord($buffer[2]) << 8) | ord($buffer[3]);
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $payloadLen = 0;
            for ($i = 2; $i < 10; $i++) {
                $payloadLen = ($payloadLen << 8) | ord($buffer[$i]);
            }
            $offset = 10;
        }

        $maskSize = $masked ? 4 : 0;
        $totalSize = $offset + $maskSize + $payloadLen;

        if (strlen($buffer) < $totalSize) {
            return null;
        }

        $payload = substr($buffer, $offset + $maskSize, $payloadLen);

        if ($masked) {
            $maskKey = substr($buffer, $offset, 4);
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= chr((ord($payload[$i]) ^ ord($maskKey[$i % 4])) & 0xFF);
            }
            $payload = $unmasked;
        }

        $buffer = substr($buffer, $totalSize);

        return new self($opcode, $payload, $fin);
    }

    /**
     * Encode this frame for transmission (server→client, never masked).
     *
     * Payload lengths 0–125 use the 1-byte length field;
     * 126–65535 use the 2-byte extended length;
     * larger payloads use the 8-byte extended length.
     */
    public function encode(): string
    {
        $b0 = ($this->fin ? 0x80 : 0x00) | $this->opcode->value;
        $len = strlen($this->payload);

        if ($len <= 125) {
            $header = chr($b0) . chr($len);
        } elseif ($len <= 65535) {
            $header = chr($b0) . chr(126)
                . chr(($len >> 8) & 0xFF)
                . chr($len & 0xFF);
        } else {
            $header = chr($b0) . chr(127)
                . chr(0) . chr(0) . chr(0) . chr(0)
                . chr(($len >> 24) & 0xFF)
                . chr(($len >> 16) & 0xFF)
                . chr(($len >> 8) & 0xFF)
                . chr($len & 0xFF);
        }

        return $header . $this->payload;
    }
}
