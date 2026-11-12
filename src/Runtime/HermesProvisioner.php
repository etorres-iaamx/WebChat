<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
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
        (new AdminAgentsClient($this->config))->hermes('create', $profile, '--description', $description);

        $temporary = [];
        try {
            $temporary['soul'] = $this->temporary('# SOUL\n\nPerfil WebChat de ' . $displayName . ".\n");
            $temporary['user'] = $this->temporary("# USER\n\nNombre: {$displayName}\nCorreo: {$email}\n");
            $temporary['agents'] = $this->temporary("# AGENTS\n\nEste perfil solo opera sobre su propia conversación de WebChat.\n");
            $arguments = ['context-update', $profile, '--soul-file', $temporary['soul'], '--user-file', $temporary['user'], '--agents-file', $temporary['agents']];
            if (($home = trim((string) $this->config->get('HERMES_HOME', ''))) !== '') {
                array_push($arguments, '--hermes-home', $home);
            }
            (new AdminAgentsClient($this->config))->hermes(...$arguments);
        } finally {
            foreach ($temporary as $path) if (is_string($path) && is_file($path)) @unlink($path);
        }

        $home = trim((string) $this->config->get('HERMES_HOME', ''));
        $workspace = $home !== '' ? rtrim($home, '/') . '/profiles/' . $profile : 'profiles/' . $profile;
        return ['external_agent_id' => $profile, 'workspace' => $workspace];
    }

    private function temporary(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'webchat-hermes-');
        if ($path === false || file_put_contents($path, $content) === false) throw new RuntimeException('No fue posible preparar el contexto Hermes.');
        return $path;
    }
}
