<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\App\Token;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$resolveSecret = (string) webchat_config()->get('WEBCHAT_CHANNEL_RESOLVE_SECRET', '');
if ($resolveSecret !== '' && !hash_equals($resolveSecret, (string) ($_SERVER['HTTP_X_WEBCHAT_RESOLVE_SECRET'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Resolver no autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$channel = strtolower(trim((string) ($_GET['channel'] ?? '')));
$identity = trim((string) ($_POST['external_identity'] ?? $_GET['external_identity'] ?? ''));
if (!in_array($channel, ['telegram', 'whatsapp'], true) || $identity === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Canal o identidad no válidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

$channels = webchat_channels();
$resolved = $channels->resolve($channel, $identity);
if ($resolved === null) {
    $base = rtrim((string) (webchat_config()->get('WEBCHAT_PUBLIC_BASE_URL', '') ?: ''), '/');
    $path = rtrim(webchat_config()->publicPath(), '/') . '/';
    $intent = Token::issue(['purpose' => 'channel_onboarding', 'channel' => $channel, 'external_identity' => $identity], (string) webchat_config()->get('APP_SECRET'), 900);
    $query = http_build_query(['intent' => $intent]);
    $state = $channels->state($channel, $identity);
    // A linked WebChat identity waits for OpenClaw's native pairing code.
    // Keep the resolver action aligned with channel_identities.status so an
    // already-created account is not sent through registration again.
    $action = $state === 'binding_pending' ? 'continue_pairing' : 'register';
    echo json_encode([
        'ok' => true,
        'registered' => false,
        'state' => $state ?? 'unknown',
        'action' => $action,
        'channel' => $channel,
        'webchat_url' => ($base !== '' ? $base : '') . $path . '?' . $query,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'registered' => true, 'state' => 'active', 'action' => 'route_to_agent'], JSON_UNESCAPED_UNICODE);
