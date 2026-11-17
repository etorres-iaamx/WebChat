<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$repository = new \Webchat\Repository\ChatRepository(webchat_db());

while (true) {
    foreach ($repository->findRunningRelayRequests() as $request) {
        $runId = (string) ($request['openclaw_run_id'] ?? '');
        if ($runId === '') continue;

        $client = new \Webchat\Gateway\OpenClawClient(webchat_config());
        try {
            $client->connect();
            $history = $client->history((string) $request['gateway_session_key'], 100);
            $messages = is_array($history['messages'] ?? null) ? $history['messages'] : [];
            $runSeen = false;
            $assistantSeen = false;
            foreach ($messages as $message) {
                if (!is_array($message)) continue;
                $metadata = is_array($message['__openclaw'] ?? null) ? $message['__openclaw'] : [];
                if (($metadata['runId'] ?? '') === $runId && ($message['role'] ?? '') === 'user') {
                    $runSeen = true;
                    continue;
                }
                if ($runSeen && ($message['role'] ?? '') === 'assistant') {
                    $assistantSeen = true;
                    break;
                }
            }
            if ($assistantSeen) {
                $repository->recoverRunningRelay((int) $request['id']);
                fwrite(STDERR, '[webchat-recovery] recovered run=' . $runId . PHP_EOL);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, '[webchat-recovery] check failed run=' . $runId . ' exception=' . get_class($exception) . PHP_EOL);
        } finally {
            $client->close();
        }
    }
    sleep(15);
}
