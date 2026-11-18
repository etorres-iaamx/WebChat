<?php

declare(strict_types=1);

namespace Webchat\Channels;

use RuntimeException;
use Webchat\App\Config;
use Webchat\App\Database;
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
        $statement = $this->database->pdo()->prepare('SELECT ci.user_id, ci.channel, ci.external_identity, u.public_id AS user_public_id, la.public_id AS agent_public_id, rb.external_agent_id FROM channel_identities ci INNER JOIN users u ON u.id = ci.user_id INNER JOIN logical_agents la ON la.user_id = u.id INNER JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' AND rb.status = \'active\' AND rb.external_agent_id IS NOT NULL AND rb.external_agent_id <> \'\' WHERE ci.channel = :channel AND ci.external_identity = :identity AND ci.status = \'paired\' AND u.status = \'active\' LIMIT 1');
        $statement->execute(['channel' => $channel, 'identity' => trim($externalIdentity)]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function state(string $channel, string $externalIdentity): ?string
    {
        $this->assertChannel($channel);
        $statement = $this->database->pdo()->prepare(
            'SELECT status FROM channel_identities WHERE channel = :channel AND external_identity = :identity LIMIT 1'
        );
        $statement->execute(['channel' => $channel, 'identity' => trim($externalIdentity)]);
        $status = $statement->fetchColumn();
        return is_string($status) && $status !== '' ? $status : null;
    }

    public function link(int $userId, string $channel, string $externalIdentity): void
    {
        $this->assertChannel($channel);
        $externalIdentity = trim($externalIdentity);
        if ($externalIdentity === '' || strlen($externalIdentity) > 255) {
            throw new RuntimeException('La identidad del canal no es válida.');
        }
        $this->database->transaction(function ($pdo) use ($userId, $channel, $externalIdentity): void {
            $existing = $pdo->prepare('SELECT user_id FROM channel_identities WHERE channel = :channel AND external_identity = :identity FOR UPDATE');
            $existing->execute(['channel' => $channel, 'identity' => $externalIdentity]);
            $ownerId = $existing->fetchColumn();
            if ($ownerId !== false && (int) $ownerId !== $userId) {
                throw new RuntimeException('Esta identidad de canal ya está vinculada a otra cuenta.');
            }

            $statement = $pdo->prepare('INSERT INTO channel_identities (user_id, channel, external_identity, status) VALUES (:user_id, :channel, :identity, \'binding_pending\') ON DUPLICATE KEY UPDATE status = \'binding_pending\'');
            $statement->execute(['user_id' => $userId, 'channel' => $channel, 'identity' => $externalIdentity]);
        });

        $agent = $this->database->pdo()->prepare('SELECT rb.external_agent_id FROM logical_agents la INNER JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' AND rb.status = \'active\' WHERE la.user_id = :user_id LIMIT 1');
        $agent->execute(['user_id' => $userId]);
        $agentId = $agent->fetchColumn();
        if (!is_string($agentId) || $agentId === '') {
            throw new RuntimeException('El agente OpenClaw todavía no está provisionado.');
        }
        try {
            (new OpenClawBindingManager($this->config))->bindPeer($agentId, $channel, $externalIdentity);
        } catch (\Throwable $error) {
            throw new RuntimeException('No fue posible completar el binding del canal en OpenClaw.', 0, $error);
        }
        $this->database->pdo()->prepare('UPDATE channel_identities SET status = \'paired\' WHERE user_id = :user_id AND channel = :channel AND external_identity = :identity')->execute(['user_id' => $userId, 'channel' => $channel, 'identity' => $externalIdentity]);
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado.');
        }
    }
}
