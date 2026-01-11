<?php

namespace Sumee;

use DateTimeImmutable;
use DateTimeZone;

class Utils
{
    public static function nowMs(): string
    {
        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', microtime(true)), new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s.v');
    }

    public static function toUnixMs(string $datetime): int
    {
        $dt = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
        return (int) ($dt->format('Uu') / 1000);
    }

    public static function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $value .= str_repeat('=', $padLen);
        }
        $value = strtr($value, '-_', '+/');
        return base64_decode($value) ?: '';
    }
}
