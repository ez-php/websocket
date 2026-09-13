# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum` and
`opcache` → `OPCache` are existing exceptions the guess gets wrong).

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| **next free** | **3311** | **6383** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project is the one exception, since it has no host/container split and uses `REDIS_PORT` for both.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/websocket

## Source structure

```
src/
├── WebSocketException.php    — base exception for the module
├── HandshakeException.php    — HTTP→WebSocket upgrade failure (extends WebSocketException)
├── Opcode.php                — int-backed enum: CONTINUATION, TEXT, BINARY, CLOSE, PING, PONG
├── Frame.php                 — RFC 6455 frame: parse(string &$buffer): ?Frame, encode(): string
├── ConnectionInterface.php   — id(), isConnected(), send(), sendBinary(), close(), requestHeaders(), header()
├── Connection.php            — stream-socket implementation; handshake(), readFrame(), sendPong()
├── HandlerInterface.php      — onOpen(), onMessage(), onClose(), onError() lifecycle callbacks
├── ChannelManager.php        — subscribe/unsubscribe/broadcast pub/sub on named channels
└── Server.php                — Fiber event loop; stream_socket_server + stream_select

tests/
├── TestCase.php
├── OpcodeTest.php            — enum values and tryFrom() for reserved opcodes
├── FrameTest.php             — encode, parse, masked client frames, extended lengths, round-trip
├── ConnectionTest.php        — handshake, send, readFrame, close — using UNIX socket pairs
├── ChannelManagerTest.php    — subscribe/unsubscribe/broadcast with PHPUnit mocks
└── ServerTest.php            — constructor, accessors, run() throws on port conflict
```

---

## Key classes and responsibilities

### WebSocketException / HandshakeException

`WebSocketException` is the base. `HandshakeException` is thrown when the HTTP upgrade
request is malformed or the connection closes before headers are complete.

---

### Opcode (`src/Opcode.php`)

Int-backed enum covering the six RFC 6455 opcodes used by this implementation.
`Opcode::tryFrom(int)` is used in `Frame::parse()` to detect reserved/unknown opcodes and
throw `WebSocketException`.

---

### Frame (`src/Frame.php`)

Two-method class:
- `static parse(string &$buffer): ?Frame` — reads one frame from the front of the buffer,
  unmasks the payload (client frames are always masked), removes consumed bytes from
  `$buffer`, returns `null` if the buffer is incomplete.
- `encode(): string` — produces the wire bytes for a server→client frame (never masked).

All byte arithmetic uses `ord()`/`chr()` instead of `pack()`/`unpack()` to avoid
`array|false` return types and keep PHPStan level 9 happy.

Extended payload lengths: 126 → 2-byte big-endian; 127 → 8-byte big-endian
(sufficient for payloads up to PHP_INT_MAX on 64-bit).

---

### ConnectionInterface (`src/ConnectionInterface.php`)

Minimal contract. `id()` returns the unique connection string; `isConnected()` tracks
whether the WebSocket connection is open. `send()` and `sendBinary()` write frames.
`close()` sends a close frame with status 1000. `requestHeaders()` returns the
upgrade-request headers (keyed by lowercased name) and `header($name)` looks one up
case-insensitively — populated by `handshake()`, these let `onOpen()` handlers inspect
`origin`, `cookie`, or auth headers and `close()` unwanted connections (e.g. Origin
checks against Cross-Site WebSocket Hijacking). The module performs no authorization
itself.

---

### Connection (`src/Connection.php`)

Stream-socket implementation. The constructor intentionally does **not** call
`stream_set_blocking()` — the `Server` sets the socket non-blocking before creating the
Connection so that tests can use blocking socket pairs without modification.

`handshake()` reads HTTP headers in a loop, suspending the current `Fiber` when no data
is yet available (checked with `Fiber::getCurrent() !== null`). Computes `Sec-WebSocket-Accept`
via `base64_encode(sha1($key . GUID, true))`. It also parses the request header block
(only up to the terminating `\r\n\r\n`) into a lowercased-key map exposed via
`requestHeaders()`/`header()`, so `onOpen()` handlers can perform Origin/cookie checks.

`readFrame()` does one non-blocking `fread()`, appends to an internal buffer, then
delegates to `Frame::parse()`. Returns `null` when no complete frame is present yet.

`socket()` exposes the raw resource to the `Server` for use with `stream_select()`.

---

### HandlerInterface (`src/HandlerInterface.php`)

Four lifecycle callbacks. `onMessage()` is only called for `TEXT` and `BINARY` frames;
control frames (`PING`, `PONG`, `CLOSE`, `CONTINUATION`) are handled internally by the
`Server` and never forwarded to the handler.

