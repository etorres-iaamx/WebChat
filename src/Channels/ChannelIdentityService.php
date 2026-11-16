<?php

declare(strict_types=1);

namespace Webchat\Channels;

use RuntimeException;
use Webchat\App\Database;

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

    public function link(int $userId, string $channel, string $externalIdentity): void
    {
        $this->assertChannel($channel);
        $externalIdentity = trim($externalIdentity);
        if ($externalIdentity === '' || strlen($externalIdentity) > 255) {
            throw new RuntimeException('La identidad del canal no es válida.');
        }
        $statement = $this->database->pdo()->prepare('INSERT INTO channel_identities (user_id, channel, external_identity, status) VALUES (:user_id, :channel, :identity, \'paired\') ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), status = \'paired\'');
        $statement->execute(['user_id' => $userId, 'channel' => $channel, 'identity' => $externalIdentity]);
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado.');
        }
    }
}
