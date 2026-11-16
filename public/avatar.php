<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$user = webchat_auth()->userFromSession((string) ($_COOKIE['webchat_auth'] ?? ''), true);
$avatar = is_array($user) ? webchat_auth()->avatarFileForUser((int) ($user['_database_id'] ?? 0)) : null;
if ($avatar === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $avatar['mime']);
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($avatar['path']);
