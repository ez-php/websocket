# Changelog

All notable changes to `ez-php/websocket` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.2.0] — 2026-03-28

### Added
- `Frame` — RFC 6455 WebSocket frame with `parse(string &$buffer): ?Frame` and `encode(): string`; supports continuation, text, binary, close, ping, and pong opcodes; handles extended 16-bit and 64-bit payload lengths; client-to-server masking
- `Opcode` — int-backed enum: `CONTINUATION`, `TEXT`, `BINARY`, `CLOSE`, `PING`, `PONG`
- `ConnectionInterface` — contract: `id()`, `isConnected()`, `send()`, `sendBinary()`, `close()`
- `Connection` — stream-socket implementation of `ConnectionInterface`; performs the HTTP→WebSocket handshake (RFC 6455 `Sec-WebSocket-Accept`), reads and writes frames, responds to PING with PONG automatically
- `HandlerInterface` — application lifecycle callbacks: `onOpen()`, `onMessage()`, `onClose()`, `onError()`
- `ChannelManager` — pub/sub over named channels: `subscribe()`, `unsubscribe()`, `broadcast()`
- `Server` — PHP 8.5 Fiber-based event loop using `stream_socket_server()` and `stream_select()`; each connection runs in its own Fiber; standalone with zero framework dependencies
- `WebSocketException` — base exception for all module errors
- `HandshakeException` — thrown when the HTTP→WebSocket upgrade fails (extends `WebSocketException`)
