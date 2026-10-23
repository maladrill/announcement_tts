<?php
// Exercise intentional silence through storage, dialplan, GraphQL and backup adapters.
use FreePBX\modules\Announcementtts\Service;

$silentData = array_replace($data, ['description' => 'Silent test', 'text' => '']);
$beforeCalls = $calls;
$beforeFiles = glob($root . '/announcementtts_*.wav');
$broken = true;
$silentId = $service->save(null, $silentData);
check($service->get($silentId)['text'] === '' && $service->get($silentId)['audio_file'] === null,
    'Empty text creates an intentional silent announcement');
check($calls === $beforeCalls && glob($root . '/announcementtts_*.wav') === $beforeFiles,
    'Silent create needs neither API access nor a placeholder WAV');
$service->save($silentId, array_replace($silentData, ['text' => " \t\r\n\u{00A0}\u{2003}"]), true);
check($service->get($silentId)['text'] === '' && $calls === $beforeCalls,
    'Whitespace-only text is normalized and forced regeneration remains silent');

$previous = $service->get($id);
$service->save($id, array_replace($data, ['text' => '']));
check($service->get($id)['audio_file'] === null && is_file($previous['audio_file']) && $calls === $beforeCalls,
    'Clearing text removes the audio reference and preserves the WAV used by the loaded dialplan');
$emptySnapshot = $service->get($id);
$db->exec("CREATE TRIGGER reject_silent_update BEFORE UPDATE ON announcementtts BEGIN SELECT RAISE(ABORT, 'test failure'); END");
fails(static fn() => $service->save($id, array_replace($data, ['text' => '', 'post_dest' => 'app-blackhole,hangup,1'])),
    'Silent update propagates database errors');
check($service->get($id) === $emptySnapshot && $calls === $beforeCalls,
    'Silent update rollback preserves metadata without calling the provider');
$db->exec('DROP TRIGGER reject_silent_update');

$GLOBALS['ext']->commands = [];
$logFile = $root . '/silent-errors.log';
$previousErrorLog = ini_get('error_log');
ini_set('error_log', $logFile);
try { announcementtts_get_config('asterisk'); }
finally { ini_set('error_log', $previousErrorLog); }
$commands = array_values(array_filter($GLOBALS['ext']->commands, static fn($command) => $command[0] === 'app-announcementtts-' . $silentId));
check(array_column($commands, 1) === ['AnnouncementTTS ID ' . $silentId, $silentData['post_dest']],
    'Silent dialplan goes directly to destination without Answer, Wait or Playback');
check(!is_file($logFile) || filesize($logFile) === 0,
    'Intentional silence does not produce missing-audio warnings');

$backup = new \FreePBX\modules\Announcementtts\Backup($fpbx);
$backup->runBackup('silent', 'offline');
check(count($backup->configs['announcements']) === 2 && $backup->files === [] && $backup->logs === [],
    'Backup includes silent records without audio files or warnings');
$restore = new \FreePBX\modules\Announcementtts\Restore($fpbx);
$restore->tmpdir = $root . '/silent-archive';
$restore->configs = $backup->configs;
$restore->runRestore();
check(count($service->all()) === 2 && $restore->logs === [] && $calls === $beforeCalls,
    'Restore preserves intentional silence without regeneration or warnings');

// A stale legacy audio reference must never turn an empty announcement into audible playback.
$stale = $service->get($silentId);
$stale['audio_file'] = $previous['audio_file'];
$silentMissing = $service->restoreRows([$stale], [basename($previous['audio_file']) => $previous['audio_file']]);
check($silentMissing === [] && $service->get($silentId)['audio_file'] === null && count($service->all()) === 1,
    'Silent restore ignores stale audio even when a WAV is included in the archive');
$service->restoreRows($backup->configs['announcements']);

$gql->canRead = true;
$gql->canWrite = true;
$silentInput = array_replace($silentData, ['description' => 'Silent GraphQL']);
$silentRecord = $mutations['addAnnouncementtts']['mutateAndGetPayload']($silentInput);
check($silentRecord['text'] === '' && $silentRecord['audio_file'] === null && $calls === $beforeCalls,
    'GraphQL create accepts empty text without a provider call');
$silentInput['announcementtts_id'] = $silentRecord['announcementtts_id'];
$silentInput['regenerate'] = true;
$silentRecord = $mutations['updateAnnouncementtts']['mutateAndGetPayload']($silentInput);
check($silentRecord['audio_file'] === null && $calls === $beforeCalls,
    'GraphQL update preserves intentional silence with regeneration requested');
$mutations['removeAnnouncementtts']['mutateAndGetPayload'](['id' => $silentRecord['announcementtts_id']]);

$form = file_get_contents(dirname(__DIR__) . '/views/form.php');
preg_match('/<textarea\b[^>]*id="text"[^>]*>/', $form, $textarea);
check(isset($textarea[0]) && !preg_match('/\brequired\b/', $textarea[0]),
    'Browser form permits an empty announcement text');
$broken = false;
$service->save($silentId, array_replace($silentData, ['text' => 'Speech enabled again.']));
check($service->get($silentId)['audio_file'] !== null && $calls === $beforeCalls + 1,
    'Changing empty text to speech generates a new validated WAV');
$service->delete($silentId);
$service->save($id, $data);