---

### ChannelManager (`src/ChannelManager.php`)

In-memory pub/sub: `array<string, array<string, ConnectionInterface>>` (channel → connId → conn).
`broadcast()` iterates subscribers, calls `$conn->send()` on connected ones, and prunes
disconnected entries automatically. Empty channels are deleted. `unsubscribeAll()` is
the recommended call in `HandlerInterface::onClose()`.

---

### Server (`src/Server.php`)

Event loop responsibilities:
1. `stream_socket_server("tcp://$host:$port")` — listen
2. `stream_set_blocking($serverSocket, false)` — non-blocking accept
3. Per iteration: build `$read` array (server socket + all connection sockets),
   call `stream_select($read, ...)` with a 50 ms timeout
4. New connection on server socket → `stream_set_blocking($clientSocket, false)`,
   create `Connection`, spawn `Fiber`, call `$fiber->start()`
5. Readable client socket → `$fiber->resume()`; reap terminated Fibers
6. `handleConnection()` runs inside the Fiber: handshake → `onOpen` → frame loop
   (`readFrame()` returns null → `Fiber::suspend()`) → `onClose`

Resource tracking uses `get_resource_id($socket)` as the array key for O(1) lookups
(`array<int, Connection>`, `array<int, Fiber>`, `array<int, resource>`).

---

## Design decisions and constraints

- **Standalone — zero package dependencies.** The WebSocket server runs as a long-lived
  CLI process, not inside an HTTP request lifecycle. There is no ServiceProvider; the
  developer creates a `Server` and calls `run()` directly. This avoids coupling to the
  framework container or HTTP stack.
- **One Fiber per connection.** PHP 8.1+ Fibers provide cooperative multitasking without
  OS threads. `stream_select()` acts as the I/O event demultiplexer; Fibers are suspended
  on no-data-yet conditions and resumed when the socket becomes readable.
- **No message fragmentation reassembly.** Continuation frames (`Opcode::CONTINUATION`)
  are silently ignored. Virtually all browser WebSocket clients send complete messages in
  a single frame. Reassembly adds significant state-machine complexity for a minimal module.
- **No TLS (WSS).** TLS termination belongs at the reverse proxy layer (nginx, Caddy).
  The server uses plain TCP; `wss://` is achieved with `ssl://` stream wrappers in a
  future extension.
- **Non-blocking mode controlled by Server, not Connection.** This separation allows
  test code to use blocking UNIX socket pairs (via `stream_socket_pair`) without
  interference from the blocking-mode setting.
- **`Fiber::getCurrent() !== null` guard.** `handshake()` calls `Fiber::suspend()` only
  when running inside a Fiber. In test contexts (blocking sockets with pre-written data),
  the guard prevents `FiberError` while keeping the method testable without spinning up
  the full Server.
- **`get_resource_id()` for O(1) socket lookup.** After `stream_select()`, matching a
  readable socket to its Connection and Fiber is O(1) via the resource integer ID.

---

## Testing approach

No external infrastructure required. All tests run in-process.

- `OpcodeTest` — enum int values, `Opcode::from()`, `tryFrom()` for reserved values
- `FrameTest` — encode/decode round-trips, masked client frames, 126-byte extended length,
  parse returning `null` for incomplete buffers, unknown opcode exception,
  consuming exactly one frame from a buffer containing multiple
- `ConnectionTest` — uses `stream_socket_pair(STREAM_PF_UNIX, ...)` for realistic I/O
  without a real network; tests handshake 101 response and RFC 6455 Accept computation,
  send/sendBinary frame encoding, readFrame parsing, close idempotency
- `ChannelManagerTest` — PHPUnit mocks for `ConnectionInterface`; subscribe, unsubscribe,
  unsubscribeAll, broadcast sends/skips/prunes, channel lifecycle
- `ServerTest` — constructor, accessors, `run()` throws `WebSocketException` on port conflict

Full integration tests (multiple concurrent WebSocket clients) require a separate test
process and are out of scope for this suite.

---

## What does not belong in this module

| Concern | Where it belongs |
|---------|-----------------|
| SSE / server-sent events | `ez-php/broadcast` |
| TLS / WSS | Reverse proxy or a future `ez-php/websocket-tls` extension |
| Message fragmentation reassembly | Application layer or future extension |
| Authentication / authorization | Application handler (`onOpen()`: inspect `header('origin')`/`header('cookie')`, then `$conn->close()` to reject) |
| Persistent connection state across restarts | External store (Redis, DB) |
| Push gateway / relay to external clients | Application layer |
| HTTP routing before upgrade | `ez-php/framework` router (inspect `requestHeaders()` in `onOpen()`) |
