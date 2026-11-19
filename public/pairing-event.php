<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedSecret = trim((string) webchat_config()->get('WEBCHAT_PAIRING_EVENT_SECRET', ''));
$providedSecret = (string) ($_SERVER['HTTP_X_WEBCHAT_PAIRING_SECRET'] ?? '');
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Evento no autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    webchat_channels()->recordPairingEvent(
        (string) ($payload['event'] ?? ''),
        (string) ($payload['channel'] ?? ''),
        (string) ($payload['account_id'] ?? 'default'),
        (string) ($payload['external_identity'] ?? ''),
        (string) ($payload['request_id'] ?? ''),
    );
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
