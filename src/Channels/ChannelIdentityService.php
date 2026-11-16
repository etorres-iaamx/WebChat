<?php

declare(strict_types=1);

namespace Webchat\Channels;

use RuntimeException;
use Webchat\App\Database;
use Webchat\App\Token;
use Webchat\App\Uuid;
use Webchat\App\Config;
use Webchat\Runtime\OpenClawBindingManager;

final class ChannelIdentityService
{
    public function __construct(private readonly Database $database, private readonly Config $config)
    {
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $channel, string $externalIdentity): ?array
    {
        $this->assertChannel($channel);
        $statement = $this->database->pdo()->prepare('SELECT ci.user_id, ci.channel, ci.external_identity, u.public_id AS user_public_id, la.public_id AS agent_public_id, rb.external_agent_id FROM channel_identities ci INNER JOIN users u ON u.id = ci.user_id INNER JOIN logical_agents la ON la.user_id = u.id LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' WHERE ci.channel = :channel AND ci.external_identity = :identity AND ci.status = \'paired\' AND u.status = \'active\' LIMIT 1');
        $statement->execute(['channel' => $channel, 'identity' => trim($externalIdentity)]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array{action:string,agent_id?:string} */
    public function resolveInbound(string $channel, string $externalIdentity, string $message = ''): array
    {
        $resolved = $this->resolve($channel, $externalIdentity);
        if ($resolved !== null) {
            return ['action' => 'route_to_agent', 'agent_id' => (string) ($resolved['external_agent_id'] ?? '')];
        }

        $message = trim($message);
        if ($message !== '') {
            $statement = $this->database->pdo()->prepare('SELECT * FROM pairing_attempts WHERE channel = :channel AND external_identity = :identity AND status IN (\'pending\', \'binding_pending\') AND expires_at > UTC_TIMESTAMP(6) ORDER BY id DESC LIMIT 1');
            $statement->execute(['channel' => $channel, 'identity' => trim($externalIdentity)]);
            $attempt = $statement->fetch();
            if (is_array($attempt) && hash_equals((string) $attempt['challenge_hash'], Token::hash($message))) {
                $this->completePairingAttempt($attempt);
                $resolved = $this->resolve($channel, $externalIdentity);
                return ['action' => 'route_to_agent', 'agent_id' => (string) ($resolved['external_agent_id'] ?? '')];
            }
        }

        return ['action' => 'register'];
    }

    /** @return array{pairing_id:string, challenge:string} */
    public function startPairing(int $userId, string $channel, string $externalIdentity): array
    {
        $this->assertChannel($channel);
        $externalIdentity = trim($externalIdentity);
        if ($externalIdentity === '' || strlen($externalIdentity) > 255) throw new RuntimeException('La identidad del canal no es válida.');
        if ($this->resolve($channel, $externalIdentity) !== null) throw new RuntimeException('Esta identidad ya está vinculada.');
        $challenge = (string) random_int(100000, 999999);
        $publicId = Uuid::v4();
        $this->database->pdo()->prepare('INSERT INTO pairing_attempts (public_id, user_id, channel, external_identity, challenge_hash, expires_at) VALUES (:public_id, :user_id, :channel, :identity, :challenge_hash, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE))')->execute(['public_id' => $publicId, 'user_id' => $userId, 'channel' => $channel, 'identity' => $externalIdentity, 'challenge_hash' => Token::hash($challenge)]);
        return ['pairing_id' => $publicId, 'challenge' => $challenge];
    }

    public function confirmPairing(int $userId, string $pairingId, string $challenge): void
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM pairing_attempts WHERE public_id = :public_id AND user_id = :user_id AND status IN (\'pending\', \'binding_pending\') AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute(['public_id' => $pairingId, 'user_id' => $userId]);
        $attempt = $statement->fetch();
        if (!is_array($attempt) || !hash_equals((string) $attempt['challenge_hash'], Token::hash(trim($challenge)))) throw new RuntimeException('El código de pairing no es válido.');
        $this->completePairingAttempt($attempt);
    }

    /** @param array<string,mixed> $attempt */
    private function completePairingAttempt(array $attempt): void
    {
        $agent = $this->database->pdo()->prepare('SELECT rb.external_agent_id FROM logical_agents la INNER JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' WHERE la.user_id = :user_id LIMIT 1');
        $agent->execute(['user_id' => $attempt['user_id']]);
        $agentId = $agent->fetchColumn();
        if (!is_string($agentId) || $agentId === '') throw new RuntimeException('El agente OpenClaw todavía no está provisionado.');
        $this->database->transaction(function ($pdo) use ($attempt): void {
            $pdo->prepare('INSERT INTO channel_identities (user_id, channel, external_identity, status) VALUES (:user_id, :channel, :identity, \'binding_pending\') ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), status = \'binding_pending\'')->execute(['user_id' => $attempt['user_id'], 'channel' => $attempt['channel'], 'identity' => $attempt['external_identity']]);
            $pdo->prepare('UPDATE pairing_attempts SET status = \'binding_pending\' WHERE id = :id')->execute(['id' => $attempt['id']]);
        });
        try {
            (new OpenClawBindingManager($this->config))->bindPeer($agentId, (string) $attempt['channel'], (string) $attempt['external_identity']);
        } catch (\Throwable $error) {
            throw new RuntimeException('No fue posible completar el binding del canal en OpenClaw.', 0, $error);
        }
        $this->database->transaction(function ($pdo) use ($attempt): void {
            $pdo->prepare('UPDATE channel_identities SET status = \'paired\' WHERE channel = :channel AND external_identity = :identity')->execute(['channel' => $attempt['channel'], 'identity' => $attempt['external_identity']]);
            $pdo->prepare('UPDATE pairing_attempts SET status = \'confirmed\', confirmed_at = UTC_TIMESTAMP(6) WHERE id = :id')->execute(['id' => $attempt['id']]);
        });
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) throw new RuntimeException('Canal no soportado.');
    }
}
