<?php

declare(strict_types=1);

namespace Webchat\Channels;

use RuntimeException;
use Webchat\App\Database;
use Webchat\App\Token;
use Webchat\App\Uuid;

final class ChannelIdentityService
{
    public function __construct(private readonly Database $database)
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
        $statement = $this->database->pdo()->prepare('SELECT * FROM pairing_attempts WHERE public_id = :public_id AND user_id = :user_id AND status = \'pending\' AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute(['public_id' => $pairingId, 'user_id' => $userId]);
        $attempt = $statement->fetch();
        if (!is_array($attempt) || !hash_equals((string) $attempt['challenge_hash'], Token::hash(trim($challenge)))) throw new RuntimeException('El código de pairing no es válido.');
        $this->database->transaction(function ($pdo) use ($attempt, $userId): void {
            $pdo->prepare('INSERT INTO channel_identities (user_id, channel, external_identity, status) VALUES (:user_id, :channel, :identity, \'paired\') ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), status = \'paired\'')->execute(['user_id' => $userId, 'channel' => $attempt['channel'], 'identity' => $attempt['external_identity']]);
            $pdo->prepare('UPDATE pairing_attempts SET status = \'confirmed\', confirmed_at = UTC_TIMESTAMP(6) WHERE id = :id')->execute(['id' => $attempt['id']]);
        });
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) throw new RuntimeException('Canal no soportado.');
    }
}
