<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\Auth\AuthService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function auth_cookie_name(): string { return 'webchat_auth'; }
function auth_token(): string { return (string) ($_COOKIE[auth_cookie_name()] ?? ''); }

$action = trim((string) ($_GET['action'] ?? ''));
$data = request_data();

try {
    $auth = webchat_auth();
    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $auth->register($data);
        setcookie(auth_cookie_name(), $result['session_token'], ['expires' => time() + webchat_config()->int('AUTH_SESSION_TTL', 2592000), 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax']);
        unset($result['session_token']);
        json_response(['ok' => true, 'data' => $result], 201);
    }
    if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        json_response(['ok' => true, 'data' => $auth->verify((string) ($data['email'] ?? ''), (string) ($data['code'] ?? ''))]);
    }
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $auth->login((string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''));
        setcookie(auth_cookie_name(), $result['session_token'], ['expires' => time() + webchat_config()->int('AUTH_SESSION_TTL', 2592000), 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax']);
        json_response(['ok' => true, 'data' => ['user' => $result['user']]]);
    }
    if ($action === 'logout') {
        $auth->logout(auth_token());
        setcookie(auth_cookie_name(), '', ['expires' => time() - 3600, 'path' => '/']);
        json_response(['ok' => true]);
    }
    if ($action === 'me') {
        $user = $auth->userFromSession(auth_token());
        json_response(['ok' => true, 'authenticated' => $user !== null, 'user' => $user]);
    }
    if ($action === 'pairing/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        json_response(['ok' => true, 'data' => webchat_channels()->startPairing($userId, (string) ($data['channel'] ?? ''), (string) ($data['external_identity'] ?? ''))], 201);
    }
    if ($action === 'pairing/confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        webchat_channels()->confirmPairing($userId, (string) ($data['pairing_id'] ?? ''), (string) ($data['challenge'] ?? ''));
        json_response(['ok' => true]);
    }
    json_response(['ok' => false, 'error' => 'Ruta no encontrada.'], 404);
} catch (Throwable $exception) {
    $message = webchat_config()->get('APP_ENV', 'local') === 'production' ? 'No fue posible completar la operación.' : $exception->getMessage();
    json_response(['ok' => false, 'error' => $message], 400);
}
