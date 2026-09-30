<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

use Fiber;

/**
 * Class ConnectionLifecycle
 *
 * One accepted connection from upgrade to close: handshake, `onOpen`, the frame
 * loop, `onClose`. Shared by `Server` and ez-php/websocket-tls's `TlsServer`, so
 * every transport applies the same RFC 6455 rules:
 *
 * - unmasked client frames close with 1002 (§5.1)
 * - fragmented messages are reassembled and delivered once (§5.4); a stray
 *   `CONTINUATION`, a new data frame inside an open message, or a fragmented
 *   control frame closes with 1002 (§5.4/§5.5)
 * - a message over `$maxMessageBytes` (one frame or the sum of its fragments)
 *   closes with 1009
 * - `PING` is answered, `CLOSE` closes, `PONG` is ignored
 *
 * Call run() inside a Fiber: it suspends while no data is available.
 *
 * @package EzPhp\WebSocket
 */
final readonly class ConnectionLifecycle
{
    /**
     * @param int $maxMessageBytes Largest message delivered to the handler.
     */
    public function __construct(private int $maxMessageBytes = Server::DEFAULT_MAX_MESSAGE_BYTES)
    {
    }

    /**
     * @param Connection       $conn
     * @param HandlerInterface $handler
     *
     * @return void
     */
    public function run(Connection $conn, HandlerInterface $handler): void
    {
        try {
            $conn->handshake();
        } catch (HandshakeException $e) {
            $handler->onError($conn, $e);

            return;
        }

        $handler->onOpen($conn);

        // A fragmented message in progress: its opcode and the payload so far.
        $fragmentOpcode = null;
        $fragments = '';

        try {
            while ($conn->isConnected()) {
                $frame = $conn->readFrame();

                if ($frame === null) {
                    Fiber::suspend();
                    continue;
                }

                if (!$frame->masked) {
                    $conn->closeWith(1002, 'Client frames must be masked.');
                    break;
                }

                if ($frame->opcode === Opcode::CLOSE || $frame->opcode === Opcode::PING || $frame->opcode === Opcode::PONG) {
                    // Control frames must not be fragmented; they may arrive between fragments.
                    if (!$frame->fin) {
                        $conn->closeWith(1002, 'Control frames must not be fragmented.');
                        break;
                    }

                    match ($frame->opcode) {
                        Opcode::CLOSE => $conn->close(),
                        Opcode::PING => $conn->sendPong($frame->payload),
                        default => null,
                    };

                    continue;
                }

                // A continuation needs an open message; a new data frame may not start inside one.
                if ($frame->opcode === Opcode::CONTINUATION ? $fragmentOpcode === null : $fragmentOpcode !== null) {
                    $conn->closeWith(1002, 'Unexpected frame in a fragmented message.');
                    break;
                }

                if (strlen($fragments) + strlen($frame->payload) > $this->maxMessageBytes) {
                    $conn->closeWith(1009, 'Message too big.');
                    break;
                }

                if (!$frame->fin) {
                    $fragmentOpcode ??= $frame->opcode;
                    $fragments .= $frame->payload;
                    continue;
                }

                if ($fragmentOpcode !== null) {
                    $frame = new Frame($fragmentOpcode, $fragments . $frame->payload, true, true);
                    $fragmentOpcode = null;
                    $fragments = '';
                }

                $handler->onMessage($conn, $frame);
            }
        } catch (\Throwable $e) {
            $handler->onError($conn, $e);
        } finally {
            $handler->onClose($conn);
        }
    }
}
