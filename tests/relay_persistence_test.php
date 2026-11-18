<?php

declare(strict_types=1);

$relay = file_get_contents(dirname(__DIR__) . '/src/WebSocket/RelayServer.php');
$client = file_get_contents(dirname(__DIR__) . '/src/Gateway/OpenClawClient.php');
$dashboard = file_get_contents(dirname(__DIR__) . '/public/dashboard.js');
if ($relay === false || $client === false || $dashboard === false) {
    throw new RuntimeException('Unable to read relay sources.');
}

foreach ([
    'private function reconnectGateway(',
    "'type' => 'gateway.reconnected'",
    "'type' => 'history', 'messages' => \$history, 'reconcile' => true",
    '$gateway->sendPing(\'webchat\');',
    "'request_id' => \$requestId",
    "private function requestIdFromRunId(",
] as $fragment) {
    if (strpos($relay, $fragment) === false) {
        throw new RuntimeException('Missing persistent Gateway connection safeguard: ' . $fragment);
    }
}
if (strpos($client, 'public function sendPing(') === false) {
    throw new RuntimeException('Gateway client heartbeat is missing.');
}
if (strpos($dashboard, 'payload.reconcile === true') === false) {
    throw new RuntimeException('Dashboard does not reconcile history after reconnect.');
}

echo "PASS (persistent Gateway relay safeguards)\n";
