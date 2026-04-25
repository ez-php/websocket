# ez-php/websocket

PHP 8.5 Fiber-based WebSocket server for the ez-php ecosystem.

Implements RFC 6455 from the ground up — no third-party WebSocket library needed.
Each connection runs in its own **Fiber**, allowing hundreds of concurrent clients
on a single PHP process without threads or async extensions.

Intentionally separate from `ez-php/broadcast` (SSE/event-bus); this module
provides bidirectional, low-latency real-time communication.

---

## Installation

```bash
composer require ez-php/websocket
```

No framework integration is needed — the server runs as a standalone long-lived process.

---

## Quick start

```php
<?php

use EzPhp\WebSocket\ChannelManager;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\Server;

class ChatHandler implements HandlerInterface
{
    public function __construct(private readonly ChannelManager $channels) {}

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->channels->subscribe('general', $conn);
        $this->channels->broadcast('general', "{$conn->id()} joined.");
    }

    public function onMessage(ConnectionInterface $conn, Frame $frame): void
    {
        $this->channels->broadcast('general', "[{$conn->id()}] {$frame->payload}");
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->channels->unsubscribeAll($conn);
        $this->channels->broadcast('general', "{$conn->id()} left.");
    }

    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
        error_log("WebSocket error [{$conn->id()}]: {$e->getMessage()}");
    }
}

$server = new Server('0.0.0.0', 8080);
$server->run(new ChatHandler(new ChannelManager()));
```

Start the server:

```bash
php chat-server.php
```

Connect from a browser:

```js
const ws = new WebSocket('ws://localhost:8080');
ws.onmessage = e => console.log(e.data);
ws.onopen    = () => ws.send('Hello!');
```

---

## Core classes

### Server

```php
$server = new Server(host: '0.0.0.0', port: 8080);
$server->run($handler); // blocks; handles SIGTERM externally
```

- Opens a TCP server socket via `stream_socket_server()`
- Spawns one `Fiber` per accepted connection
- Non-blocking `stream_select()` event loop resumes Fibers when sockets are readable
- Handles PING→PONG and CLOSE frames automatically

### ConnectionInterface / Connection

```php
$conn->id();            // unique string ID assigned by Server
$conn->isConnected();   // false after close() or peer disconnect
$conn->send('text');    // UTF-8 TEXT frame
$conn->sendBinary($b);  // BINARY frame
$conn->close('bye');    // clean close with status 1000
```

### Frame

Represents one RFC 6455 frame. Available in `HandlerInterface::onMessage()`:

```php
$frame->opcode;   // Opcode::TEXT | Opcode::BINARY
$frame->payload;  // decoded (unmasked) payload string
$frame->fin;      // true for complete (non-fragmented) messages
```

### ChannelManager

Named pub/sub channels over connected clients:

```php
$mgr->subscribe('room', $conn);
$mgr->unsubscribe('room', $conn);
$mgr->unsubscribeAll($conn);          // called in onClose()
$mgr->broadcast('room', 'message');   // sends TEXT frame to all connected subscribers
$mgr->connections('room');            // list<ConnectionInterface>
$mgr->channels();                     // list<string>
$mgr->count('room');                  // int
```

`broadcast()` silently prunes disconnected connections.

---

## Architecture notes

- **No message fragmentation reassembly**: continuation frames are silently ignored. Real-world clients send single-frame messages for chat/notifications; large binary transfers should be chunked at the application level.
- **No TLS (WSS)**: terminate TLS at a reverse proxy (nginx, Caddy) and use plain `ws://` internally.
- **No authentication**: verify cookies or tokens in `onOpen()` and call `$conn->close()` on failure.

---

## License

MIT
