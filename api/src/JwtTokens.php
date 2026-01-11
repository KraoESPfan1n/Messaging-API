<?php

namespace Sumee;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtTokens
{
    public static function encode(array $payload): string
    {
        $secret = Config::get('JWT_SECRET', 'dev_secret_change_me');
        return JWT::encode($payload, $secret, 'HS256');
    }

    public static function decode(string $token): array
    {
        $secret = Config::get('JWT_SECRET', 'dev_secret_change_me');
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        return (array) $decoded;
    }
}
