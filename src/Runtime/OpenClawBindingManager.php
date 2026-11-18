<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class OpenClawBindingManager
{
    public function __construct(private readonly Config $config)
    {
    }

    public function bindPeer(string $agentId, string $channel, string $externalIdentity): void
    {
        if (!$this->config->bool('OPENCLAW_BINDING_ENABLED', false)) {
            throw new RuntimeException('El binding de canales está deshabilitado.');
        }
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) {
            throw new RuntimeException('Canal no soportado para binding.');
        }
        if (!preg_match('/^[A-Za-z0-9_+:.@-]+$/', $externalIdentity)) {
            throw new RuntimeException('Identidad de canal inválida.');
        }

        $lockPath = (string) $this->config->get('OPENCLAW_BINDING_LOCK', sys_get_temp_dir() . '/webchat-openclaw-bindings.lock');
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('No fue posible obtener el lock de bindings.');
        }
        try {
            $bindings = $this->readBindings();
            foreach ($bindings as $binding) {
                $samePeer = ($binding['match']['channel'] ?? '') === $channel
                    && ($binding['match']['accountId'] ?? 'default') === (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default')
                    && ($binding['match']['peer']['kind'] ?? '') === 'direct'
                    && ($binding['match']['peer']['id'] ?? '') === $externalIdentity;
                if ($samePeer) {
                    if (($binding['agentId'] ?? '') === $agentId) {
                        return;
                    }
                    throw new RuntimeException('Esta identidad ya está vinculada a otro agente OpenClaw.');
                }
            }
            $bindings[] = [
                'agentId' => $agentId,
                'match' => [
                    'channel' => $channel,
                    'accountId' => (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default'),
                    'peer' => ['kind' => 'direct', 'id' => $externalIdentity],
                ],
            ];
            $this->patchBindings($bindings);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array<string,mixed>> */
    private function readBindings(): array
    {
        $result = LocalCommand::run([$this->config->get('OPENCLAW_BINARY', 'openclaw'), 'config', 'get', 'bindings', '--json']);
        if ($result['returncode'] !== 0) {
            throw new RuntimeException('No fue posible leer los bindings de OpenClaw.');
        }
        $decoded = json_decode($result['stdout'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenClaw devolvió bindings inválidos.');
        }
        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_array($item)));
    }

    /** @param list<array<string,mixed>> $bindings */
    private function patchBindings(array $bindings): void
    {
        $result = LocalCommand::run(
            [$this->config->get('OPENCLAW_BINARY', 'openclaw'), 'config', 'patch', '--stdin'],
            json_encode(['bindings' => $bindings], JSON_THROW_ON_ERROR),
        );
        if ($result['returncode'] !== 0) {
            throw new RuntimeException('OpenClaw rechazó el binding: ' . trim($result['stderr']));
        }
    }
}
