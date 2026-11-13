<?php

declare(strict_types=1);

namespace Webchat\WebSocket;

use RuntimeException;
use Throwable;
use Webchat\App\Config;
use Webchat\App\Database;
use Webchat\App\Token;
use Webchat\Gateway\GatewayException;
use Webchat\Gateway\OpenClawClient;
use Webchat\Repository\ChatRepository;

final class RelayServer
{
    private const PROTOCOL_VERSION = 1;
    private const MAX_CLIENTS_PER_PROCESS = 64;

    private ?ChatRepository $repository = null;
    /** @var array<string,string> */
    private array $activeRunText = [];

    public function __construct(private readonly Config $config)
    {
    }

    private function repository(): ChatRepository
    {
        // The relay forks one worker per browser connection. Open the PDO
        // connection after fork so workers never share a copied socket.
        return $this->repository ??= new ChatRepository(new Database($this->config));
    }

    public function run(): never
    {
        $host = $this->config->required('WEBCHAT_BIND_HOST');
        $port = $this->config->int('WEBCHAT_WS_PORT', 8081);
        $errno = 0;
        $error = '';
        $server = stream_socket_server("tcp://{$host}:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($server)) {
            throw new RuntimeException('Unable to bind WebSocket relay: ' . ($error !== '' ? $error : 'bind failed'));
        }

        stream_set_blocking($server, true);
        $children = 0;
        $this->log("Webchat relay listening on {$host}:{$port}");

        while (true) {
            $this->reapChildren($children);
            $client = @stream_socket_accept($server, 1);
            if (!is_resource($client)) {
                continue;
            }

            if ($children >= self::MAX_CLIENTS_PER_PROCESS) {
                fclose($client);
                continue;
            }

            if (!function_exists('pcntl_fork')) {
                $this->handleClient($client);
                continue;
            }

            $pid = pcntl_fork();
            if ($pid === -1) {
                fclose($client);
                $this->log('Unable to fork WebSocket client process');
                continue;
            }
            if ($pid > 0) {
                $children++;
                fclose($client);
                continue;
            }

            fclose($server);
            try {
                $this->handleClient($client);
            } catch (Throwable $exception) {
                $this->log('Client process ended: ' . $exception->getMessage());
            } finally {
                if (is_resource($client)) {
                    fclose($client);
                }
            }
            exit(0);
        }
    }

    private function handleClient($stream): void
    {
        $accepted = Connection::acceptServer($stream, $this->config->allowedOrigins());
        $browser = $accepted['connection'];
        $request = $accepted['request'];
        $expectedPath = (string) $this->config->get('WEBCHAT_PUBLIC_WS_PATH', '/webchat/ws');
        if (($request['path'] ?? '') !== $expectedPath) {
            $browser->close(1008);
            return;
        }

        $hello = $browser->nextJson(10.0);
        if (!is_array($hello) || ($hello['type'] ?? '') !== 'connect' || !is_string($hello['token'] ?? null)) {
            $browser->close(1008);
            return;
        }

        $claims = Token::verify($hello['token'], $this->config->required('APP_SECRET'));
        if ($claims === null || ($claims['kind'] ?? '') !== 'webchat' || !is_string($claims['session_id'] ?? null)) {
            $browser->close(1008);
            return;
        }

        $repository = $this->repository();
        $session = $repository->findSessionByPublicId($claims['session_id']);
        if ($session === null) {
            $browser->close(1008);
            return;
        }

        $gateway = new OpenClawClient($this->config);
        try {
            $gateway->connect();
            $history = $this->normalizeGatewayHistory($gateway->history((string) $session['gateway_session_key'], 50));

            $browser->sendJson([
                'type' => 'connected',
                'protocol_version' => self::PROTOCOL_VERSION,
                'conversation_id' => $session['conversation_public_id'],
            ]);
            $browser->sendJson(['type' => 'history', 'messages' => $history]);

            $this->relayLoop($browser, $gateway, $session);
        } catch (ConnectionClosed $exception) {
            if (!$browser->isClosed()) {
                try {
                    $this->sendError($browser, 'GATEWAY_UNAVAILABLE', 'El Gateway no está disponible.', null, true);
                } catch (Throwable) {
                    // The browser may have closed while reporting the failure.
                }
            }
        } catch (GatewayException $exception) {
            try {
                $this->sendError($browser, 'GATEWAY_UNAVAILABLE', 'El Gateway no está disponible.', null, true);
            } catch (Throwable) {
                // The browser may have closed while the Gateway was unavailable.
            }
        } catch (Throwable $exception) {
            $this->log('Relay error: ' . $exception->getMessage());
            try {
                $this->sendError($browser, 'INTERNAL_ERROR', 'No fue posible iniciar el webchat.', null, true);
            } catch (Throwable) {
                // The browser may have closed before the diagnostic was sent.
            }
        } finally {
            $gateway->close();
            $browser->close();
        }
    }

