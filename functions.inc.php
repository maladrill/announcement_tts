<?php
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2015–2025 SQS Polska.
// vim: set ai ts=4 sw=4 ft=php:

/**
 * Zwraca listę destynacji dla tego modułu.
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
 * Używane przez dialplan – pobiera pojedynczą destynację.
 */
function announcementtts_getdest($exten) {
    return ['app-announcementtts-' . $exten . ',s,1'];
}

/**
 * Informacje o destynacji (dla menu Usage).
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
 * Gdzie używana jest dana nagrana wiadomość.
 * (teraz zawsze zwracamy pustą listę — nie używamy nagrań systemowych)
 */
function announcementtts_recordings_usage($recording_id) {
    return [];
}

/**
 * Buduje dialplan dla Asterisk.
 */
function announcementtts_get_config($engine) {
    if ($engine !== 'asterisk') {
        return;
    }
    global $ext;
    foreach (announcementtts_list() as $row) {
        $ctx = "app-announcementtts-{$row['announcementtts_id']}";
        if (!$row['noanswer']) {
            $ext->add($ctx, 's', '', new ext_gotoif('$["${CHANNEL(state)}"="Up"]','begin'));
            $ext->add($ctx, 's', '', new ext_answer(''));
            $ext->add($ctx, 's', '', new ext_wait('1'));
        } else {
            $ext->add($ctx, 's', '', new ext_progress());
        }
        $ext->add($ctx, 's', 'begin', new ext_noop("AnnouncementTTS: {$row['description']}"));
        $file = basename($row['audio_file'], '.wav');
        $ext->add($ctx, 's', 'play', new ext_playback("custom/{$file},noanswer"));
        if ($row['repeat_msg']) {
            $ext->add($ctx, 's', '', new ext_responsetimeout(1));
            $ext->add($ctx, 's', $row['repeat_msg'], new ext_goto('s,play'));
        }
        if ($row['allow_skip']) {
            $skip = "_[0-9*#]";
            $ext->add($ctx, 's', $skip, new ext_noop('User skipped announcement'));
            $ext->add($ctx, 's', $skip, new ext_goto($row['post_dest']));
        }
        $ext->add($ctx, 's', '', new ext_goto($row['post_dest']));
    }
}

/**
 * Pobiera wszystkie komunikaty z bazy.
 */
function announcementtts_list() {
    $res = sql(
        "SELECT * FROM announcementtts ORDER BY announcementtts_id",
        "getAll", DB_FETCHMODE_ASSOC
    );
    return is_array($res) ? $res : [];
}

/**
 * Pobiera pojedynczy komunikat jako asocjację.
 */
function announcementtts_get($id) {
    $id = intval($id);
    $row = sql(
        "SELECT * FROM announcementtts WHERE announcementtts_id = {$id}",
        "getRow", DB_FETCHMODE_ASSOC
    );
    return is_array($row) ? $row : [];
}

/**
 * Dodawanie nowego komunikatu + generacja TTS.
 */
function announcementtts_add($description, $unused, $allow_skip,
                             $post_dest, $return_ivr, $noanswer, $repeat_msg) {
    // sanitize
    $d    = sql_escape($description);
    $skip = intval($allow_skip);
    $rivr = intval($return_ivr);
    $na   = intval($noanswer);
    $rep  = sql_escape($repeat_msg);

    // 1) wstawiamy rekord
    sql(
        "INSERT INTO announcementtts
            (description, allow_skip, post_dest, return_ivr, noanswer, repeat_msg)
         VALUES
            ($d, ?, ?, ?, ?, ?)",
        'query',
        [$skip, $post_dest, $rivr, $na, $rep]
    );
    // 2) ID
    $id = sql("SELECT LAST_INSERT_ID()", "getOne");
    // 3) TTS
    $text     = sql_escape($_REQUEST['text']     ?? '');
    $language = sql_escape($_REQUEST['language'] ?? 'en');
    $voice    = sql_escape($_REQUEST['voice']    ?? 'sage');
    sql(
        "UPDATE announcementtts
            SET `text` = $text, `language` = $language, `voice` = $voice
          WHERE announcementtts_id = ?",
        'query',
        [$id]
    );
    // 4) wav
    try {
        $wav  = generate_tts($id, $_REQUEST['text'], $_REQUEST['language'], $_REQUEST['voice']);
        $path = sql_escape("/var/lib/asterisk/sounds/custom/{$wav}");
        sql("UPDATE announcementtts SET audio_file = $path WHERE announcementtts_id = ?", 'query', [$id]);
    } catch (Exception $e) {
        error_log("AnnouncementTTS: błąd TTS dla ID {$id}: " . $e->getMessage());
    }
    return $id;
}

