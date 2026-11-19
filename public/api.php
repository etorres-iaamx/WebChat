<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\Auth\AuthService;
use Webchat\App\Token;

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
function auth_cookie_options(?int $ttl = null): array
{
    $options = ['path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'httponly' => true, 'samesite' => 'Lax'];
    if ($ttl !== null) $options['expires'] = time() + $ttl;
    return $options;
}

$action = trim((string) ($_GET['action'] ?? ''));
$data = request_data();

try {
    $auth = webchat_auth();
    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $auth->register($data);
        setcookie(auth_cookie_name(), $result['session_token'], auth_cookie_options());
        unset($result['session_token']);
        json_response(['ok' => true, 'data' => $result], 201);
    }
    if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        json_response(['ok' => true, 'data' => $auth->verify((string) ($data['email'] ?? ''), (string) ($data['code'] ?? ''))]);
    }
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rememberMe = in_array(strtolower(trim((string) ($data['remember_me'] ?? ''))), ['1', 'true', 'on', 'yes'], true);
        $result = $auth->login((string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''), $rememberMe);
        setcookie(auth_cookie_name(), $result['session_token'], $rememberMe ? auth_cookie_options((int) $result['session_ttl']) : auth_cookie_options());
        json_response(['ok' => true, 'data' => ['user' => $result['user']]]);
    }
    if ($action === 'logout') {
        $auth->logout(auth_token());
        setcookie(auth_cookie_name(), '', array_merge(auth_cookie_options(), ['expires' => time() - 3600]));
        json_response(['ok' => true]);
    }
    if ($action === 'me') {
        $user = $auth->userFromSession(auth_token());
        json_response(['ok' => true, 'authenticated' => $user !== null, 'user' => $user]);
    }
    if ($action === 'profile/avatar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) json_response(['ok' => false, 'error' => 'Selecciona una imagen.'], 422);
        json_response(['ok' => true, 'data' => ['user' => $auth->updateAvatar($userId, $_FILES['avatar'])]]);
    }
    if ($action === 'profile/password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        $newPassword = (string) ($data['new_password'] ?? '');
        if ($newPassword !== (string) ($data['new_password_confirmation'] ?? '')) {
            json_response(['ok' => false, 'error' => 'Las contraseñas nuevas no coinciden.'], 422);
        }
        json_response(['ok' => true, 'data' => ['user' => $auth->changePassword($userId, (string) ($data['current_password'] ?? ''), $newPassword, auth_token())]]);
    }
    if ($action === 'runtime/provision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        try {
            $auth->provisionRuntimesForUser($userId);
        } catch (Throwable $exception) {
            error_log('WebChat runtime retry failed user_id=' . $userId . ' exception=' . get_class($exception));
            json_response(['ok' => false, 'error' => 'No fue posible completar la operación. Intenta nuevamente más tarde.'], 503);
        }
        json_response(['ok' => true]);
    }
    if ($action === 'channel/link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        $intent = Token::verify((string) ($data['intent'] ?? ''), (string) webchat_config()->get('APP_SECRET'));
        if (!is_array($intent) || ($intent['purpose'] ?? '') !== 'channel_onboarding') json_response(['ok' => false, 'error' => 'La invitación de canal no es válida o expiró.'], 422);
        webchat_channels()->link($userId, (string) ($intent['channel'] ?? ''), (string) ($intent['external_identity'] ?? ''));
        json_response(['ok' => true]);
    }
    if ($action === 'channel/reserve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        $intent = Token::verify((string) ($data['intent'] ?? ''), (string) webchat_config()->get('APP_SECRET'));
        if (!is_array($intent) || ($intent['purpose'] ?? '') !== 'channel_onboarding') json_response(['ok' => false, 'error' => 'La invitación de canal no es válida o expiró.'], 422);
        webchat_channels()->link($userId, (string) ($intent['channel'] ?? ''), (string) ($intent['external_identity'] ?? ''));
        json_response(['ok' => true]);
    }
    if ($action === 'channel/approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        webchat_channels()->approvePairing($userId, (string) ($data['channel'] ?? ''), (string) ($data['code'] ?? ''));
        json_response(['ok' => true]);
    }
    if ($action === 'channel/pending') {
        $userId = $auth->userIdFromSession(auth_token());
        if ($userId === null) json_response(['ok' => false, 'error' => 'Debes iniciar sesión.'], 401);
        json_response(['ok' => true, 'data' => ['channels' => webchat_channels()->pendingForUser($userId)]]);
    }
    json_response(['ok' => false, 'error' => 'Ruta no encontrada.'], 404);
} catch (Throwable $exception) {
    if ($exception instanceof \Webchat\Auth\UserAlreadyRegisteredException) {
        json_response(['ok' => false, 'error' => $exception->getMessage()], 409);
    }
    $correlationId = bin2hex(random_bytes(8));
    $safeError = preg_replace('/\s+/', ' ', $exception->getMessage()) ?: get_class($exception);
    $safeError = preg_replace('/(token|password|secret|authorization|code)[=: ]+[^\s,;]+/i', '$1=[redacted]', $safeError) ?: $safeError;
    error_log(sprintf('WebChat request failed correlation_id=%s action=%s exception=%s error=%s', $correlationId, $action, get_class($exception), mb_substr($safeError, 0, 500)));
    $message = webchat_config()->get('APP_ENV', 'local') === 'production' ? 'No fue posible completar la operación.' : $exception->getMessage();
    json_response(['ok' => false, 'error' => $message], 400);
}
