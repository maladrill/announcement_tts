<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
global $db;

/**
 * install.php
 *
 * Tworzy tabelę announcementtts z aktualnym, uproszczonym schematem.
 */

// Stwórz tabelę, jeśli nie istnieje
$db->query("
  CREATE TABLE IF NOT EXISTS `announcementtts` (
    `announcementtts_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `description`        VARCHAR(50)  NULL,
    `text`               TEXT         NULL,
    `language`           VARCHAR(10)  NOT NULL DEFAULT 'en',
    `voice`              VARCHAR(20)  NOT NULL DEFAULT 'sage',
    `audio_file`         VARCHAR(255) NULL,
    `post_dest`          VARCHAR(255) NOT NULL DEFAULT ''
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Usuwanie niepotrzebnych kolumn (jeśli istnieją)
$drop_columns = ['recording_id', 'allow_skip', 'return_ivr', 'noanswer', 'repeat_msg'];
try {
    $columns = $db->getAll("SHOW COLUMNS FROM `announcementtts`", DB_FETCHMODE_ASSOC);
    $existing = is_array($columns) ? array_column($columns, 'Field') : [];

    foreach ($drop_columns as $col) {
        if (in_array($col, $existing, true)) {
            $db->query("ALTER TABLE `announcementtts` DROP COLUMN `$col`");
        }
    }

    return true;
} catch (Exception $e) {
    freepbx_log(FPBX_LOG_ERROR,
       "announcementtts install.php failed: " . $e->getMessage()
    );
    return false;
}
