<?php

namespace Sumee;

class RateLimiter
{
    public static function allow(string $key, int $limit, int $windowSec): bool
    {
        $redis = RedisClient::client();
        if (!$redis) {
            return true;
        }
        $count = $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, $windowSec);
        }
        return $count <= $limit;
    }
}
