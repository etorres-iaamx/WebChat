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
        if (!$this->config->bool('OPENCLAW_BINDING_ENABLED', false)) return;
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) throw new RuntimeException('Canal no soportado para binding.');
        if (!preg_match('/^[A-Za-z0-9_+:.@-]+$/', $externalIdentity)) throw new RuntimeException('Identidad de canal inválida.');
        $lockPath = (string) ($this->config->get('OPENCLAW_BINDING_LOCK', sys_get_temp_dir() . '/webchat-openclaw-bindings.lock'));
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('No fue posible obtener el lock de bindings.');
        try {
            $bindings = $this->readBindings();
            foreach ($bindings as $binding) {
                if (($binding['agentId'] ?? '') === $agentId && ($binding['match']['channel'] ?? '') === $channel && ($binding['match']['peer']['id'] ?? '') === $externalIdentity) return;
            }
            $bindings[] = ['agentId' => $agentId, 'match' => ['channel' => $channel, 'accountId' => (string) ($this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default')), 'peer' => ['kind' => 'direct', 'id' => $externalIdentity]]];
            $this->patchBindings($bindings);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array<string,mixed>> */
    private function readBindings(): array
    {
        $result = $this->run(['openclaw', 'config', 'get', 'bindings', '--json']);
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) throw new RuntimeException('OpenClaw devolvió bindings inválidos.');
        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_array($item)));
    }

    /** @param list<array<string,mixed>> $bindings */
    private function patchBindings(array $bindings): void
    {
        $this->run(['openclaw', 'config', 'patch', '--stdin'], json_encode(['bindings' => $bindings,], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $command */
    private function run(array $command, string $stdin = ''): string
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No fue posible ejecutar OpenClaw.');
        if ($stdin !== '') fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException('OpenClaw rechazó la operación: ' . trim($error));
        return (string) $output;
    }
}
