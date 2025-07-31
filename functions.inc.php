<?php
// License for all code of this FreePBX module can be found in the license file inside the module directory
// Copyright 2015–2025 SQS Polska

// vim: set ai ts=4 sw=4 ft=php:

/**
 * Returns a list of destinations for the FreePBX destinations module.
 */
function announcementtts_destinations() {
    $out = [];
    foreach (announcementtts_list() as $row) {
        $out[] = [
            'destination' => 'app-announcementtts-' . $row['announcementtts_id'] . ',s,1',
            'description' => $row['description'],
        ];
    }
    return $out;
}

/**
 * Returns a specific destination (used in extensions list).
 */
function announcementtts_getdest($exten) {
    return ['app-announcementtts-' . $exten . ',s,1'];
}

/**
 * Returns information for the "Usage" menu.
 */
function announcementtts_getdestinfo($dest) {
    global $active_modules;
    if (strpos($dest, 'app-announcementtts-') === 0) {
        list($prefix,) = explode(',', $dest);
        $id = substr($prefix, strlen('app-announcementtts-'));
        $row = announcementtts_get($id);
        if (empty($row)) {
            return [];
        }
        $type = $active_modules['announcementtts']['type'] ?? 'setup';
        return [
            'description' => sprintf(_("Announcement TTS: %s"), $row['description']),
            'edit_url'    => 'config.php?display=announcementtts&view=form&type='
                             . $type . '&extdisplay=' . urlencode($id),
        ];
    }
    return false;
}

/**
 * Returns an empty array — we don't use system recordings.
 */
function announcementtts_recordings_usage($recording_id) {
    return [];
}

/**
 * Generates the Asterisk dialplan for TTS announcements.
 */
function announcementtts_get_config($engine) {
    if ($engine !== 'asterisk') {
        return;
    }
    global $ext;
    foreach (announcementtts_list() as $row) {
        $ctx = "app-announcementtts-{$row['announcementtts_id']}";
        $ext->add($ctx, 's', '', new ext_answer(''));
        $ext->add($ctx, 's', '', new ext_wait('1'));
        $ext->add($ctx, 's', '', new ext_noop("AnnouncementTTS: {$row['description']}"));
        $file = basename($row['audio_file'], '.wav');
        $ext->add($ctx, 's', 'play', new ext_playback("custom/{$file},noanswer"));
        $ext->add($ctx, 's', '', new ext_goto($row['post_dest']));
    }
}

/**
 * Returns all TTS announcements from the database.
 */
function announcementtts_list() {
    $res = sql("SELECT * FROM announcementtts ORDER BY announcementtts_id", "getAll", DB_FETCHMODE_ASSOC);
    return is_array($res) ? $res : [];
}

/**
 * Returns a single TTS announcement by ID.
 */
function announcementtts_get($id) {
    $id = intval($id);
    $row = sql("SELECT * FROM announcementtts WHERE announcementtts_id = {$id}", "getRow", DB_FETCHMODE_ASSOC);
    return is_array($row) ? $row : [];
}

/**
 * Adds a new TTS announcement and generates the audio file.
 */
function announcementtts_add($description, $unused = '', $skip = 0, $post_dest = '', $ret = 0, $na = 0, $repeat = '') {
    $desc  = sql_escape($description);
    $text  = sql_escape($_REQUEST['text']     ?? '');
    $lang  = sql_escape($_REQUEST['language'] ?? 'en');
    $voice = sql_escape($_REQUEST['voice']    ?? 'nova');

    sql("INSERT INTO announcementtts (description, text, language, voice, post_dest) VALUES ($desc, $text, $lang, $voice, ?)",
        'query', [$post_dest]);

    $id = sql("SELECT LAST_INSERT_ID()", "getOne");

    try {
        $wav  = generate_tts($id, $_REQUEST['text'], $_REQUEST['language'], $_REQUEST['voice']);
        $path = sql_escape("/var/lib/asterisk/sounds/custom/{$wav}");
        sql("UPDATE announcementtts SET audio_file = $path WHERE announcementtts_id = ?", 'query', [$id]);
    } catch (Exception $e) {
        error_log("AnnouncementTTS: TTS error for ID {$id}: " . $e->getMessage());
    }

    return $id;
}

