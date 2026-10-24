<?php

declare(strict_types=1);

namespace EzPhp\WebSocket;

/**
 * A message — after decompression, for permessage-deflate — exceeds the configured size limit.
 * Callers close the connection with status 1009.
 *
 * @package EzPhp\WebSocket
 */
final class MessageTooBigException extends WebSocketException
{
}
