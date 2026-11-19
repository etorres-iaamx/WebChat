<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use Webchat\App\Config;

final class PairingEventBus
{
    private string $path;

    public function __construct(Config $config)
    {
        $this->path = trim((string) $config->get('WEBCHAT_PAIRING_EVENT_BUS', sys_get_temp_dir() . '/webchat-pairing-events.jsonl'));
    }

    /** @param array<string,mixed> $event */
    public function publish(array $event): void
    {
        if ($this->path === '') return;
        $directory = dirname($this->path);
        if (!is_dir($directory)) @mkdir($directory, 0700, true);
        $line = json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if ($line !== false) @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return resource|null */
    public function openCursor()
    {
        if ($this->path === '') return null;
        $handle = @fopen($this->path, 'c+b');
        if (!is_resource($handle)) return null;
        fseek($handle, 0, SEEK_END);
        return $handle;
    }

    /** @param resource|null $handle @return list<array<string,mixed>> */
    public function drain($handle): array
    {
        if (!is_resource($handle)) return [];
        $events = [];
        while (($line = fgets($handle)) !== false) {
            $event = json_decode(trim($line), true);
            if (is_array($event)) $events[] = $event;
        }
        return $events;
    }
}