    /** @param array<string, mixed> $session */
    private function relayLoop(Connection $browser, OpenClawClient $gateway, array $session): void
    {
        $browserStream = $browser->stream();
        $gatewayStream = $gateway->stream();
        $lastPingAt = microtime(true);
        $active = true;

        while ($active) {
            $read = [$browserStream, $gatewayStream];
            $write = null;
            $except = null;
            $result = stream_select($read, $write, $except, 0, 250000);
            if ($result === false) {
                throw new ConnectionClosed('Relay select failed');
            }

            if ($result > 0) {
                if (in_array($browserStream, $read, true)) {
                    while (($message = $browser->nextJsonNonBlocking()) !== null) {
                        $active = $this->handleBrowserMessage($browser, $gateway, $session, $message);
                        if (!$active) {
                            break;
                        }
                    }
                }

                if ($active && in_array($gatewayStream, $read, true)) {
                    while (($event = $gateway->readEventNonBlocking()) !== null) {
                        $this->handleGatewayFrame($browser, $session, $event);
                    }
                }
            }

            foreach ($gateway->drainPendingEvents() as $event) {
                $this->handleGatewayFrame($browser, $session, $event);
            }

            if (microtime(true) - $lastPingAt >= 25.0) {
                $browser->sendPing('iaamx');
                $lastPingAt = microtime(true);
            }
        }
    }

    /** @param array<string, mixed> $session @param array<string, mixed> $message */
    private function handleBrowserMessage(Connection $browser, OpenClawClient $gateway, array $session, array $message): bool
    {
        $type = $message['type'] ?? '';
        if ($type === 'ping') {
            $browser->sendJson(['type' => 'pong']);
            return true;
        }
        if ($type === 'close') {
            return false;
        }
        if ($type !== 'message.create') {
            $this->sendError($browser, 'UNSUPPORTED_MESSAGE', 'Mensaje no soportado.');
            return true;
        }

        $requestId = isset($message['request_id']) && is_string($message['request_id']) ? trim($message['request_id']) : '';
        $content = isset($message['content']) && is_string($message['content']) ? trim($message['content']) : '';
        if ($requestId === '' || strlen($requestId) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $requestId)) {
            $this->sendError($browser, 'INVALID_REQUEST', 'El identificador de la solicitud no es válido.');
            return true;
        }
        if ($content === '') {
            $this->sendError($browser, 'EMPTY_MESSAGE', 'Escribe un mensaje antes de enviarlo.', $requestId);
            return true;
        }
        if (mb_strlen($content, 'UTF-8') > $this->config->int('WEBCHAT_MAX_MESSAGE_LENGTH', 4000)) {
            $this->sendError($browser, 'MESSAGE_TOO_LARGE', 'El mensaje supera el límite permitido.', $requestId);
            return true;
        }

        $repository = $this->repository();
        $conversationId = (int) $session['conversation_id'];
        $existing = $repository->findUserMessageByRequestId($conversationId, $requestId);
        if ($existing !== null) {
            $browser->sendJson([
                'type' => 'message.accepted',
                'request_id' => $requestId,
                'message_id' => $existing['public_id'],
                'run_id' => $existing['openclaw_run_id'] ?: null,
                'assistant_message_id' => $existing['assistant_public_id'] ?: null,
                'replayed' => true,
            ]);
            return true;
        }

