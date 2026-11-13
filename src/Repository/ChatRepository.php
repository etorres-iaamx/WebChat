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

        // A browser can retain this technical cookie after logout, account
        // switching, or a recovered deployment. Never reuse a token that is
        // already owned by another user; rotate it before inserting the new
        // session to avoid a unique-key failure and cross-account reuse.
        $rotatedToken = null;
        if ($existing !== null && (int) $existing['user_id'] !== $userId) {
            $rotatedToken = Token::random(32);
            $tokenHash = Token::hash($rotatedToken);
        }

        return $this->database->transaction(function (PDO $pdo) use ($tokenHash, $config, $userId, $rotatedToken): array {
            $user = $pdo->prepare('SELECT u.id, u.public_id, u.display_name, la.public_id AS agent_public_id, rb.external_agent_id FROM users u LEFT JOIN logical_agents la ON la.user_id = u.id LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' WHERE u.id = :id AND u.status = \'active\' LIMIT 1');
            $user->execute(['id' => $userId]);
            $row = $user->fetch();
            if (!is_array($row)) throw new \RuntimeException('Authenticated user is not available.');
            $sessionPublicId = Uuid::v4();
            $conversationPublicId = Uuid::v4();
            $agentId = (string) ($row['external_agent_id'] ?? '');
            if ($agentId === '') {
                throw new \RuntimeException('El agente todavía está en provisioning.');
            }
            $gatewaySessionKey = $config->gatewaySessionKeyForAgent($agentId, $sessionPublicId);
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $config->int('WEBCHAT_SESSION_TTL', 2592000) . ' seconds')->format('Y-m-d H:i:s.u');
            $pdo->prepare('INSERT INTO conversations (public_id, gateway_session_key, title) VALUES (:public_id, :gateway_session_key, :title)')->execute(['public_id' => $conversationPublicId, 'gateway_session_key' => $gatewaySessionKey, 'title' => 'WebChat']);
            $conversationId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, role) VALUES (:conversation_id, :user_id, \'owner\')')->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
            $pdo->prepare('INSERT INTO web_sessions (public_id, token_hash, user_id, conversation_id, expires_at) VALUES (:public_id, :token_hash, :user_id, :conversation_id, :expires_at)')->execute(['public_id' => $sessionPublicId, 'token_hash' => $tokenHash, 'user_id' => $userId, 'conversation_id' => $conversationId, 'expires_at' => $expiresAt]);
            $session = ['id' => (int) $pdo->lastInsertId(), 'public_id' => $sessionPublicId, 'user_id' => $userId, 'conversation_id' => $conversationId, 'conversation_public_id' => $conversationPublicId, 'gateway_session_key' => $gatewaySessionKey, 'user_public_id' => $row['public_id'], 'display_name' => $row['display_name'], 'agent_id' => $agentId];
            if ($rotatedToken !== null) $session['rotated_token'] = $rotatedToken;
            return $session;
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
                   rb.external_agent_id AS agent_id
            FROM web_sessions ws
            INNER JOIN conversations c ON c.id = ws.conversation_id
            INNER JOIN users u ON u.id = ws.user_id
            LEFT JOIN logical_agents la ON la.user_id = u.id
            LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = 'openclaw'
            WHERE ws.public_id = :public_id
              AND ws.expires_at > UTC_TIMESTAMP(6)
            LIMIT 1
            SQL);
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findUserMessageByRequestId(int $conversationId, string $requestId): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT public_id, state AS status, openclaw_run_id, assistant_public_id
            FROM relay_requests
            WHERE conversation_id = :conversation_id
              AND client_request_id = :client_request_id
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
            INSERT INTO relay_requests (public_id, conversation_id, user_id, client_request_id, state)
            VALUES (:public_id, :conversation_id, :user_id, :client_request_id, 'accepted')
            SQL);
        $statement->execute([
            'public_id' => $publicId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'client_request_id' => $requestId,
        ]);
        return $publicId;
    }

    /** @return array{run_id:int, assistant_public_id:string} */
    public function createGatewayRun(int $conversationId, string $userMessagePublicId, string $openclawRunId): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($conversationId, $userMessagePublicId, $openclawRunId): array {
            $userStatement = $pdo->prepare('SELECT id FROM relay_requests WHERE public_id = :public_id AND conversation_id = :conversation_id LIMIT 1');
            $userStatement->execute(['public_id' => $userMessagePublicId, 'conversation_id' => $conversationId]);
            $userId = $userStatement->fetchColumn();
            if ($userId === false) {
                throw new \RuntimeException('User message not found');
            }

            $assistantPublicId = Uuid::v4();
            $pdo->prepare('UPDATE relay_requests SET openclaw_run_id = :run_id, assistant_public_id = :assistant_id, state = \'running\' WHERE id = :id')
                ->execute(['run_id' => $openclawRunId, 'assistant_id' => $assistantPublicId, 'id' => $userId]);
            return ['run_id' => (int) $userId, 'assistant_public_id' => $assistantPublicId];
        });
    }

    /** @return array<string, mixed>|null */
    public function findRunByOpenClawId(int $conversationId, string $openclawRunId): ?array
    {
        $statement = $this->database->pdo()->prepare(<<<'SQL'
            SELECT id, openclaw_run_id, state, public_id AS user_public_id,
                   assistant_public_id
            FROM relay_requests
            WHERE conversation_id = :conversation_id
              AND openclaw_run_id = :openclaw_run_id
            LIMIT 1
            SQL);
        $statement->execute(['conversation_id' => $conversationId, 'openclaw_run_id' => $openclawRunId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function finishRun(int $runId, string $state, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE relay_requests SET state = :state, error_code = :error_code, error_message = :error_message WHERE id = :id');
        $statement->execute([
            'state' => $state,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'id' => $runId,
        ]);
    }

    public function markUserMessageFailed(int $conversationId, string $requestId): void
    {
        $this->database->pdo()->prepare('UPDATE relay_requests SET state = \'failed\' WHERE conversation_id = :conversation_id AND client_request_id = :client_request_id')
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
