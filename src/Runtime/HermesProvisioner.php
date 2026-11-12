<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use Webchat\App\Config;

final class HermesProvisioner
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{external_agent_id:string, workspace:string} */
    public function provision(string $userPublicId, string $displayName, string $email): array
    {
        $profile = trim((string) $this->config->get('HERMES_PROFILE_PREFIX', 'webchat-')) . strtolower(str_replace('-', '', $userPublicId));
        $description = 'WebChat: ' . $displayName;
        $workspace = (new EmbeddedAgentAdmin($this->config))->run('provision-hermes', '--profile', $profile, '--description', $description, '--home', $this->config->required('HERMES_HOME'), '--display-name', $displayName, '--email', $email)['workspace'];
        return ['external_agent_id' => $profile, 'workspace' => $workspace];
    }
}
