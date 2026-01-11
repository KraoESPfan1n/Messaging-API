<?php

namespace Sumee;

use Psr\Http\Message\UploadedFileInterface;

class Storage
{
    public static function storagePath(): string
    {
        return rtrim(Config::get('STORAGE_PATH', dirname(__DIR__) . '/storage'), '/');
    }

    public static function filePath(string $storageKey): string
    {
        return self::storagePath() . '/' . ltrim($storageKey, '/');
    }

    public static function publicUrl(string $storageKey): string
    {
        $base = rtrim(Config::get('UPLOAD_BASE_URL', Config::get('APP_URL', 'http://localhost:8080') . '/uploads'), '/');
        return $base . '/files/' . ltrim($storageKey, '/');
    }

    public static function saveUploadedFile(UploadedFileInterface $file, string $storageKey): void
    {
        $path = self::filePath($storageKey);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file->moveTo($path);
    }
}
