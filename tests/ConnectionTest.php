<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Connection;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebSocket\Opcode;

/**
 * Tests for Connection using UNIX stream socket pairs so no network is needed.
 *
 * Each test creates a pair of connected stream sockets:
 *   - $server: the socket passed to Connection (server side)
 *   - $client: simulates what a WebSocket client sends/receives
 *
 * @covers \EzPhp\WebSocket\Connection
 */
final class ConnectionTest extends TestCase
{
    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'stream_socket_pair() must succeed');
        /** @var array{resource, resource} $pair */
        return $pair;
    }

    private function upgradeRequest(string $key): string
    {
        return "GET / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
    }

    public function testIdReturnsConstructorValue(): void
    {
        [$server, $client] = $this->socketPair();
        $conn = new Connection($server, 'conn-42');
        self::assertSame('conn-42', $conn->id());
        fclose($client);
        fclose($server);
    }

    public function testIsConnectedIsFalseBeforeHandshake(): void
    {
        [$server, $client] = $this->socketPair();
        $conn = new Connection($server, '1');
        self::assertFalse($conn->isConnected());
        fclose($client);
        fclose($server);
    }

    public function testHandshakeSends101Response(): void
    {
        [$server, $client] = $this->socketPair();

        $key = base64_encode(random_bytes(16));
        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();

        self::assertTrue($conn->isConnected());

        $response = fread($client, 512);
        self::assertIsString($response);
        self::assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        self::assertStringContainsString('Upgrade: websocket', $response);
        self::assertStringContainsString('Sec-WebSocket-Accept:', $response);

        fclose($client);
    }

    public function testHandshakeComputesCorrectAcceptKey(): void
    {
        [$server, $client] = $this->socketPair();

        // RFC 6455 example values
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $expectedAccept = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';

        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();

        $response = fread($client, 512);
        self::assertIsString($response);
        self::assertStringContainsString("Sec-WebSocket-Accept: {$expectedAccept}", $response);

        fclose($client);
    }

    public function testHandshakeThrowsWhenKeyMissing(): void
    {
        [$server, $client] = $this->socketPair();

        // Send a request without Sec-WebSocket-Key
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $conn = new Connection($server, '1');

        $this->expectException(HandshakeException::class);
        $conn->handshake();

        fclose($client);
    }

    public function testSendWritesTextFrameToSocket(): void
    {
        [$server, $client] = $this->socketPair();

        $key = base64_encode(random_bytes(16));
        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();
        fread($client, 512); // consume the 101 response

        $conn->send('hello');

        $raw = fread($client, 64);
        self::assertIsString($raw);

        $frame = Frame::parse($raw);
        self::assertNotNull($frame);
        self::assertSame(Opcode::TEXT, $frame->opcode);
        self::assertSame('hello', $frame->payload);

        fclose($client);
    }

    public function testSendBinaryWritesBinaryFrame(): void
    {
        [$server, $client] = $this->socketPair();

        $key = base64_encode(random_bytes(16));
        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();
        fread($client, 512);

        $conn->sendBinary("\x01\x02\x03");

        $raw = fread($client, 64);
        self::assertIsString($raw);
        $frame = Frame::parse($raw);
        self::assertNotNull($frame);
        self::assertSame(Opcode::BINARY, $frame->opcode);
        self::assertSame("\x01\x02\x03", $frame->payload);

        fclose($client);
    }

    public function testReadFrameParsesIncomingFrame(): void
    {
        [$server, $client] = $this->socketPair();

        $key = base64_encode(random_bytes(16));
        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();
        fread($client, 512);

        // Write a masked TEXT frame from the "client"
        $mask = [0x37, 0xfa, 0x21, 0x3d];
        $payload = 'Hi!';
        $masked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $masked .= chr((ord($payload[$i]) ^ $mask[$i % 4]) & 0xFF);
        }
        $frame = chr(0x81) . chr(0x80 | strlen($payload))
            . chr($mask[0]) . chr($mask[1]) . chr($mask[2]) . chr($mask[3])
            . $masked;
        fwrite($client, $frame);

        $received = $conn->readFrame();
        self::assertNotNull($received);
        self::assertSame(Opcode::TEXT, $received->opcode);
        self::assertSame('Hi!', $received->payload);

        fclose($client);
    }

    public function testCloseMarksConnectionAsDisconnected(): void
    {
        [$server, $client] = $this->socketPair();

        $key = base64_encode(random_bytes(16));
        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();
        fread($client, 512);

        self::assertTrue($conn->isConnected());

        $conn->close();

        self::assertFalse($conn->isConnected());

        fclose($client);
    }

    public function testCloseIsIdempotent(): void
    {
        [$server, $client] = $this->socketPair();

        $key = base64_encode(random_bytes(16));
        fwrite($client, $this->upgradeRequest($key));

        $conn = new Connection($server, '1');
        $conn->handshake();
        fread($client, 512);

        $conn->close();
        $conn->close(); // must not throw

        self::assertFalse($conn->isConnected());

        fclose($client);
    }
}
