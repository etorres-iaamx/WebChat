<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\App\Config;
use Webchat\App\Token;
use Webchat\WebSocket\FrameCodec;

$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$secret = 'test-secret-for-webchat';
$token = Token::issue(['kind' => 'test', 'value' => 'á'], $secret, 60);
$claims = Token::verify($token, $secret);
$assert(is_array($claims) && $claims['kind'] === 'test' && $claims['value'] === 'á', 'signed token round-trip');
$assert(Token::verify($token . 'x', $secret) === null, 'tampered token rejected');

$text = str_repeat('á', 140);
$serverFrame = FrameCodec::encode(FrameCodec::TEXT, $text);
$decoded = FrameCodec::decode($serverFrame, false);
$assert(is_array($decoded) && $decoded['payload'] === $text, 'unmasked extended frame round-trip');

$clientFrame = FrameCodec::encode(FrameCodec::TEXT, '{"type":"ping"}', true);
$decodedClient = FrameCodec::decode($clientFrame, true);
$assert(is_array($decodedClient) && $decodedClient['payload'] === '{"type":"ping"}', 'masked client frame round-trip');

$config = new Config([
    'WEBCHAT_WS_PORT' => '8081',
    'WEBCHAT_PUBLIC_WS_PATH' => '/webchat/ws',
    'OPENCLAW_SESSION_KEY_PREFIX' => 'agent:main:webchat-iaamx',
]);
$assert($config->gatewaySessionKey('12345678-1234-4234-8234-123456789012') === 'agent:main:webchat-iaamx:12345678-123', 'gateway session key is scoped');
$assert($config->gatewaySessionKeyForAgent('webchat-agent-1', '12345678-1234-4234-8234-123456789012') === 'agent:webchat-agent-1:webchat:12345678-123', 'per-agent gateway session key is scoped');
$assert($config->publicWebSocketUrl('127.0.0.1:8082', false) === 'ws://127.0.0.1:8081/webchat/ws', 'local WebSocket URL uses relay port');

printf("PASS (%d assertions)\n", $assertions);
