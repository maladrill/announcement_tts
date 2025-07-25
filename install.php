<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// License for all code of this FreePBX module can be found in the license file inside the module directory
// Copyright 2025 SQS Polska.

// --- BEGIN: dodanie kolumn TTS do tabeli announcementtts ---
global $db;
// definicje nowych kolumn: klucz => definicja SQL
$cols = [
    'text'       => "TEXT NOT NULL AFTER `repeat_msg`",
    'language'   => "VARCHAR(16) NOT NULL DEFAULT 'en' AFTER `text`",
    'voice'      => "VARCHAR(16) NOT NULL DEFAULT 'sage' AFTER `language`",
    'audio_file' => "VARCHAR(255) NULL AFTER `voice`",
];

foreach ($cols as $colName => $colDef) {
    // Sprawdź, czy kolumna już istnieje
    $exists = $db->getOne("SHOW COLUMNS FROM `announcementtts` LIKE '" . $db->escapeSimple($colName) . "'");
    if (DB::IsError($exists)) {
        die_freepbx("Error checking for column $colName: " . $exists->getDebugInfo());
    }
    if (empty($exists)) {
        // Dodaj kolumnę
        $sql = "ALTER TABLE `announcementtts` ADD COLUMN `$colName` $colDef";
        $res = $db->query($sql);
        if (DB::IsError($res)) {
            die_freepbx("Failed to add column $colName: " . $res->getDebugInfo());
        }
    }
}
// --- END: dodanie kolumn TTS ---
