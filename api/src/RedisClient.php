<?php

namespace Sumee;

use Predis\Client;

class RedisClient
{
    private static ?Client $client = null;

    public static function client(): ?Client
    {
        if (self::$client !== null) {
            return self::$client;
        }
        $url = Config::get('REDIS_URL');
        if (!$url) {
            return null;
        }
        self::$client = new Client($url);
        return self::$client;
    }
}
