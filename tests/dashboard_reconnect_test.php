<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/public/dashboard.js');
if ($source === false) {
    throw new RuntimeException('Unable to read public/dashboard.js');
}

$required = [
    'const inFlightOutbound = new Map();',
    'function requeueInFlight()',
    'function scheduleReconnect()',
    'inFlightOutbound.delete(String(payload.request_id));',
    'requeueInFlight();',
];

foreach ($required as $fragment) {
    if (strpos($source, $fragment) === false) {
        throw new RuntimeException('Missing reconnect/idempotency safeguard: ' . $fragment);
    }
}

if (strpos($source, 'const exponent = Math.min(reconnectAttempts++, 5);') === false) {
    throw new RuntimeException('Reconnect backoff is not bounded.');
}

echo "PASS (dashboard reconnect safeguards)\n";
