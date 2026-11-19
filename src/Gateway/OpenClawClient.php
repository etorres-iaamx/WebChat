<?php

declare(strict_types=1);

namespace Webchat\Gateway;

use RuntimeException;
use Webchat\App\Config;
use Webchat\App\Uuid;
use Webchat\WebSocket\Connection;
use Webchat\WebSocket\ConnectionClosed;

final class OpenClawClient
{
    /** @var list<array<string, mixed>> */
    private array $pendingEvents = [];
    private ?Connection $connection = null;
    /** @var array<string, mixed>|null */
    private ?array $hello = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function connect(): void
    {
        $this->connection = Connection::connectClient(
            $this->config->required('OPENCLAW_GATEWAY_URL'),
            $this->config->bool('OPENCLAW_GATEWAY_TLS_VERIFY', true),
        );

        // Consume the pre-connect challenge. Trusted loopback backend clients do not
        // need a device signature, but the challenge still precedes the connect frame.
        $this->connection->nextJson(5.0);

        $requestId = Uuid::v4();
        $this->connection->sendJson([
            'type' => 'req',
            'id' => $requestId,
            'method' => 'connect',
            'params' => [
                'minProtocol' => 4,
                'maxProtocol' => 4,
                'client' => [
                    'id' => 'gateway-client',
                    'version' => '0.1.0',
                    'platform' => 'php',
                    'mode' => 'backend',
                ],
                'role' => 'operator',
                'scopes' => ['operator.read', 'operator.write'],
                'caps' => [],
                'commands' => [],
                'permissions' => new \stdClass(),
                'auth' => ['token' => $this->config->required('OPENCLAW_GATEWAY_TOKEN')],
                'locale' => 'es-MX',
                'userAgent' => 'webchat-iaamx/0.1.0',
            ],
        ]);

        $response = $this->waitForResponse($requestId, 10.0);
        if (($response['ok'] ?? false) !== true) {
            throw $this->gatewayError($response['error'] ?? null, 'Gateway handshake failed');
        }
        $this->hello = is_array($response['payload'] ?? null) ? $response['payload'] : [];
    }

    /** @return array<string, mixed> */
    public function history(string $sessionKey, int $limit = 50): array
    {
        return $this->request('chat.history', [
            'sessionKey' => $sessionKey,
            'limit' => max(1, min(100, $limit)),
            'maxChars' => 20000,
        ]);
    }

    /** @return array<string, mixed> */
    public function sendChat(string $sessionKey, string $message, string $idempotencyKey, ?string $agentId = null): array
    {
        $resolvedAgentId = trim((string) $agentId);
        if ($resolvedAgentId === '') {
            throw new RuntimeException('La sesión de WebChat no tiene un agente propio provisionado.');
        }
        return $this->request('chat.send', [
            'sessionKey' => $sessionKey,
            // Never fall back to main/Xochitl: a missing user agent must fail
            // closed instead of leaking the WebChat conversation into Xochitl.
            'agentId' => $resolvedAgentId,
            'message' => $message,
            'idempotencyKey' => $idempotencyKey,
            'deliver' => false,
            'timeoutMs' => 0,
        ]);
    }

    public function stream()
    {
        if ($this->connection === null) {
            throw new RuntimeException('Gateway client is not connected');
        }
        return $this->connection->stream();
    }

    public function sendPing(string $payload = 'webchat'): void
    {
        if ($this->connection === null) {
            throw new RuntimeException('Gateway client is not connected');
        }
        $this->connection->sendPing($payload);
    }

    /** @return list<array<string, mixed>> */
    public function drainPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];
        return $events;
    }

    /** @return array<string, mixed>|null */
    public function readEventNonBlocking(): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $frame = $this->connection->nextJsonNonBlocking();
        if ($frame === null) {
            return null;
        }
        return $frame;
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    /** @return array<string, mixed> */
    private function request(string $method, array $params): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Gateway client is not connected');
        }

        $requestId = Uuid::v4();
        $this->connection->sendJson([
            'type' => 'req',
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ]);

        $response = $this->waitForResponse($requestId, 20.0);
        if (($response['ok'] ?? false) !== true) {
            throw $this->gatewayError($response['error'] ?? null, $method . ' failed');
        }
        return is_array($response['payload'] ?? null) ? $response['payload'] : [];
    }

    /** @return array<string, mixed> */
    private function waitForResponse(string $requestId, float $timeout): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('Gateway client is not connected');
        }

        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $frame = $this->connection->nextJson(max(0.05, $deadline - microtime(true)));
            if ($frame === null) {
                continue;
            }
            if (($frame['type'] ?? '') === 'res' && ($frame['id'] ?? '') === $requestId) {
                return $frame;
            }
            if (($frame['type'] ?? '') === 'event') {
                $this->pendingEvents[] = $frame;
            }
        }
        throw new GatewayException('OpenClaw Gateway request timed out', 'TIMEOUT', null, true);
    }

    private function gatewayError(mixed $error, string $fallback): GatewayException
    {
        $error = is_array($error) ? $error : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : $fallback;
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $details = is_array($error['details'] ?? null) ? $error['details'] : null;
        $retryable = ($error['retryable'] ?? false) === true;
        return new GatewayException($message, $code, $details, $retryable);
    }
}
