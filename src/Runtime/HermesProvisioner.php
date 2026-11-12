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
        $workspace = (new LocalHermesAdmin($this->config))->provision($profile, $description, '# SOUL\n\nPerfil WebChat de ' . $displayName . ".\n", "# USER\n\nNombre: {$displayName}\nCorreo: {$email}\n", "# AGENTS\n\nEste perfil solo opera sobre su propia conversación de WebChat.\n");
        return ['external_agent_id' => $profile, 'workspace' => $workspace];
    }
}
