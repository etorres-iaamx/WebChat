<?php

declare(strict_types=1);

namespace Webchat\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Webchat\App\Config;
use Webchat\App\Database;
use Webchat\App\Token;
use Webchat\App\Uuid;

final class ChatRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string, mixed> */
    public function createOrRefreshVisitorSession(string $rawToken, Config $config): array
    {
        $tokenHash = Token::hash($rawToken);
        $existing = $this->findSessionByTokenHash($tokenHash);
        if ($existing !== null) {
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $config->int('WEBCHAT_SESSION_TTL', 2592000) . ' seconds')->format('Y-m-d H:i:s.u');
            $this->database->pdo()->prepare('UPDATE web_sessions SET last_seen_at = UTC_TIMESTAMP(6), expires_at = :expires_at WHERE id = :id')
                ->execute(['expires_at' => $expiresAt, 'id' => $existing['id']]);
            $existing['expires_at'] = $expiresAt;
            return $existing;
        }

        return $this->database->transaction(function (PDO $pdo) use ($tokenHash, $config): array {
            $sessionPublicId = Uuid::v4();
            $conversationPublicId = Uuid::v4();
            $userPublicId = Uuid::v4();
            $gatewaySessionKey = $config->gatewaySessionKey($sessionPublicId);
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $config->int('WEBCHAT_SESSION_TTL', 2592000) . ' seconds')->format('Y-m-d H:i:s.u');

            $pdo->prepare('INSERT INTO users (public_id, display_name) VALUES (:public_id, :display_name)')
                ->execute(['public_id' => $userPublicId, 'display_name' => (string) $config->get('WEBCHAT_VISITOR_NAME', 'Visitante IAAMX')]);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO conversations (public_id, gateway_session_key, title) VALUES (:public_id, :gateway_session_key, :title)')
                ->execute([
                    'public_id' => $conversationPublicId,
                    'gateway_session_key' => $gatewaySessionKey,
                    'title' => 'Webchat IAAMX',
                ]);
            $conversationId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, role) VALUES (:conversation_id, :user_id, :role)')
                ->execute(['conversation_id' => $conversationId, 'user_id' => $userId, 'role' => 'owner']);

            $pdo->prepare('INSERT INTO web_sessions (public_id, token_hash, user_id, conversation_id, expires_at) VALUES (:public_id, :token_hash, :user_id, :conversation_id, :expires_at)')
                ->execute([
                    'public_id' => $sessionPublicId,
                    'token_hash' => $tokenHash,
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                    'expires_at' => $expiresAt,
                ]);

            return [
                'id' => (int) $pdo->lastInsertId(),
                'public_id' => $sessionPublicId,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'conversation_public_id' => $conversationPublicId,
                'gateway_session_key' => $gatewaySessionKey,
                'user_public_id' => $userPublicId,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function createOrRefreshUserSession(string $rawToken, Config $config, int $userId): array
    {
        $tokenHash = Token::hash($rawToken);
        $existing = $this->findSessionByTokenHash($tokenHash);
        if ($existing !== null && (int) $existing['user_id'] === $userId) {
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $config->int('WEBCHAT_SESSION_TTL', 2592000) . ' seconds')->format('Y-m-d H:i:s.u');
            $this->database->pdo()->prepare('UPDATE web_sessions SET last_seen_at = UTC_TIMESTAMP(6), expires_at = :expires_at WHERE id = :id')->execute(['expires_at' => $expiresAt, 'id' => $existing['id']]);
            $existing['expires_at'] = $expiresAt;
            return $existing;
        }

        return $this->database->transaction(function (PDO $pdo) use ($tokenHash, $config, $userId): array {
            $user = $pdo->prepare('SELECT u.id, u.public_id, u.display_name, la.public_id AS agent_public_id, rb.external_agent_id FROM users u LEFT JOIN logical_agents la ON la.user_id = u.id LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' WHERE u.id = :id AND u.status = \'active\' LIMIT 1');
            $user->execute(['id' => $userId]);
            $row = $user->fetch();
            if (!is_array($row)) throw new \RuntimeException('Authenticated user is not available.');
            $sessionPublicId = Uuid::v4();
            $conversationPublicId = Uuid::v4();
            $agentId = (string) ($row['external_agent_id'] ?: $config->get('OPENCLAW_AGENT_ID', 'main'));
            $gatewaySessionKey = $config->gatewaySessionKeyForAgent($agentId, $sessionPublicId);
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $config->int('WEBCHAT_SESSION_TTL', 2592000) . ' seconds')->format('Y-m-d H:i:s.u');
            $pdo->prepare('INSERT INTO conversations (public_id, gateway_session_key, title) VALUES (:public_id, :gateway_session_key, :title)')->execute(['public_id' => $conversationPublicId, 'gateway_session_key' => $gatewaySessionKey, 'title' => 'WebChat']);
            $conversationId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, role) VALUES (:conversation_id, :user_id, \'owner\')')->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
            $pdo->prepare('INSERT INTO web_sessions (public_id, token_hash, user_id, conversation_id, expires_at) VALUES (:public_id, :token_hash, :user_id, :conversation_id, :expires_at)')->execute(['public_id' => $sessionPublicId, 'token_hash' => $tokenHash, 'user_id' => $userId, 'conversation_id' => $conversationId, 'expires_at' => $expiresAt]);
            return ['id' => (int) $pdo->lastInsertId(), 'public_id' => $sessionPublicId, 'user_id' => $userId, 'conversation_id' => $conversationId, 'conversation_public_id' => $conversationPublicId, 'gateway_session_key' => $gatewaySessionKey, 'user_public_id' => $row['public_id'], 'display_name' => $row['display_name'], 'agent_id' => $agentId];
        });
    }

    /** @return array<string, mixed>|null */
    public function findSessionByPublicId(string $publicId): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT ws.id, ws.public_id, ws.user_id, ws.conversation_id,
                   c.public_id AS conversation_public_id,
                   c.gateway_session_key,
                   u.public_id AS user_public_id,
                   u.display_name,
                   COALESCE(rb.external_agent_id, :default_agent) AS agent_id
            FROM web_sessions ws
            INNER JOIN conversations c ON c.id = ws.conversation_id
            INNER JOIN users u ON u.id = ws.user_id
            LEFT JOIN logical_agents la ON la.user_id = u.id
            LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = 'openclaw'
            WHERE ws.public_id = :public_id
              AND ws.expires_at > UTC_TIMESTAMP(6)
            LIMIT 1
            SQL);
        $statement->execute(['public_id' => $publicId, 'default_agent' => 'main']);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $conversationId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->database->pdo()->prepare("SELECT public_id, sender_type, content, status, created_at, updated_at FROM messages WHERE conversation_id = :conversation_id ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $statement->execute(['conversation_id' => $conversationId]);
        $rows = array_reverse($statement->fetchAll());
        return array_map(static fn (array $row): array => [
            'id' => $row['public_id'],
            'role' => $row['sender_type'] === 'assistant' ? 'assistant' : 'user',
            'text' => (string) $row['content'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findUserMessageByRequestId(int $conversationId, string $requestId): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT m.public_id, m.status, gr.openclaw_run_id, am.public_id AS assistant_public_id
            FROM messages m
            LEFT JOIN gateway_runs gr ON gr.user_message_id = m.id
            LEFT JOIN messages am ON am.gateway_run_id = gr.id AND am.sender_type = 'assistant'
            WHERE m.conversation_id = :conversation_id
              AND m.client_request_id = :client_request_id
            LIMIT 1
            SQL);
        $statement->execute(['conversation_id' => $conversationId, 'client_request_id' => $requestId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function insertUserMessage(int $conversationId, int $userId, string $requestId, string $content): string
    {
        $publicId = Uuid::v4();
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            INSERT INTO messages (public_id, conversation_id, sender_type, sender_user_id, client_request_id, content, status)
            VALUES (:public_id, :conversation_id, 'user', :sender_user_id, :client_request_id, :content, 'accepted')
            SQL);
        $statement->execute([
            'public_id' => $publicId,
            'conversation_id' => $conversationId,
            'sender_user_id' => $userId,
            'client_request_id' => $requestId,
            'content' => $content,
        ]);
        return $publicId;
    }

    /** @return array{run_id:int, assistant_public_id:string} */
    public function createGatewayRun(int $conversationId, string $userMessagePublicId, string $openclawRunId): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($conversationId, $userMessagePublicId, $openclawRunId): array {
            $userStatement = $pdo->prepare('SELECT id FROM messages WHERE public_id = :public_id AND conversation_id = :conversation_id LIMIT 1');
            $userStatement->execute(['public_id' => $userMessagePublicId, 'conversation_id' => $conversationId]);
            $userId = $userStatement->fetchColumn();
            if ($userId === false) {
                throw new \RuntimeException('User message not found');
            }

            $runPublicId = Uuid::v4();
            $pdo->prepare('INSERT INTO gateway_runs (public_id, conversation_id, user_message_id, openclaw_run_id, state, started_at) VALUES (:public_id, :conversation_id, :user_message_id, :openclaw_run_id, \'running\', UTC_TIMESTAMP(6))')
                ->execute([
                    'public_id' => $runPublicId,
                    'conversation_id' => $conversationId,
                    'user_message_id' => (int) $userId,
                    'openclaw_run_id' => $openclawRunId,
                ]);
            $runId = (int) $pdo->lastInsertId();

            $assistantPublicId = Uuid::v4();
            $pdo->prepare('INSERT INTO messages (public_id, conversation_id, sender_type, gateway_run_id, content, status) VALUES (:public_id, :conversation_id, \'assistant\', :gateway_run_id, \'\', \'streaming\')')
                ->execute([
                    'public_id' => $assistantPublicId,
                    'conversation_id' => $conversationId,
                    'gateway_run_id' => $runId,
                ]);
            $assistantId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE gateway_runs SET assistant_message_id = :assistant_message_id WHERE id = :id')
                ->execute(['assistant_message_id' => $assistantId, 'id' => $runId]);

            return ['run_id' => $runId, 'assistant_public_id' => $assistantPublicId];
        });
    }

    /** @return array<string, mixed>|null */
    public function findRunByOpenClawId(int $conversationId, string $openclawRunId): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT gr.id, gr.openclaw_run_id, gr.state,
                   um.public_id AS user_public_id,
                   am.public_id AS assistant_public_id,
                   am.content AS assistant_content
            FROM gateway_runs gr
            INNER JOIN messages um ON um.id = gr.user_message_id
            LEFT JOIN messages am ON am.id = gr.assistant_message_id
            WHERE gr.conversation_id = :conversation_id
              AND gr.openclaw_run_id = :openclaw_run_id
            LIMIT 1
            SQL);
        $statement->execute(['conversation_id' => $conversationId, 'openclaw_run_id' => $openclawRunId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function updateAssistantMessage(int $runId, string $content, string $status): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE messages SET content = :content, status = :status, updated_at = UTC_TIMESTAMP(6), completed_at = CASE WHEN :completed = 1 THEN UTC_TIMESTAMP(6) ELSE completed_at END WHERE gateway_run_id = :gateway_run_id AND sender_type = \'assistant\'');
        $statement->execute([
            'content' => $content,
            'status' => $status,
            'completed' => $status === 'completed' || $status === 'failed' ? 1 : 0,
            'gateway_run_id' => $runId,
        ]);
    }

    public function assistantContent(int $runId): string
    {
        $statement = $this->database->pdo()->prepare('SELECT content FROM messages WHERE gateway_run_id = :gateway_run_id AND sender_type = \'assistant\' LIMIT 1');
        $statement->execute(['gateway_run_id' => $runId]);
        $content = $statement->fetchColumn();
        return is_string($content) ? $content : '';
    }

    public function finishRun(int $runId, string $state, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE gateway_runs SET state = :state, error_code = :error_code, error_message = :error_message, finished_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = :id');
        $statement->execute([
            'state' => $state,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'id' => $runId,
        ]);
    }

    public function markUserMessageFailed(int $conversationId, string $requestId): void
    {
        $this->database->pdo()->prepare('UPDATE messages SET status = \'failed\', updated_at = UTC_TIMESTAMP(6) WHERE conversation_id = :conversation_id AND client_request_id = :client_request_id')
            ->execute(['conversation_id' => $conversationId, 'client_request_id' => $requestId]);
    }

    /** @return array<string, mixed>|null */
    private function findSessionByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT ws.id, ws.public_id, ws.user_id, ws.conversation_id,
                   c.public_id AS conversation_public_id,
                   c.gateway_session_key,
                   u.public_id AS user_public_id,
                   u.display_name
            FROM web_sessions ws
            INNER JOIN conversations c ON c.id = ws.conversation_id
            INNER JOIN users u ON u.id = ws.user_id
            WHERE ws.token_hash = :token_hash
              AND ws.expires_at > UTC_TIMESTAMP(6)
            LIMIT 1
            SQL);
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
