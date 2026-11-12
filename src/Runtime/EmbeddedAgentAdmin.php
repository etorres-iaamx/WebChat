<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class EmbeddedAgentAdmin
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string,mixed> */
    public function run(string ...$arguments): array
    {
        $script = (string) $this->config->get('AGENT_ADMIN_SCRIPT', '');
        if ($script === '') $script = dirname(__DIR__, 2) . '/runtime/agent_admin.py';
        elseif ($script[0] !== '/') $script = dirname(__DIR__, 2) . '/' . ltrim($script, '/');
        $python = (string) $this->config->get('AGENT_ADMIN_PYTHON', 'python3');
        $payload = LocalCommand::json([$python, $script, ...$arguments]);
        if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['result'] ?? null)) {
            throw new RuntimeException('El administrador embebido devolvió una respuesta inválida.');
        }
        return $payload['result'];
    }
}
