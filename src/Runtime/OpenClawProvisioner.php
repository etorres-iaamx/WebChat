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
        $result = (new AdminAgentsClient($this->config))->openclaw(
            'create', $agentId, '--workspace', $workspace, '--agent-dir', $root . '/.agents/' . $agentId,
        );
        $payload = $result['results'][0]['data'] ?? [];
        $externalId = is_array($payload) && is_string($payload['id'] ?? null) ? $payload['id'] : $agentId;
        return ['external_agent_id' => $externalId, 'workspace' => $workspace];
    }
}
