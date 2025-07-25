<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
global $db;

/**
 * On install/upgrade, add only the missing columns to announcementtts
 */

// Define the columns we need and their ALTER statements
$needs = [
    'text'       => "ALTER TABLE `announcementtts` ADD COLUMN `text` TEXT NULL",
    'language'   => "ALTER TABLE `announcementtts` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'en'",
    'voice'      => "ALTER TABLE `announcementtts` ADD COLUMN `voice` VARCHAR(20) NOT NULL DEFAULT 'sage'",
    'audio_file' => "ALTER TABLE `announcementtts` ADD COLUMN `audio_file` VARCHAR(255) NULL",
];

try {
    // 1) Try to fetch the existing columns
    $rows = $db->getAll("SHOW COLUMNS FROM `announcementtts`", DB_FETCHMODE_ASSOC);

    // 2) If that returned an error (on brand‑new install the table may not exist yet),
    //    treat it as “no columns found” so all four will be created below.
    if (!is_array($rows)) {
        $existing = [];
    } else {
        $existing = array_column($rows, 'Field');
    }

    // 3) For each needed column, ALTER only if it doesn’t exist already
    foreach ($needs as $col => $alterSql) {
        if (!in_array($col, $existing, true)) {
            $db->query($alterSql);
        }
    }

    // 4) Signal success
    return true;

} catch (Exception $e) {
    freepbx_log(
        FPBX_LOG_ERROR,
        "announcementtts install.php failed modifying table: " . $e->getMessage()
    );
    return false;
}
