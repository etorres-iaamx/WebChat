<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class LocalOpenClawAdmin
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{external_agent_id:string,workspace:string} */
    public function provision(string $agentPublicId): array
    {
        $agentId = 'webchat-' . strtolower(str_replace('-', '', $agentPublicId));
        $root = rtrim($this->config->required('OPENCLAW_AGENT_WORKSPACE_ROOT'), '/');
        $workspace = $root . '/' . $agentId;
        $result = LocalCommand::json(['openclaw', 'agents', 'add', $agentId, '--workspace', $workspace, '--agent-dir', $root . '/.agents/' . $agentId, '--non-interactive', '--json']);
        $external = is_array($result) && is_string($result['id'] ?? null) ? $result['id'] : $agentId;
        return ['external_agent_id' => $external, 'workspace' => $workspace];
    }

    public function bindPeer(string $agentId, string $channel, string $externalIdentity): void
    {
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) throw new RuntimeException('Canal no soportado.');
        $lockPath = (string) $this->config->get('OPENCLAW_BINDING_LOCK', sys_get_temp_dir() . '/webchat-openclaw-bindings.lock');
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('No fue posible obtener el lock de bindings.');
        try {
            $bindings = LocalCommand::json(['openclaw', 'config', 'get', 'bindings', '--json']);
            if (!is_array($bindings)) throw new RuntimeException('Bindings inválidos.');
            $desired = ['channel' => $channel, 'accountId' => (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default'), 'peer' => ['kind' => 'direct', 'id' => $externalIdentity]];
            foreach ($bindings as $binding) {
                if (!is_array($binding) || ($binding['match'] ?? null) !== $desired) continue;
                if (($binding['agentId'] ?? '') !== $agentId) throw new RuntimeException('La identidad ya está vinculada a otro agente.');
                return;
            }
            $bindings[] = ['type' => 'route', 'agentId' => $agentId, 'match' => $desired];
            LocalCommand::json(['openclaw', 'config', 'patch', '--stdin'], json_encode(['bindings' => $bindings], JSON_THROW_ON_ERROR));
            $readback = LocalCommand::json(['openclaw', 'config', 'get', 'bindings', '--json']);
            foreach (is_array($readback) ? $readback : [] as $binding) if (is_array($binding) && ($binding['agentId'] ?? '') === $agentId && ($binding['match'] ?? null) === $desired) return;
            throw new RuntimeException('No se pudo verificar el binding de peer.');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
