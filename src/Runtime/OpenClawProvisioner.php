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
    public function provision(string $agentPublicId): array
    {
        return (new LocalOpenClawAdmin($this->config))->provision($agentPublicId);
    }
}
