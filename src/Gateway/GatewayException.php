<?php

declare(strict_types=1);

namespace Webchat\Gateway;

use RuntimeException;

final class GatewayException extends RuntimeException
{
    /** @param array<string, mixed>|null $details */
    public function __construct(string $message, public readonly ?string $codeName = null, public readonly ?array $details = null, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
