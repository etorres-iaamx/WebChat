<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$channel = strtolower(trim((string) ($_GET['channel'] ?? '')));
$identity = trim((string) ($_POST['external_identity'] ?? $_GET['external_identity'] ?? ''));
$message = trim((string) ($_POST['message'] ?? $_GET['message'] ?? ''));
if (!in_array($channel, ['telegram', 'whatsapp'], true) || $identity === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Canal o identidad no válidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = webchat_channels()->resolveInbound($channel, $identity, $message);
if (($result['action'] ?? '') !== 'route_to_agent') {
    $base = rtrim((string) (webchat_config()->get('WEBCHAT_PUBLIC_BASE_URL', '') ?: ''), '/');
    $path = rtrim(webchat_config()->publicPath(), '/') . '/';
    $query = http_build_query(['channel' => $channel, 'external_identity' => $identity]);
    echo json_encode(['ok' => true, 'registered' => false, 'action' => 'register', 'webchat_url' => ($base !== '' ? $base : '') . $path . '?' . $query], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'registered' => true, 'action' => 'route_to_agent'], JSON_UNESCAPED_UNICODE);