/**
 * Edits an existing TTS announcement and regenerates the audio if TTS fields changed.
 */
function announcementtts_edit($id, $unused = '', $skip = 0, $post_dest = '', $ret = 0, $na = 0, $repeat = '') {
    $id    = intval($id);
    $old   = announcementtts_get($id);
    $text  = $_REQUEST['text']     ?? '';
    $lang  = $_REQUEST['language'] ?? 'en';
    $voice = $_REQUEST['voice']    ?? 'nova';

    sql("UPDATE announcementtts SET text = ?, language = ?, voice = ?, post_dest = ?, description = description WHERE announcementtts_id = ?",
        'query', [$text, $lang, $voice, $post_dest, $id]);

    if ($old['text'] !== $text || $old['language'] !== $lang || $old['voice'] !== $voice) {
        try {
            $wav  = generate_tts($id, $text, $lang, $voice);
            $path = "/var/lib/asterisk/sounds/custom/{$wav}";
            sql("UPDATE announcementtts SET audio_file = ? WHERE announcementtts_id = ?", 'query', [$path, $id]);
        } catch (Exception $e) {
            error_log("AnnouncementTTS: TTS regen error for ID {$id}: " . $e->getMessage());
        }
    }

    return $id;
}

/**
 * FreePBX destination reload check — always returns empty.
 */
function announcementtts_check_destinations($dest = true) {
    return [];
}

/**
 * Updates destinations when a destination is changed globally.
 */
function announcementtts_change_destination($old, $new) {
    sql("UPDATE announcementtts SET post_dest = ? WHERE post_dest = ?", 'query', [$new, $old]);
}

/**
 * Generates TTS using OpenAI API and converts the MP3 to WAV for Asterisk.
 */
function generate_tts($id, $text, $language, $voice) {
    $keyFile = '/etc/asterisk/openai.key';

    if (!file_exists($keyFile)) {
        throw new Exception("OpenAI key file not found: $keyFile");
    }

    $apiKey = trim(file_get_contents($keyFile));
    $payload = json_encode([
        'model'    => 'tts-1',
        'input'    => $text,
        'language' => $language,
        'voice'    => $voice,
        'response_format' => 'mp3'
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($code !== 200) {
        throw new Exception("OpenAI TTS HTTP {$code}: {$resp}");
    }

    $tmpMp3 = tempnam(sys_get_temp_dir(), "tts{$id}_") . '.mp3';
    file_put_contents($tmpMp3, $resp);

    $outName = "announcementtts_{$id}.wav";
    $outPath = "/var/lib/asterisk/sounds/custom/{$outName}";

    $cmd = sprintf(
        'ffmpeg -y -i %s -ar 8000 -ac 1 -sample_fmt s16 %s 2>&1',
        escapeshellarg($tmpMp3),
        escapeshellarg($outPath)
    );

    exec($cmd, $out, $ret);
    @unlink($tmpMp3);

    if ($ret !== 0) {
        throw new Exception("FFmpeg error: " . implode("\n", $out));
    }

    return $outName;
}

/**
 * Deletes an announcement by ID, including the audio file if it exists.
 */
function announcementtts_delete($id) {
    $id = intval($id);
    $row = announcementtts_get($id);

    if (!empty($row['audio_file']) && file_exists($row['audio_file'])) {
        @unlink($row['audio_file']);
    }

    $db = FreePBX::Database();
    $stmt = $db->prepare("DELETE FROM announcementtts WHERE announcementtts_id = ?");
    return (bool)$stmt->execute([$id]);
}
