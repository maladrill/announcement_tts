<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
global $db;

/**
 * install.php
 *
 * 1) Creates announcementtts table (with recording_id) if it doesn’t exist
 * 2) On upgrades only adds any of the missing columns
 */

// 1) Create full table schema on fresh installs
$db->query("
  CREATE TABLE IF NOT EXISTS `announcementtts` (
    `announcementtts_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `description`       VARCHAR(50)  NULL,
    `recording_id`      INT          NULL,
    `allow_skip`        TINYINT(1)   NOT NULL DEFAULT 0,
    `post_dest`         VARCHAR(255) NULL,
    `return_ivr`        TINYINT(1)   NOT NULL DEFAULT 0,
    `noanswer`          TINYINT(1)   NOT NULL DEFAULT 0,
    `repeat_msg`        VARCHAR(2)   NOT NULL DEFAULT '',
    `text`              TEXT         NULL,
    `language`          VARCHAR(10)  NOT NULL DEFAULT 'en',
    `voice`             VARCHAR(20)  NOT NULL DEFAULT 'sage',
    `audio_file`        VARCHAR(255) NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 2) Now make sure any “delta” columns get added on upgrades only
$needs = [
    'recording_id' => "ALTER TABLE `announcementtts` ADD COLUMN `recording_id` INT NULL",
    'text'         => "ALTER TABLE `announcementtts` ADD COLUMN `text` TEXT NULL",
    'language'     => "ALTER TABLE `announcementtts` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'en'",
    'voice'        => "ALTER TABLE `announcementtts` ADD COLUMN `voice` VARCHAR(20) NOT NULL DEFAULT 'sage'",
    'audio_file'   => "ALTER TABLE `announcementtts` ADD COLUMN `audio_file` VARCHAR(255) NULL",
];

try {
    $rows = $db->getAll("SHOW COLUMNS FROM `announcementtts`", DB_FETCHMODE_ASSOC);
    $existing = is_array($rows) ? array_column($rows, 'Field') : [];

    foreach ($needs as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            $db->query($sql);
        }
    }

    return true;
} catch (Exception $e) {
    freepbx_log(FPBX_LOG_ERROR,
       "announcementtts install.php failed: " . $e->getMessage()
    );
    return false;
}
