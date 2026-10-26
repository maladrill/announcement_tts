<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// FreePBX includes this script in a scope containing its legacy global database wrapper.
// Keep installer variables local so PDO cannot replace that wrapper.
(static function (): void {
    foreach (['curl', 'mbstring', 'pdo_mysql'] as $extension) {
        if (!extension_loaded($extension)) { throw new RuntimeException('Required PHP extension is missing: ' . $extension); }
    }
    if (!is_executable('/usr/bin/ffmpeg')) { throw new RuntimeException('Install FFmpeg before installing Announcements TTS.'); }
    if (!is_callable('proc_open')) { throw new RuntimeException('PHP proc_open is required for bounded audio conversion.'); }
    $database = FreePBX::Database();
    $database->exec("CREATE TABLE IF NOT EXISTS announcementtts (
        announcementtts_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        description VARCHAR(50) NULL, `text` TEXT NULL,
        language VARCHAR(10) NOT NULL DEFAULT 'pl', voice VARCHAR(20) NOT NULL DEFAULT 'fable',
        audio_file VARCHAR(255) NULL, post_dest VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $engine = $database->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcementtts'")->fetchColumn();
    if (strcasecmp((string) $engine, 'InnoDB') !== 0) { $database->exec('ALTER TABLE announcementtts ENGINE=InnoDB'); }
    $columns = $database->query('SHOW COLUMNS FROM announcementtts')->fetchAll(PDO::FETCH_COLUMN);
    // Add missing TTS fields without deleting legacy columns or existing configuration.
    foreach ([
        'text' => 'TEXT NULL', 'language' => "VARCHAR(10) NOT NULL DEFAULT 'pl'",
        'voice' => "VARCHAR(20) NOT NULL DEFAULT 'fable'", 'audio_file' => 'VARCHAR(255) NULL',
        'post_dest' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ] as $name => $definition) {
        if (!in_array($name, $columns, true)) { $database->exec('ALTER TABLE announcementtts ADD COLUMN `' . $name . '` ' . $definition); }
    }
    $root = FreePBX::Config()->get('ASTVARLIBDIR') ?: '/var/lib/asterisk';
    $directory = rtrim($root, '/') . '/sounds/custom';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create custom sounds directory.');
    }
})();
