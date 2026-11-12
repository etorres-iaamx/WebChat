<?php

declare(strict_types=1);

namespace Webchat\App;

final class Token
{
    /** @param array<string, mixed> $payload */
    public static function issue(array $payload, string $secret, int $ttl): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + max(1, $ttl);
        $body = self::base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $body, $secret, true));
        return $body . '.' . $signature;
    }

    /** @return array<string, mixed>|null */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', $parts[0], $secret, true));
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }

        $decoded = json_decode(self::base64UrlDecode($parts[0]), true);
        if (!is_array($decoded) || !isset($decoded['exp']) || (int) $decoded['exp'] < time()) {
            return null;
        }
        return $decoded;
    }

    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
