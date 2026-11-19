<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class OpenClawPairingManager
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string,mixed> */
    public function approve(string $channel, string $code, ?string $expectedIdentity = null): array
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado para pairing.');
        }
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9]{6,12}$/', $code)) {
            throw new RuntimeException('El código de pairing no es válido.');
        }

        $account = (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default');
        $binary = (string) $this->config->get('OPENCLAW_BINARY', 'openclaw');
        $requests = $this->pendingRequests($channel);
        $request = null;
        foreach ($requests as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateCode = (string) ($candidate['code'] ?? $candidate['pairingCode'] ?? '');
            if (hash_equals($candidateCode, $code)) {
                $request = $candidate;
                break;
            }
        }
        if ($request === null) {
            throw new RuntimeException('El código no está pendiente o expiró.');
        }
        if ($expectedIdentity !== null && $expectedIdentity !== '') {
            $requestIdentities = array_filter([
                $request['externalIdentity'] ?? null,
                $request['from'] ?? null,
                $request['senderId'] ?? null,
            ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '');
            if ($requestIdentities !== [] && !in_array($expectedIdentity, array_map(static fn (mixed $value): string => trim((string) $value), $requestIdentities), true)) {
                throw new RuntimeException('El código pertenece a otra identidad de canal.');
            }
        }

        $result = LocalCommand::run([$binary, 'pairing', 'approve', $channel, $code, '--account', $account]);
        if ($result['returncode'] !== 0) {
            throw new RuntimeException('OpenClaw rechazó el código de pairing.');
        }
        return $request;
    }

    /** @return list<array<string,mixed>> */
    public function pendingRequests(string $channel): array
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado para pairing.');
        }

        $account = (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default');
        $binary = (string) $this->config->get('OPENCLAW_BINARY', 'openclaw');
        $pending = LocalCommand::json([$binary, 'pairing', 'list', $channel, '--account', $account, '--json']);
        $requests = is_array($pending) && isset($pending['requests']) && is_array($pending['requests'])
            ? $pending['requests']
            : [];

        return array_values(array_filter($requests, static fn (mixed $request): bool => is_array($request)));
    }

    public function hasPendingRequest(string $channel, string $identity): bool
    {
        $identity = trim($identity);
        if ($identity === '') return false;

        foreach ($this->pendingRequests($channel) as $request) {
            $candidates = [
                $request['requestId'] ?? null,
                $request['request_id'] ?? null,
                $request['id'] ?? null,
                $request['externalIdentity'] ?? null,
                $request['senderId'] ?? null,
                $request['from'] ?? null,
            ];
            $meta = $request['meta'] ?? $request['metadata'] ?? null;
            if (is_array($meta)) {
                $candidates = array_merge($candidates, [
                    $meta['requestId'] ?? null,
                    $meta['request_id'] ?? null,
                    $meta['externalIdentity'] ?? null,
                    $meta['senderId'] ?? null,
                    $meta['from'] ?? null,
                ]);
            }
            if (in_array($identity, array_map(static fn (mixed $value): string => trim((string) $value), $candidates), true)) {
                return true;
            }
        }

        return false;
    }
}
