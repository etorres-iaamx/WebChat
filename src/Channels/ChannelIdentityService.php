<?php

declare(strict_types=1);

namespace Webchat\Channels;

use RuntimeException;
use Webchat\App\Config;
use Webchat\App\Database;
use Webchat\Runtime\OpenClawBindingManager;
use Webchat\Runtime\OpenClawPairingManager;

final class ChannelIdentityService
{
    public function __construct(private readonly Database $database, private readonly Config $config)
    {
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $channel, string $externalIdentity): ?array
    {
        $this->assertChannel($channel);
        $externalIdentity = $this->normalizeIdentity($channel, $externalIdentity);
        $statement = $this->database->pdo()->prepare('SELECT ci.user_id, ci.channel, ci.external_identity, u.public_id AS user_public_id, la.public_id AS agent_public_id, rb.external_agent_id FROM channel_identities ci INNER JOIN users u ON u.id = ci.user_id INNER JOIN logical_agents la ON la.user_id = u.id INNER JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' AND rb.status = \'active\' AND rb.external_agent_id IS NOT NULL AND rb.external_agent_id <> \'\' WHERE ci.channel = :channel AND ci.external_identity = :identity AND ci.status = \'paired\' AND u.status = \'active\' LIMIT 1');
        $statement->execute(['channel' => $channel, 'identity' => trim($externalIdentity)]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function state(string $channel, string $externalIdentity): ?string
    {
        $this->assertChannel($channel);
        $externalIdentity = $this->normalizeIdentity($channel, $externalIdentity);
        $statement = $this->database->pdo()->prepare('SELECT status FROM channel_identities WHERE channel = :channel AND external_identity = :identity LIMIT 1');
        $statement->execute(['channel' => $channel, 'identity' => trim($externalIdentity)]);
        $status = $statement->fetchColumn();
        return is_string($status) && $status !== '' ? $status : null;
    }

    /** @return array<string,string> */
    public function statesForUser(int $userId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT channel, status FROM channel_identities WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $states = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) continue;
            $channel = (string) ($row['channel'] ?? '');
            $status = (string) ($row['status'] ?? '');
            if (in_array($channel, ['telegram', 'whatsapp'], true) && $status !== '') {
                $states[$channel] = $status;
            }
        }
        return $states;
    }

    public function reserve(int $userId, string $channel, string $externalIdentity): void
    {
        $this->assertChannel($channel);
        $externalIdentity = $this->normalizeIdentity($channel, $externalIdentity);
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
    }

    public function approvePairing(int $userId, string $channel, string $code): void
    {
        $this->assertChannel($channel);
        $agent = $this->database->pdo()->prepare('SELECT rb.external_agent_id FROM logical_agents la INNER JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' AND rb.status = \'active\' WHERE la.user_id = :user_id LIMIT 1');
        $agent->execute(['user_id' => $userId]);
        $agentId = $agent->fetchColumn();
        if (!is_string($agentId) || $agentId === '') {
            throw new RuntimeException('El agente OpenClaw todavía no está provisionado.');
        }

        $pairing = new OpenClawPairingManager($this->config);
        $request = $pairing->pendingRequestForCode($channel, $code);
        if ($request === null) throw new RuntimeException('El código no está pendiente o expiró.');
        $externalIdentity = $pairing->identityFromRequest($channel, $request);
        if ($externalIdentity === null) throw new RuntimeException('OpenClaw no devolvió la identidad del pairing.');

        // The Telegram/WhatsApp code is the only onboarding credential.
        $this->reserve($userId, $channel, $externalIdentity);
        $pairing->approve($channel, $code, $externalIdentity);

        try {
            (new OpenClawBindingManager($this->config))->bindPeer($agentId, $channel, $externalIdentity);
        } catch (\Throwable $error) {
            throw new RuntimeException('No fue posible completar el binding del canal en OpenClaw.', 0, $error);
        }
        $this->database->pdo()->prepare('UPDATE channel_identities SET status = \'paired\' WHERE user_id = :user_id AND channel = :channel AND external_identity = :identity')->execute(['user_id' => $userId, 'channel' => $channel, 'identity' => $externalIdentity]);
    }

    public function unpairUser(int $userId): void
    {
        $statement = $this->database->pdo()->prepare('SELECT channel, external_identity FROM channel_identities WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $identities = $statement->fetchAll();
        $bindingManager = new OpenClawBindingManager($this->config);
        foreach ($identities as $identity) {
            if (!is_array($identity)) continue;
            $channel = (string) ($identity['channel'] ?? '');
            $externalIdentity = (string) ($identity['external_identity'] ?? '');
            if (in_array($channel, ['telegram', 'whatsapp'], true) && $externalIdentity !== '') {
                $bindingManager->unbindPeer($channel, $externalIdentity);
            }
        }
        $this->database->pdo()->prepare('DELETE FROM channel_identities WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado.');
        }
    }

    private function normalizeIdentity(string $channel, string $identity): string
    {
        $identity = trim($identity);
        $identity = preg_replace('/^' . preg_quote(strtolower($channel), '/') . ':/i', '', $identity) ?? $identity;
        if (strtolower($channel) === 'whatsapp') {
            $identity = explode('@', $identity, 2)[0];
            if (preg_match('/^[0-9]{10,15}$/', $identity)) {
                $identity = '+' . $identity;
            }
        }
        return trim($identity);
    }
}
