<?php

namespace Sumee;

use PDO;
use PDOException;

class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME', 'sumee');
        $user = Config::get('DB_USER', 'sumee');
        $pass = Config::get('DB_PASS', 'sumee');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $attempts = 0;
        $lastError = null;
        while ($attempts < 5) {
            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return self::$pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                $attempts++;
                usleep(200000);
            }
        }
        if ($lastError) {
            throw $lastError;
        }
        return self::$pdo;
    }
}
