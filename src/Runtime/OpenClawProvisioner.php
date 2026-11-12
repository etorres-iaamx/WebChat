<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class OpenClawProvisioner
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{external_agent_id:string, workspace:string} */
    public function provision(string $agentPublicId): array
    {
        $agentId = 'webchat-' . strtolower(str_replace('-', '', $agentPublicId));
        $root = rtrim($this->config->required('OPENCLAW_AGENT_WORKSPACE_ROOT'), '/');
        $workspace = $root . '/' . $agentId;
        $command = ['openclaw', 'agents', 'add', '--non-interactive', '--workspace', $workspace, '--json', $agentId];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No fue posible iniciar el provisioning de OpenClaw.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException('OpenClaw no pudo crear el agente: ' . trim($error));
        $decoded = json_decode((string) $output, true);
        $externalId = is_array($decoded) && is_string($decoded['id'] ?? null) ? $decoded['id'] : $agentId;
        return ['external_agent_id' => $externalId, 'workspace' => $workspace];
    }
}
