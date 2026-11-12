<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

/** JSON subprocess boundary for the repository-owned agent-admin adapters. */
final class AdminAgentsClient
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string,mixed> */
    public function openclaw(string ...$arguments): array
    {
        return $this->run($this->config->required('ADMIN_AGENTS_PYTHON'), $this->config->openclawAdminScript(), $arguments);
    }

    /** @return array<string,mixed> */
    public function hermes(string ...$arguments): array
    {
        return $this->run($this->config->required('ADMIN_AGENTS_PYTHON'), $this->config->hermesAdminScript(), $arguments);
    }

    /** @param list<string> $arguments @return array<string,mixed> */
    private function run(string $python, string $script, array $arguments): array
    {
        $process = proc_open([$python, $script, ...$arguments], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No fue posible iniciar el administrador de agentes.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = json_decode((string) $stdout, true);
        if ($exitCode !== 0 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
            $fallback = trim((string) $stderr);
            throw new RuntimeException($message !== '' ? $message : ($fallback !== '' ? $fallback : 'El administrador de agentes falló.'));
        }
        return $decoded;
    }
}
