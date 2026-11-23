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
        $request = $this->pendingRequestForCode($channel, $code);
        if ($request === null) {
            throw new RuntimeException('El código no está pendiente o expiró.');
        }
        if ($expectedIdentity !== null && $expectedIdentity !== '') {
            $requestIdentities = array_filter([
                $request['externalIdentity'] ?? null,
                $request['from'] ?? null,
                $request['senderId'] ?? null,
            ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '');
            $normalizedRequestIdentities = array_map(fn (mixed $value): string => $this->normalizeIdentityValue($channel, trim((string) $value)), $requestIdentities);
            $normalizedExpectedIdentity = $this->normalizeIdentityValue($channel, $expectedIdentity);
            if ($normalizedRequestIdentities !== [] && !in_array($normalizedExpectedIdentity, $normalizedRequestIdentities, true)) {
                throw new RuntimeException('El código pertenece a otra identidad de canal.');
            }
        }

        $result = LocalCommand::run([$binary, 'pairing', 'approve', $channel, $code, '--account', $account]);
        if ($result['returncode'] !== 0) {
            throw new RuntimeException('OpenClaw rechazó el código de pairing.');
        }
        return $request;
    }

    /** @return array<string,mixed>|null */
    public function pendingRequestForCode(string $channel, string $code): ?array
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9]{6,12}$/', $code)) {
            throw new RuntimeException('El código de pairing no es válido.');
        }
        foreach ($this->pendingRequests($channel) as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateCode = (string) ($candidate['code'] ?? $candidate['pairingCode'] ?? '');
            if ($candidateCode !== '' && hash_equals($candidateCode, $code)) return $candidate;
        }
        return null;
    }

    public function identityFromRequest(string $channel, array $request): ?string
    {
        $candidates = [
            $request['externalIdentity'] ?? null,
            $request['external_identity'] ?? null,
            $request['senderId'] ?? null,
            $request['sender_id'] ?? null,
            $request['from'] ?? null,
            $request['id'] ?? null,
        ];
        $meta = $request['meta'] ?? $request['metadata'] ?? null;
        if (is_array($meta)) {
            $candidates = array_merge($candidates, [
                $meta['externalIdentity'] ?? null,
                $meta['external_identity'] ?? null,
                $meta['senderId'] ?? null,
                $meta['sender_id'] ?? null,
                $meta['from'] ?? null,
            ]);
        }
        foreach ($candidates as $candidate) {
            $value = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ($value !== '') return $this->normalizeIdentityValue($channel, $value);
        }
        return null;
    }

    private function normalizeIdentityValue(string $channel, string $identity): string
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

}
