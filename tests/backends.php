<?php
use FreePBX\modules\Announcementtts\TtsGenerator;
// Verify engine-specific validation, paid request selection and old backup compatibility.
$mainRows = $service->all();
$mainSources = [];
foreach ($mainRows as $row) { $mainSources[basename($row['audio_file'])] = $row['audio_file']; }
check(count(TtsGenerator::voices('tts-1')) === 9 && count(TtsGenerator::voices('realtime')) === 10, 'Both engines expose their official voice sets');
foreach ([['backend' => 'unknown'], ['backend' => []], ['backend' => 'realtime', 'voice' => 'nova'], ['backend' => 'tts-1', 'voice' => 'cedar']] as $override) {
    fails(static fn() => $service->save(null, array_replace($data, $override)), 'Invalid engine or cross-engine voice is rejected before generation');
}
$engineData = array_replace($data, ['description' => 'Engine selection', 'voice' => 'alloy']);
$engineId = $service->save(null, $engineData);
check($generatedBackend === 'tts-1', 'Service passes the saved tts-1 engine to the audio generator');
$engineData['backend'] = 'realtime';
$beforeCalls = $calls;
$oldEngineRow = $service->get($engineId);
$service->save($engineId, $engineData);
check($calls === $beforeCalls + 1 && $generatedBackend === 'realtime'
    && $service->get($engineId)['audio_file'] !== $oldEngineRow['audio_file'], 'Changing only the engine regenerates even a voice shared by both engines');
$beforeCalls = $calls;
$service->save($engineId, array_replace($engineData, ['description' => 'Engine renamed']));
check($calls === $beforeCalls, 'An unchanged Realtime recording is reused for metadata edits');
$previousEngine = $service->get($engineId);
$broken = true;
fails(static fn() => $service->save($engineId, array_replace($engineData, ['backend' => 'tts-1'])), 'Failure while switching engines is propagated');
check($service->get($engineId) === $previousEngine, 'Failed engine switch preserves the original engine, text, voice and audio');
$broken = false;
$defaults = $data;
unset($defaults['voice'], $defaults['backend']);
$defaults['description'] = 'Default engine';
$defaultId = $service->save(null, $defaults);
check($service->get($defaultId)['backend'] === 'realtime' && $service->get($defaultId)['voice'] === 'marin', 'New records without explicit options default to Realtime API and Marin');
$beforeCalls = $calls;
$defaults['description'] = 'Default renamed';
$service->save($defaultId, $defaults);
check($calls === $beforeCalls && $service->get($defaultId)['voice'] === 'marin', 'Older editing callers preserve saved engine and voice when omitted');
$beforeCalls = $calls;
$service->save($defaultId, array_replace($defaults, ['text' => '', 'backend' => 'tts-1']));
check($calls === $beforeCalls && $service->get($defaultId)['audio_file'] === null && $service->get($defaultId)['voice'] === 'fable', 'Silent engine switches use valid defaults and never call the API');
$rows = $service->all();
$sources = [];
foreach ($rows as $row) { if ($row['audio_file']) { $sources[basename($row['audio_file'])] = $row['audio_file']; } }
$service->restoreRows($rows, $sources);
check($service->get($engineId)['backend'] === 'realtime' && $service->get($engineId)['voice'] === 'alloy', 'Backup restore retains Realtime engine and voice');
$legacyRows = $mainRows;
unset($legacyRows[0]['backend']);
$beforeCalls = $calls;
$service->restoreRows($legacyRows, $mainSources);
check($service->get($id)['backend'] === 'tts-1' && $service->get($id)['voice'] === 'fable' && $calls === $beforeCalls, 'Backups without an engine restore as tts-1 without API requests');