/**
 * Edycja komunikatu + regeneracja TTS.
 */
function announcementtts_edit($id, $unused, $allow_skip,
                              $post_dest, $return_ivr, $noanswer, $repeat_msg) {
    $id   = intval($id);
    $skip = intval($allow_skip);
    $rivr = intval($return_ivr);
    $na   = intval($noanswer);
    $rep  = sql_escape($repeat_msg);

    // 1) update
    sql(
        "UPDATE announcementtts SET
            allow_skip = ?, post_dest = ?, return_ivr = ?, noanswer = ?, repeat_msg = ?
         WHERE announcementtts_id = ?",
        'query',
        [$skip, $post_dest, $rivr, $na, $rep, $id]
    );
    // 2) TTS
    $text     = sql_escape($_REQUEST['text']     ?? '');
    $language = sql_escape($_REQUEST['language'] ?? 'en');
    $voice    = sql_escape($_REQUEST['voice']    ?? 'sage');
    sql(
        "UPDATE announcementtts
            SET `text` = $text, `language` = $language, `voice` = $voice
          WHERE announcementtts_id = ?",
        'query',
        [$id]
    );
    // 3) wav
    try {
        $wav  = generate_tts($id, $_REQUEST['text'], $_REQUEST['language'], $_REQUEST['voice']);
        $path = sql_escape("/var/lib/asterisk/sounds/custom/{$wav}");
        sql("UPDATE announcementtts SET audio_file = $path WHERE announcementtts_id = ?", 'query', [$id]);
    } catch (Exception $e) {
        error_log("AnnouncementTTS: błąd regeneracji TTS dla ID {$id}: " . $e->getMessage());
    }
    return $id;
}

/**
 * Dla poprawnego reload omijamy
 */
function announcementtts_check_destinations($dest = true) {
    return [];
}

/**
 * Zmiana destynacji
 */
function announcementtts_change_destination($old, $new) {
    sql("UPDATE announcementtts SET post_dest = ? WHERE post_dest = ?", 'query', [$new, $old]);
}

/**
 * Generowanie TTS przez OpenAI.
 */
function generate_tts($id, $text, $language, $voice) {
    $keyFile = '/etc/asterisk/openai.key';
    if (!file_exists($keyFile)) {
        throw new Exception("Nie znaleziono klucza API: $keyFile");
    }
    $apiKey = trim(file_get_contents($keyFile));
    $payload = json_encode([
        'model'    => 'tts-1',
        'input'    => $text,
        'language' => $language,
        'voice'    => $voice,
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
 * Proceduralnie usuwa rekord announcementtts o zadanym ID.
 */
function announcementtts_delete($id) {
    $id = intval($id);
    $row = announcementtts_get($id);
    // 1) Usuń plik audio, jeżeli istnieje
    if (!empty($row['audio_file']) && file_exists($row['audio_file'])) {
        @unlink($row['audio_file']);
    }
    // 2) Usuń rekord z bazy
    $db = FreePBX::Database();
    $stmt = $db->prepare("DELETE FROM announcementtts WHERE announcementtts_id = ?");
    return (bool)$stmt->execute([$id]);
}
