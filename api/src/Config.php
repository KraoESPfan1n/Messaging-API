<?php

namespace Sumee;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        $root = dirname(__DIR__);
        if (file_exists($root . '/.env')) {
            $dotenv = Dotenv::createImmutable($root);
            $dotenv->safeLoad();
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return (int) $value;
    }
}
