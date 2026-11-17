<?php

declare(strict_types=1);

namespace Webchat\Core;

use RuntimeException;
use Webchat\App\Config;

final class CoreAgentClient
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{agent_id:string,status:string,workspace_ref:string} */
    public function provision(array $user, string $idempotencyKey): array
    {
        $base = rtrim($this->config->required('AGENT_CORE_URL'), '/');
        $token = $this->config->required('AGENT_CORE_TOKEN');
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'agent_public_id' => (string) ($user['public_id'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'country_code' => (string) ($user['country_code'] ?? ''),
        ];
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n" . 'X-Webapp4-Internal-Token: ' . $token . "\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'timeout' => max(1, $this->config->int('AGENT_CORE_TIMEOUT_SECONDS', 15)),
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($base . '/internal/agents/provision', false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int) $match[1];
                break;
            }
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('El core no pudo crear el agente.');
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data) || !is_string($data['agent_id'] ?? null) || !is_string($data['status'] ?? null) || !is_string($data['workspace_ref'] ?? null)) {
            throw new RuntimeException('El core devolvió una respuesta inválida.');
        }
        return ['agent_id' => $data['agent_id'], 'status' => $data['status'], 'workspace_ref' => $data['workspace_ref']];
    }
}
