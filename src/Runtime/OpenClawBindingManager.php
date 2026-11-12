<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;
use Webchat\App\Config;

final class OpenClawBindingManager
{
    public function __construct(private readonly Config $config)
    {
    }

    public function bindPeer(string $agentId, string $channel, string $externalIdentity): void
    {
        if (!$this->config->bool('OPENCLAW_BINDING_ENABLED', false)) return;
        if (!in_array($channel, ['telegram', 'whatsapp'], true)) throw new RuntimeException('Canal no soportado para binding.');
        if (!preg_match('/^[A-Za-z0-9_+:.@-]+$/', $externalIdentity)) throw new RuntimeException('Identidad de canal inválida.');
        (new EmbeddedAgentAdmin($this->config))->run('bind-peer', '--agent-id', $agentId, '--channel', $channel, '--account', (string) $this->config->get('OPENCLAW_CHANNEL_ACCOUNT_ID', 'default'), '--peer-id', $externalIdentity);
    }
}
