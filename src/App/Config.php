<?php

declare(strict_types=1);

namespace Webchat\App;

use RuntimeException;

final class Config
{
    /** @var array<string, string> */
    private array $values;

    /** @param array<string, string> $values */
    public function __construct(array $values = [])
    {
        $defaults = [
            'APP_ENV' => 'local',
            'APP_SECRET' => 'change-this-local-secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'webchat_iaamx',
            'DB_USERNAME' => 'webchat',
            'DB_PASSWORD' => '',
            'OPENCLAW_GATEWAY_URL' => 'ws://127.0.0.1:18789',
            'OPENCLAW_GATEWAY_TOKEN' => '',
            'OPENCLAW_AGENT_ID' => 'main',
            'OPENCLAW_SESSION_KEY_PREFIX' => 'agent:main:webchat-iaamx',
            'OPENCLAW_GATEWAY_TLS_VERIFY' => '1',
            'OPENCLAW_PROVISIONING_ENABLED' => '0',
            'OPENCLAW_AGENT_WORKSPACE_ROOT' => '',
            'OPENCLAW_BINDING_ENABLED' => '0',
            'OPENCLAW_BINDING_LOCK' => '/tmp/webchat-openclaw-bindings.lock',
            'OPENCLAW_CHANNEL_ACCOUNT_ID' => 'default',
            'HERMES_PROVISIONING_ENABLED' => '0',
            'HERMES_HOME' => '',
            'HERMES_PROFILE_PREFIX' => 'webchat-',
            'AGENT_ADMIN_PYTHON' => 'python3',
            'AGENT_ADMIN_SCRIPT' => '',
            'WEBCHAT_BIND_HOST' => '127.0.0.1',
            'WEBCHAT_WS_PORT' => '8081',
            'WEBCHAT_PUBLIC_WS_URL' => '',
            'WEBCHAT_PUBLIC_WS_PATH' => '/webchat/ws',
            'WEBCHAT_ALLOWED_ORIGINS' => '',
            'WEBCHAT_TOKEN_TTL' => '3600',
            'WEBCHAT_SESSION_TTL' => '2592000',
            'WEBCHAT_MAX_MESSAGE_LENGTH' => '4000',
            'WEBCHAT_VISITOR_NAME' => 'Visitante IAAMX',
            'WEBCHAT_PUBLIC_BASE_URL' => '',
            'AUTH_SESSION_TTL' => '2592000',
            'HIMALAYA_ENABLED' => '0',
            'HIMALAYA_ACCOUNT' => 'agent',
            'HIMALAYA_FROM' => 'xochitl.bot.iaamx@gmail.com',
            'HIMALAYA_BINARY' => 'himalaya',
            'APP_ENV_LOCAL_SHOW_CODES' => '0',
        ];

        $this->values = $defaults;
        foreach ($defaults as $key => $_) {
            $value = getenv($key);
            if ($value !== false) {
                $this->values[$key] = $value;
            }
        }
        foreach ($values as $key => $value) {
            $this->values[$key] = $value;
        }
    }

    public static function fromEnvironment(): self
    {
        return new self();
    }

    public static function loadDotEnv(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function required(string $key): string
    {
        $value = trim((string) $this->get($key, ''));
        if ($value === '') {
            throw new RuntimeException('Missing required configuration: ' . $key);
        }
        return $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower(trim((string) $this->get($key, '')));
        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        $raw = (string) $this->get('WEBCHAT_ALLOWED_ORIGINS', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $item): bool => $item !== ''));
    }

    public function publicWebSocketUrl(?string $host = null, bool $secure = false): string
    {
        $configured = trim((string) $this->get('WEBCHAT_PUBLIC_WS_URL', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host ??= '127.0.0.1';
        $scheme = $secure ? 'wss' : 'ws';
        $port = $this->int('WEBCHAT_WS_PORT', 8081);
        $hostName = $host;

        if (str_starts_with($host, '[')) {
            $closingBracket = strpos($host, ']');
            if ($closingBracket !== false) {
                $hostName = substr($host, 0, $closingBracket + 1);
            }
        } elseif (substr_count($host, ':') === 1) {
            [$candidateHost, $candidatePort] = explode(':', $host, 2);
            if ($candidateHost !== '' && ctype_digit($candidatePort)) {
                $hostName = $candidateHost;
            }
        }

        // A TLS reverse proxy (including ngrok) exposes the relay on the same
        // public origin. Local HTTP uses the dedicated relay port instead.
        $authority = $hostName;
        if (!$secure) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority . (string) $this->get('WEBCHAT_PUBLIC_WS_PATH', '/webchat/ws');
    }

    public function gatewaySessionKey(string $sessionPublicId): string
    {
        $prefix = trim((string) $this->get('OPENCLAW_SESSION_KEY_PREFIX', 'agent:main:webchat-iaamx'));
        return rtrim($prefix, ':') . ':' . substr($sessionPublicId, 0, 12);
    }

    public function gatewaySessionKeyForAgent(string $agentId, string $sessionPublicId): string
    {
        return 'agent:' . trim($agentId, ':') . ':webchat:' . substr($sessionPublicId, 0, 12);
    }

}
