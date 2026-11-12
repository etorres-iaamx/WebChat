<?php

declare(strict_types=1);

namespace Webchat\WebSocket;

use RuntimeException;

final class FrameCodec
{
    public const CONTINUATION = 0x0;
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    public static function encode(int $opcode, string $payload = '', bool $mask = false): string
    {
        $length = strlen($payload);
        $first = 0x80 | ($opcode & 0x0f);
        $header = chr($first);

        if ($length < 126) {
            $header .= chr(($mask ? 0x80 : 0) | $length);
        } elseif ($length <= 0xffff) {
            $header .= chr(($mask ? 0x80 : 0) | 126) . pack('n', $length);
        } else {
            $header .= chr(($mask ? 0x80 : 0) | 127) . pack('J', $length);
        }

        if (!$mask) {
            return $header . $payload;
        }

        $key = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $key[$index % 4];
        }
        return $header . $key . $masked;
    }

    /** @return array{fin:bool, opcode:int, masked:bool, payload:string}|null */
    public static function decode(string &$buffer, bool $expectMasked, int $maxPayload = 1048576): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        if (($first & 0x70) !== 0) {
            throw new RuntimeException('Unsupported WebSocket extension');
        }

        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        if ($masked !== $expectMasked) {
            throw new RuntimeException('Unexpected WebSocket mask state');
        }

        $length = $second & 0x7f;
        $offset = 2;
        if ($length === 126) {
            if (strlen($buffer) < $offset + 2) {
                return null;
            }
            $length = unpack('nvalue', substr($buffer, $offset, 2))['value'];
            $offset += 2;
        } elseif ($length === 127) {
            if (strlen($buffer) < $offset + 8) {
                return null;
            }
            $parts = unpack('Nhigh/Nlow', substr($buffer, $offset, 8));
            $length = ($parts['high'] * 4294967296) + $parts['low'];
            $offset += 8;
        }

        if ($length > $maxPayload) {
            throw new RuntimeException('WebSocket payload exceeds configured limit');
        }

        $mask = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);
        if ($masked && $length > 0) {
            $unmasked = '';
            for ($index = 0; $index < $length; $index++) {
                $unmasked .= $payload[$index] ^ $mask[$index % 4];
            }
            $payload = $unmasked;
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'masked' => $masked,
            'payload' => $payload,
        ];
    }
}
