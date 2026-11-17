<?php

declare(strict_types=1);

define('WEBCHAT_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Webchat\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = WEBCHAT_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

Webchat\App\Config::loadDotEnv(WEBCHAT_ROOT . '/.env');

function webchat_config(): Webchat\App\Config
{
    static $config;
    return $config ??= Webchat\App\Config::fromEnvironment();
}

function webchat_db(): Webchat\App\Database
{
    static $database;
    return $database ??= new Webchat\App\Database(webchat_config());
}

function webchat_repository(): Webchat\Repository\ChatRepository
{
    static $repository;
    return $repository ??= new Webchat\Repository\ChatRepository(webchat_db());
}

function webchat_auth(): Webchat\Auth\AuthService
{
    static $auth;
    return $auth ??= new Webchat\Auth\AuthService(webchat_db(), webchat_config());
}

function webchat_channels(): Webchat\Channels\ChannelIdentityService
{
    static $channels;
    return $channels ??= new Webchat\Channels\ChannelIdentityService(webchat_db());
}

function webchat_core(): Webchat\Core\CoreAgentClient
{
    static $client;
    return $client ??= new Webchat\Core\CoreAgentClient(webchat_config());
}

function webchat_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function webchat_url(string $path = ''): string
{
    $base = webchat_config()->publicPath();
    $path = ltrim($path, '/');
    return $base . ($path === '' ? '' : '/' . $path);
}
