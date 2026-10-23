<?php
// License: GPLv3+. Copyright Sangoma Technologies and SQS Polska.
require_once __DIR__ . '/includes/Service.php';
function announcementtts_list() { return FreePBX::Announcementtts()->getAnnouncementstts(); }
function announcementtts_get($id) { return FreePBX::Announcementtts()->getAnnouncementttsByID($id); }
function announcementtts_getdest($id) { return ['app-announcementtts-' . \FreePBX\modules\Announcementtts\Service::id($id) . ',s,1']; }
function announcementtts_destinations() {
    return array_map(static fn($r) => ['destination' => announcementtts_getdest($r['announcementtts_id'])[0], 'description' => $r['description']], announcementtts_list());
}
function announcementtts_getdestinfo($dest) {
    if (!is_string($dest) || !preg_match('/^app-announcementtts-([1-9][0-9]*),s,1$/D', $dest, $m)) { return false; }
    $row = announcementtts_get($m[1]);
    return !$row ? [] : ['description' => sprintf(_('Announcement TTS: %s'), $row['description']),
        'edit_url' => 'config.php?display=announcementtts&view=form&extdisplay=' . $row['announcementtts_id']];
}
function announcementtts_recordings_usage($recording_id) { return []; }
function announcementtts_check_destinations($dest = true) {
    $out = [];
    foreach (announcementtts_list() as $row) {
        if ($dest === true || (is_array($dest) && in_array($row['post_dest'], $dest, true))) {
            $out[] = ['dest' => $row['post_dest'], 'description' => sprintf(_('Announcement TTS: %s'), $row['description']),
                'edit_url' => 'config.php?display=announcementtts&view=form&extdisplay=' . $row['announcementtts_id']];
        }
    }
    return $out;
}
function announcementtts_change_destination($old, $new) { FreePBX::Announcementtts()->service()->changeDestination($old, $new); }
function announcementtts_get_config($engine) {
    if ($engine !== 'asterisk') { return; }
    global $ext;
    $service = FreePBX::Announcementtts()->service();
    foreach ($service->all() as $row) {
        $id = \FreePBX\modules\Announcementtts\Service::id($row['announcementtts_id']);
        $context = 'app-announcementtts-' . $id;
        // User descriptions must never become executable dialplan expressions.
        $ext->add($context, 's', '', new ext_noop('AnnouncementTTS ID ' . $id));
        if (!\FreePBX\modules\Announcementtts\Service::isSilent((string) ($row['text'] ?? ''))) {
            $ext->add($context, 's', '', new ext_answer(''));
            $ext->add($context, 's', '', new ext_wait('1'));
            $path = $service->audioPath($row['audio_file'] ?? '');
            if ($path !== null) { $ext->add($context, 's', 'play', new ext_playback('custom/' . basename($path, '.wav') . ',noanswer')); }
            else { error_log('[announcementtts] Missing audio for ID ' . $id); }
        }
        try { $destination = \FreePBX\modules\Announcementtts\Service::destination($row['post_dest']); }
        catch (\InvalidArgumentException $e) { $destination = 'app-blackhole,hangup,1'; error_log('[announcementtts] Invalid destination for ID ' . $id); }
        $ext->add($context, 's', '', new ext_goto($destination));
    }
}
// Compatibility wrappers for the original procedural FreePBX module API.
function announcementtts_add($description, $unused = '', $skip = 0, $post_dest = '', $ret = 0, $na = 0, $repeat = '') {
    return FreePBX::Announcementtts()->addAnnouncementtts($description, $post_dest,
        $_REQUEST['text'] ?? '', $_REQUEST['language'] ?? 'pl', $_REQUEST['voice'] ?? 'fable');
}
function announcementtts_edit($id, $unused = '', $skip = 0, $post_dest = '', $ret = 0, $na = 0, $repeat = '') {
    $row = announcementtts_get($id);
    return FreePBX::Announcementtts()->editAnnouncementtts($id, $_REQUEST['description'] ?? $row['description'] ?? '', $post_dest,
        $_REQUEST['text'] ?? '', $_REQUEST['language'] ?? 'pl', $_REQUEST['voice'] ?? 'fable');
}
function announcementtts_delete($id) { return FreePBX::Announcementtts()->deleteAnnouncementtts($id); }
function generate_tts($id, $text, $language, $voice) {
    $row = array_replace(announcementtts_get($id), compact('text', 'language', 'voice'));
    FreePBX::Announcementtts()->service()->save(\FreePBX\modules\Announcementtts\Service::id($id), $row, true);
    needreload();
    return basename((string) announcementtts_get($id)['audio_file']);
}
