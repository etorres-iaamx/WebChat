<?php

declare(strict_types=1);

namespace Webchat\WebSocket;

use RuntimeException;

final class Connection
{
    private string $buffer = '';
    private ?int $fragmentOpcode = null;
    private string $fragmentPayload = '';
    private bool $closed = false;

    private function __construct(private $stream, private readonly bool $maskOutgoing, private readonly bool $expectMasked, private readonly int $maxPayload = 1048576)
    {
        stream_set_blocking($this->stream, false);
    }

    /** @param resource $stream @return array{connection:self, request:array<string, mixed>} */
    public static function acceptServer($stream, array $allowedOrigins = []): array
    {
        stream_set_blocking($stream, true);
        $head = self::readHttpHeaders($stream, 16384);
        [$requestLine, $headers] = self::parseHttpHeaders($head);
        $parts = explode(' ', $requestLine, 3);
        if (count($parts) !== 3 || $parts[0] !== 'GET') {
            throw new RuntimeException('WebSocket handshake requires GET');
        }

        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connection = strtolower($headers['connection'] ?? '');
        $key = $headers['sec-websocket-key'] ?? '';
        if ($upgrade !== 'websocket' || !str_contains($connection, 'upgrade') || $key === '') {
            throw new RuntimeException('Invalid WebSocket handshake');
        }

        $origin = $headers['origin'] ?? '';
        if ($allowedOrigins !== [] && !in_array('*', $allowedOrigins, true) && !in_array($origin, $allowedOrigins, true)) {
            throw new RuntimeException('WebSocket origin is not allowed');
        }

        $accept = base64_encode(hash('sha1', trim($key) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        self::writeAll($stream, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

        $connectionObject = new self($stream, false, true);
        return [
            'connection' => $connectionObject,
            'request' => [
                'method' => $parts[0],
                'target' => $parts[1],
                'path' => (string) (parse_url($parts[1], PHP_URL_PATH) ?: '/'),
                'headers' => $headers,
            ],
        ];
    }

    public static function connectClient(string $url, bool $verifyTls = true): self
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true) || !isset($parts['host'])) {
            throw new RuntimeException('OPENCLAW_GATEWAY_URL must be ws:// or wss://');
        }

        $scheme = $parts['scheme'];
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $transport = $scheme === 'wss' ? 'tls' : 'tcp';
        $context = stream_context_create($scheme === 'wss' ? [
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
                'allow_self_signed' => !$verifyTls,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ] : []);

        $errno = 0;
        $error = '';
        $stream = stream_socket_client("{$transport}://{$host}:{$port}", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($stream)) {
            throw new RuntimeException('Could not connect to OpenClaw Gateway: ' . ($error !== '' ? $error : 'connection failed'));
        }

        stream_set_blocking($stream, true);
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . (($scheme === 'wss' && $port !== 443) || ($scheme === 'ws' && $port !== 80) ? ':' . $port : '');
        $request = "GET {$path} HTTP/1.1\r\nHost: {$hostHeader}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
        self::writeAll($stream, $request);
        $head = self::readHttpHeaders($stream, 16384);
        [$statusLine, $headers] = self::parseHttpHeaders($head);
        if (!str_contains($statusLine, ' 101 ') || !isset($headers['sec-websocket-accept'])) {
            throw new RuntimeException('OpenClaw Gateway rejected the WebSocket handshake');
        }
        $expected = base64_encode(hash('sha1', $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!hash_equals($expected, trim($headers['sec-websocket-accept']))) {
            throw new RuntimeException('Invalid Gateway WebSocket accept key');
        }

        return new self($stream, true, false);
    }

    public function stream()
    {
        return $this->stream;
    }

    public function sendText(string $text): void
    {
        $this->send(FrameCodec::TEXT, $text);
    }

    /** @param array<string, mixed> $payload */
    public function sendJson(array $payload): void
    {
        $this->sendText((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function sendPing(string $payload = ''): void
    {
        $this->send(FrameCodec::PING, substr($payload, 0, 125));
    }

    public function sendPong(string $payload = ''): void
    {
        $this->send(FrameCodec::PONG, substr($payload, 0, 125));
    }

    public function isClosed(): bool
    {
        return $this->closed || !is_resource($this->stream);
    }

    public function close(int $code = 1000): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->send(FrameCodec::CLOSE, pack('n', $code));
        } catch (RuntimeException) {
            // The peer may already be gone.
        }
        $this->closed = true;
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** @return array{opcode:int,payload:string}|null */
    public function nextMessage(?float $timeoutSeconds = null): ?array
    {
        while (true) {
            $frame = $this->readFrame($timeoutSeconds);
            if ($frame === null) {
                return null;
            }
            $opcode = $frame['opcode'];
            if ($opcode === FrameCodec::PING) {
                $this->sendPong($frame['payload']);
                continue;
            }
            if ($opcode === FrameCodec::PONG) {
                continue;
            }
            if ($opcode === FrameCodec::CLOSE) {
                $this->close();
                throw new ConnectionClosed('WebSocket peer closed the connection');
            }
            if ($opcode !== FrameCodec::TEXT && $opcode !== FrameCodec::BINARY) {
                continue;
            }
            return ['opcode' => $opcode, 'payload' => $frame['payload']];
        }
    }

    /** @return array{opcode:int,payload:string}|null */
    public function nextMessageNonBlocking(): ?array
    {
        while (true) {
            $frame = $this->readFrameNonBlocking();
            if ($frame === null) {
                return null;
            }
            $opcode = $frame['opcode'];
            if ($opcode === FrameCodec::PING) {
                $this->sendPong($frame['payload']);
                continue;
            }
            if ($opcode === FrameCodec::PONG) {
                continue;
            }
            if ($opcode === FrameCodec::CLOSE) {
                $this->close();
                throw new ConnectionClosed('WebSocket peer closed the connection');
            }
            if ($opcode !== FrameCodec::TEXT && $opcode !== FrameCodec::BINARY) {
                continue;
            }
            return ['opcode' => $opcode, 'payload' => $frame['payload']];
        }
    }

    /** @return array<string, mixed>|null */
    public function nextJson(?float $timeoutSeconds = null): ?array
    {
        $message = $this->nextMessage($timeoutSeconds);
        if ($message === null) {
            return null;
        }
        $decoded = json_decode($message['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed>|null */
    public function nextJsonNonBlocking(): ?array
    {
        $message = $this->nextMessageNonBlocking();
        if ($message === null) {
            return null;
        }
        $decoded = json_decode($message['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{fin:bool,opcode:int,masked:bool,payload:string}|null */
    private function readFrame(?float $timeoutSeconds): ?array
    {
        $deadline = $timeoutSeconds === null ? null : microtime(true) + max(0.0, $timeoutSeconds);
        while (true) {
            $frame = FrameCodec::decode($this->buffer, $this->expectMasked, $this->maxPayload);
            if ($frame !== null) {
                return $this->assembleFrame($frame);
            }
            $remaining = $deadline === null ? null : max(0.0, $deadline - microtime(true));
            if (!$this->waitForReadable($remaining)) {
                return null;
            }
            $this->readFromStream();
        }
    }

    /** @return array{fin:bool,opcode:int,masked:bool,payload:string}|null */
    private function readFrameNonBlocking(): ?array
    {
        if ($this->buffer === '') {
            $this->readFromStream();
        }
        $frame = FrameCodec::decode($this->buffer, $this->expectMasked, $this->maxPayload);
        return $frame === null ? null : $this->assembleFrame($frame);
    }

    /** @param array{fin:bool,opcode:int,masked:bool,payload:string} $frame @return array{opcode:int,payload:string}|null */
    private function assembleFrame(array $frame): ?array
    {
        if ($frame['opcode'] === FrameCodec::CONTINUATION) {
            if ($this->fragmentOpcode === null) {
                throw new RuntimeException('Unexpected WebSocket continuation frame');
            }
            $this->fragmentPayload .= $frame['payload'];
            if (!$frame['fin']) {
                return null;
            }
            $opcode = $this->fragmentOpcode;
            $payload = $this->fragmentPayload;
            $this->fragmentOpcode = null;
            $this->fragmentPayload = '';
            return ['opcode' => $opcode, 'payload' => $payload];
        }

        if (!$frame['fin']) {
            if ($frame['opcode'] !== FrameCodec::TEXT && $frame['opcode'] !== FrameCodec::BINARY) {
                throw new RuntimeException('Fragmented control frame');
            }
            $this->fragmentOpcode = $frame['opcode'];
            $this->fragmentPayload = $frame['payload'];
            return null;
        }

        return ['opcode' => $frame['opcode'], 'payload' => $frame['payload']];
    }

    private function readFromStream(): void
    {
        if (!is_resource($this->stream) || feof($this->stream)) {
            throw new ConnectionClosed('WebSocket stream ended');
        }
        $chunk = fread($this->stream, 65536);
        // A readable non-blocking socket that yields an empty chunk has been
        // closed by its peer. Treat it as EOF immediately; otherwise the relay
        // can spin forever on a CLOSE-WAIT socket after the Gateway disconnects.
        if ($chunk === false || $chunk === '' || feof($this->stream)) {
            throw new ConnectionClosed('WebSocket stream ended');
        }
        if ($chunk !== '') {
            $this->buffer .= $chunk;
        }
    }

    private function waitForReadable(?float $timeoutSeconds): bool
    {
        $read = [$this->stream];
        $write = null;
        $except = null;
        if ($timeoutSeconds === null) {
            $seconds = null;
            $microseconds = null;
        } else {
            $seconds = (int) floor($timeoutSeconds);
            $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
        }
        $result = stream_select($read, $write, $except, $seconds, $microseconds);
        if ($result === false) {
            throw new ConnectionClosed('WebSocket select failed');
        }
        return $result > 0;
    }

    private function send(int $opcode, string $payload): void
    {
        if ($this->isClosed()) {
            throw new ConnectionClosed('WebSocket is closed');
        }
        try {
            self::writeAll($this->stream, FrameCodec::encode($opcode, $payload, $this->maskOutgoing));
        } catch (ConnectionClosed $exception) {
            $this->closed = true;
            throw $exception;
        }
    }

    private static function readHttpHeaders($stream, int $maxBytes): string
    {
        $buffer = '';
        while (!str_contains($buffer, "\r\n\r\n")) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                throw new ConnectionClosed('HTTP handshake ended before headers');
            }
            $buffer .= $chunk;
            if (strlen($buffer) > $maxBytes) {
                throw new RuntimeException('HTTP handshake headers are too large');
            }
        }
        return $buffer;
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function parseHttpHeaders(string $head): array
    {
        [$requestLine, $rawHeaders] = explode("\r\n", $head, 2);
        $headers = [];
        foreach (explode("\r\n", trim($rawHeaders)) as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return [$requestLine, $headers];
    }

    private static function writeAll($stream, string $data): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            // Convert a peer disconnect into the typed exception below without
            // emitting a noisy PHP notice from the worker process.
            $result = @fwrite($stream, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new ConnectionClosed('WebSocket write failed');
            }
            $written += $result;
        }
    }
}
