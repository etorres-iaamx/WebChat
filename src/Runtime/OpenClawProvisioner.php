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
        return (new EmbeddedAgentAdmin($this->config))->run('provision-openclaw', '--agent-public-id', $agentPublicId, '--workspace-root', $this->config->required('OPENCLAW_AGENT_WORKSPACE_ROOT'));
    }
}
