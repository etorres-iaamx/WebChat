<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class LocalHermesAdmin
{
    public function __construct(private readonly Config $config)
    {
    }

    public function provision(string $profile, string $description, string $soul, string $user, string $agents): string
    {
        $home = rtrim($this->config->required('HERMES_HOME'), '/');
        $this->assertProfile($profile);
        $result = LocalCommand::run(['hermes', 'profile', 'create', $profile, '--no-alias', '--description', $description]);
        if ($result['returncode'] !== 0 && !str_contains(strtolower($result['stderr'] . $result['stdout']), 'already')) throw new RuntimeException(trim($result['stderr']) ?: 'Hermes no pudo crear el Profile.');
        $root = $home . '/profiles/' . $profile;
        $this->atomicWrite($root . '/SOUL.md', $soul);
        $this->atomicWrite($root . '/memories/USER.md', $user);
        $this->atomicWrite($root . '/AGENTS.md', $agents);
        return $root;
    }

    private function assertProfile(string $profile): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $profile)) throw new RuntimeException('Nombre de Profile Hermes inválido.');
    }

    private function atomicWrite(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('No se pudo crear el contexto Hermes.');
        $temporary = tempnam($directory, '.webchat-');
        if ($temporary === false || file_put_contents($temporary, $content) === false) throw new RuntimeException('No se pudo escribir el contexto Hermes.');
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
