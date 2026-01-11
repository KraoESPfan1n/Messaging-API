<?php

require __DIR__ . '/../api/vendor/autoload.php';

use Sumee\Config;
use Sumee\Db;
use Sumee\Storage;
use Sumee\Utils;

Config::load();

$pdo = Db::conn();
$now = Utils::nowMs();
$stmt = $pdo->prepare('SELECT id, storage_key FROM message_attachments WHERE deleted_at IS NOT NULL AND expires_at <= :now LIMIT 200');
$stmt->execute(['now' => $now]);
$rows = $stmt->fetchAll();

foreach ($rows as $row) {
    $path = Storage::filePath($row['storage_key']);
    if (file_exists($path)) {
        @unlink($path);
    }
    $del = $pdo->prepare('DELETE FROM message_attachments WHERE id = :id');
    $del->execute(['id' => $row['id']]);
}

echo "GC cleaned " . count($rows) . " attachments\n";
