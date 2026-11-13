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
    public function provision(string $agentPublicId, string $displayName, string $email, string $phone = '', string $countryCode = ''): array
    {
        return (new EmbeddedAgentAdmin($this->config))->run(
            'provision-openclaw', '--agent-public-id', $agentPublicId,
            '--openclaw-binary', $this->config->required('OPENCLAW_BINARY'),
            '--workspace-root', $this->config->required('OPENCLAW_AGENT_WORKSPACE_ROOT'),
            '--display-name', $displayName, '--email', $email,
            '--phone', $phone, '--country-code', $countryCode,
        );
    }
}
