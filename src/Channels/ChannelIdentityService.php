<?php

declare(strict_types=1);

namespace Webchat\Channels;

use RuntimeException;
use Webchat\App\Config;
use Webchat\App\Database;
use Webchat\Runtime\OpenClawBindingManager;
use Webchat\Runtime\OpenClawPairingManager;
use Webchat\Runtime\PairingEventBus;

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

    /** @return list<array<string,mixed>> */
    public function pendingForUser(int $userId): array
    {
        // Normal onboarding enters through binding_pending. The paired branch
        // is only for an explicit re-pair request after OpenClaw was reset.
        $statement = $this->database->pdo()->prepare('SELECT channel, external_identity, status FROM channel_identities WHERE user_id = :user_id AND status IN (\'binding_pending\', \'paired\') ORDER BY channel');
        $statement->execute(['user_id' => $userId]);
        $rows = array_values(array_filter($statement->fetchAll(), static fn (mixed $row): bool => is_array($row)));
        $pairing = new OpenClawPairingManager($this->config);

        // A WebChat identity can remain binding_pending before Telegram has
        // emitted a native code. Show the UI only while that code is live.
        return array_values(array_filter($rows, function (mixed $row) use ($pairing): bool {
            if (!is_array($row)) return false;
            try {
                if ($this->hasActivePairingState((string) ($row['channel'] ?? ''), (string) ($row['external_identity'] ?? ''))) {
                    return true;
                }
                return $pairing->hasPendingRequest((string) ($row['channel'] ?? ''), (string) ($row['external_identity'] ?? ''));
            } catch (\Throwable) {
                return false;
            }
        }));
    }

    public function recordPairingEvent(string $event, string $channel, string $accountId, string $externalIdentity, string $requestId): void
    {
        $this->assertChannel($channel);
        $event = trim($event);
        $accountId = trim($accountId) ?: 'default';
        $externalIdentity = trim($externalIdentity);
        $requestId = trim($requestId) ?: $externalIdentity;
        if (!in_array($event, ['pairing_created', 'pairing_approved', 'pairing_expired'], true)) throw new RuntimeException('Evento de pairing no válido.');
        if ($externalIdentity === '' || $requestId === '') throw new RuntimeException('Identidad de pairing no válida.');

        if ($event === 'pairing_created') {
            $pdo = $this->database->pdo();
            $pdo->beginTransaction();
            try {
                $state = $pdo->prepare(
                    'INSERT INTO pairing_state (channel, account_id, external_identity, request_id, status, expires_at, confirmed_at) VALUES (:channel, :account_id, :identity, :request_id, \'pending\', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR), NULL) ON DUPLICATE KEY UPDATE request_id = VALUES(request_id), status = \'pending\', expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR), confirmed_at = NULL'
                );
                $state->execute(['channel' => $channel, 'account_id' => $accountId, 'identity' => $externalIdentity, 'request_id' => $requestId]);
                // Close any previous active request for this identity before
                // creating the new one. The unique active key then guarantees
                // one pending pairing per channel/account/identity.
                $close = $pdo->prepare(
                    'UPDATE pairing_events SET status = \'superseded\', active_key = NULL, confirmed_at = UTC_TIMESTAMP(6) WHERE channel = :channel AND account_id = :account_id AND external_identity = :identity AND active_key = \'pending\''
                );
                $close->execute(['channel' => $channel, 'account_id' => $accountId, 'identity' => $externalIdentity]);

                $statement = $pdo->prepare(
                    'INSERT INTO pairing_events (channel, account_id, external_identity, request_id, status, active_key, expires_at) VALUES (:channel, :account_id, :identity, :request_id, \'pending\', \'pending\', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR)) ON DUPLICATE KEY UPDATE external_identity = VALUES(external_identity), status = \'pending\', active_key = \'pending\', expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR), confirmed_at = NULL'
                );
                $statement->execute(['channel' => $channel, 'account_id' => $accountId, 'identity' => $externalIdentity, 'request_id' => $requestId]);
                $pdo->commit();
            } catch (\Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
            (new PairingEventBus($this->config))->publish(['event' => $event, 'channel' => $channel, 'external_identity' => $externalIdentity]);
            return;
        }

        $status = $event === 'pairing_approved' ? 'approved' : 'expired';
        $state = $this->database->pdo()->prepare('UPDATE pairing_state SET status = :status, confirmed_at = UTC_TIMESTAMP(6) WHERE channel = :channel AND account_id = :account_id AND external_identity = :identity');
        $state->execute(['status' => $status, 'channel' => $channel, 'account_id' => $accountId, 'identity' => $externalIdentity]);
        $statement = $this->database->pdo()->prepare('UPDATE pairing_events SET status = :status, active_key = NULL, confirmed_at = UTC_TIMESTAMP(6) WHERE channel = :channel AND account_id = :account_id AND request_id = :request_id');
        $statement->execute(['status' => $status, 'channel' => $channel, 'account_id' => $accountId, 'request_id' => $requestId]);
        (new PairingEventBus($this->config))->publish(['event' => $event, 'channel' => $channel, 'external_identity' => $externalIdentity]);
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

    }

    public function approvePairing(int $userId, string $channel, string $code): void
    {
        $this->assertChannel($channel);
        $identity = $this->pendingIdentity($userId, $channel);
        if ($identity === null) throw new RuntimeException('No hay una identidad pendiente para este canal.');
        $externalIdentity = $identity;
        (new OpenClawPairingManager($this->config))->approve($channel, $code, $identity);

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
        $this->recordPairingEvent('pairing_approved', $channel, (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default'), $externalIdentity, $externalIdentity);
    }

    private function pendingIdentity(int $userId, string $channel): ?string
    {
        $statement = $this->database->pdo()->prepare('SELECT external_identity FROM channel_identities WHERE user_id = :user_id AND channel = :channel AND status IN (\'binding_pending\', \'paired\') LIMIT 1');
        $statement->execute(['user_id' => $userId, 'channel' => $channel]);
        $identity = $statement->fetchColumn();
        return is_string($identity) && $identity !== '' ? $identity : null;
    }

    private function hasActivePairingState(string $channel, string $externalIdentity): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT 1 FROM pairing_state WHERE channel = :channel AND external_identity = :identity AND status = \'pending\' AND expires_at > UTC_TIMESTAMP(6) LIMIT 1');
        $statement->execute(['channel' => $channel, 'identity' => $externalIdentity]);
        return $statement->fetchColumn() !== false;
    }

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado.');
        }
    }
}