        $userMessageId = $repository->insertUserMessage($conversationId, (int) $session['user_id'], $requestId, $content);
        $browser->sendJson(['type' => 'typing', 'on' => true]);
        try {
            $gatewayAck = $gateway->sendChat(
                (string) $session['gateway_session_key'],
                $content,
                'webchat:' . $session['conversation_public_id'] . ':' . $requestId,
                isset($session['agent_id']) ? (string) $session['agent_id'] : null,
            );
            $runId = is_string($gatewayAck['runId'] ?? null) ? $gatewayAck['runId'] : '';
            if ($runId === '') {
                throw new GatewayException('Gateway did not return a run id', 'INVALID_RESPONSE', null, true);
            }
            $run = $repository->createGatewayRun($conversationId, $userMessageId, $runId);
            $browser->sendJson([
                'type' => 'message.accepted',
                'request_id' => $requestId,
                'message_id' => $userMessageId,
                'run_id' => $runId,
                'assistant_message_id' => $run['assistant_public_id'],
            ]);
            return true;
        } catch (GatewayException $exception) {
            $repository->markUserMessageFailed($conversationId, $requestId);
            $this->sendError($browser, 'GATEWAY_ERROR', 'El Gateway rechazó el mensaje.', $requestId, $exception->retryable);
            return true;
        }
    }

    /** @param array<string, mixed> $session @param array<string, mixed> $frame */
    private function handleGatewayFrame(Connection $browser, array $session, array $frame): void
    {
        if (($frame['type'] ?? '') !== 'event' || ($frame['event'] ?? '') !== 'chat') {
            return;
        }

        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        if (($payload['sessionKey'] ?? '') !== ($session['gateway_session_key'] ?? '')) {
            return;
        }
        $runId = is_string($payload['runId'] ?? null) ? $payload['runId'] : '';
        if ($runId === '') {
            return;
        }
        $repository = $this->repository();
        $run = $repository->findRunByOpenClawId((int) $session['conversation_id'], $runId);
        if ($run === null) {
            return;
        }

        $state = (string) ($payload['state'] ?? '');
        if ($state === 'status') {
            $browser->sendJson(['type' => 'typing', 'on' => true, 'run_id' => $runId]);
            return;
        }
        if ($state === 'delta') {
            $deltaText = $this->extractText($payload['deltaText'] ?? '');
            $snapshotText = $this->extractText($payload['message'] ?? '');
            $replace = (bool) ($payload['replace'] ?? false);
            if ($snapshotText !== '') {
                $text = $snapshotText;
                $replace = true;
            } elseif ($replace) {
                $text = $deltaText;
            } else {
                $text = ($this->activeRunText[$runId] ?? '') . $deltaText;
                $replace = true;
            }
            $this->activeRunText[$runId] = $text;
            $browser->sendJson([
                'type' => 'message.delta',
                'run_id' => $runId,
                'message_id' => $run['assistant_public_id'],
                'text' => $text,
                'replace' => $replace,
            ]);
            return;
        }
        if ($state === 'final') {
            $text = $this->extractText($payload['message'] ?? '');
            if ($text === '') {
                $text = $this->activeRunText[$runId] ?? '';
            }
            $this->activeRunText[$runId] = $text;
            $repository->finishRun((int) $run['id'], 'completed');
            $browser->sendJson([
                'type' => 'message.final',
                'run_id' => $runId,
                'message_id' => $run['assistant_public_id'],
                'text' => $text,
            ]);
            $browser->sendJson(['type' => 'typing', 'on' => false, 'run_id' => $runId]);
            return;
        }
        if (in_array($state, ['error', 'aborted'], true)) {
            $errorMessage = is_string($payload['errorMessage'] ?? null) ? $payload['errorMessage'] : 'La respuesta fue interrumpida.';
            $this->activeRunText[$runId] = $errorMessage;
            $repository->finishRun((int) $run['id'], $state, strtoupper($state), $errorMessage);
            $this->sendError($browser, 'AGENT_' . strtoupper($state), $errorMessage, null, true, $runId);
            $browser->sendJson(['type' => 'typing', 'on' => false, 'run_id' => $runId]);
        }
    }

    private function sendError(Connection $browser, string $code, string $message, ?string $requestId = null, bool $retryable = false, ?string $runId = null): void
    {
        $browser->sendJson([
            'type' => 'error',
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
            'run_id' => $runId,
            'retryable' => $retryable,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeGatewayHistory(array $history): array
    {
        $messages = [];
        foreach (($history['messages'] ?? []) as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = in_array($message['role'] ?? '', ['assistant', 'user'], true) ? $message['role'] : null;
            if ($role === null) {
                continue;
            }
            $text = $this->extractText($message['content'] ?? '');
            if ($text === '') {
                continue;
            }
            $messages[] = [
                'id' => 'gateway-' . $index,
                'role' => $role,
                'text' => $text,
                'status' => 'completed',
            ];
        }
        return $messages;
    }

    private function extractText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (is_string($value['text'] ?? null)) {
            return $value['text'];
        }
        if (is_string($value['content'] ?? null)) {
            return $value['content'];
        }
        $parts = [];
        foreach ($value['content'] ?? $value as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }
        return implode('', $parts);
    }

    private function reapChildren(int &$children): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            $children = max(0, $children - 1);
        }
    }

    private function log(string $message): void
    {
        fwrite(STDERR, '[webchat-relay] ' . $message . PHP_EOL);
    }
}
