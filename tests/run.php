<?php
// Run with: php tests/run.php. All providers are mocked; no API key is needed.
ob_start();
set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once dirname(__DIR__) . '/includes/Service.php';
use FreePBX\modules\Announcementtts\Service;
use FreePBX\modules\Announcementtts\TtsGenerator;

$tests = 0;
function check(bool $condition, string $message): void {
    global $tests;
    if (!$condition) { throw new RuntimeException($message); }
    $tests++;
    echo 'PASS ' . $message . PHP_EOL;
}
function fails(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $e) { check(true, $message); return; }
    throw new RuntimeException('Expected failure: ' . $message);
}
function wav(string $path, int $rate = 8000): void {
    $data = str_repeat("\0", 1600);
    file_put_contents($path, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data);
}
class OfflineGenerator extends TtsGenerator {
    protected function request(string $text, string $voice, string $output): void { wav($output, 24000); }
}
class BrokenGenerator extends TtsGenerator {
    protected function request(string $text, string $voice, string $output): void { file_put_contents($output, 'invalid audio'); }
}

$root = sys_get_temp_dir() . '/announcementtts-tests-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE announcementtts (announcementtts_id INTEGER PRIMARY KEY AUTOINCREMENT, description TEXT, text TEXT, language TEXT, voice TEXT, audio_file TEXT, post_dest TEXT)');
$calls = 0;
$broken = false;
$destinations = ['app-blackhole,hangup,1', 'ext-local,100,1', 'app-announcementtts-1,s,1'];
$generate = function ($text, $voice, $dir) use (&$calls, &$broken): string {
    $calls++;
    if ($broken) { throw new RuntimeException('Mock provider failure.'); }
    $name = 'announcementtts_' . bin2hex(random_bytes(16)) . '.wav';
    wav($dir . '/' . $name);
    return $name;
};
$service = new Service($db, $root, static fn() => $destinations, $generate);
$data = ['description' => 'Zażółć', 'text' => 'Dzień dobry.', 'language' => 'pl', 'voice' => 'fable', 'post_dest' => 'app-blackhole,hangup,1'];

foreach ([0, -1, '1 OR 1=1', '1.0', [], true, null, '99999999999999999999999'] as $id) {
    fails(static fn() => Service::id($id), 'Reject malformed ID ' . json_encode($id));
}
foreach (['', "ext-local,100,1\n", 'ext-local,${EXTEN},1', 'ext-local,100,1;other', 'ext-local,100,0'] as $dest) {
    fails(static fn() => Service::destination($dest), 'Reject unsafe destination ' . json_encode($dest));
}
foreach ([['description' => str_repeat('ą', 51)], ['text' => str_repeat('ą', 4097)], ['text' => []], ['text' => "\xff"], ['voice' => 'ballad'], ['language' => 'xx'], ['post_dest' => 'ext-local,999,1']] as $override) {
    fails(static fn() => $service->save(null, array_replace($data, $override)), 'Validate server input ' . implode(',', array_keys($override)));
}
check($service->all() === [] && $calls === 0, 'Validation errors do not create records or call provider');
$id = $service->save(null, $data);
$saved = $service->get($id);
check($id === 1 && $saved['text'] === $data['text'] && is_file($saved['audio_file']), 'Create a complete announcement');
$before = $calls;
$service->save($id, array_replace($data, ['description' => 'Renamed', 'post_dest' => 'ext-local,100,1']));
check($calls === $before, 'Changing metadata/destination does not call the provider');
$data = array_replace($data, ['description' => 'Renamed', 'post_dest' => 'ext-local,100,1']);
$saved = $service->get($id);
$broken = true;
fails(static fn() => $service->save($id, array_replace($data, ['text' => 'Changed'])), 'Provider error is propagated');
check($service->get($id) === $saved && is_file($saved['audio_file']), 'Provider failure preserves old row and audio');
fails(static fn() => $service->save(null, array_replace($data, ['description' => 'New failing record'])), 'Failed create is rejected');
check(count($service->all()) === 1, 'Failed create leaves no incomplete row');
$broken = false;
$service->save($id, array_replace($data, ['text' => 'Changed']));
check($service->get($id)['text'] === 'Changed' && is_file($saved['audio_file']), 'Retry succeeds and retains audio used by loaded dialplan');
$data['text'] = 'Changed';
$before = $calls;
$service->save($id, $data, true);
check($calls === $before + 1, 'Explicit regeneration works with unchanged fields');
fails(static fn() => $service->save(null, $data), 'Duplicate descriptions are rejected on server');
fails(static fn() => $service->save($id, array_replace($data, ['post_dest' => 'app-announcementtts-1,s,1'])), 'Reject a direct routing loop');
$lock = fopen($root . '/.announcementtts.lock', 'c');
flock($lock, LOCK_EX);
fails(static fn() => $service->save($id, $data), 'Concurrent writer is rejected without changing data');
flock($lock, LOCK_UN); fclose($lock);

$saved = $service->get($id);
$db->exec("CREATE TRIGGER reject_update BEFORE UPDATE ON announcementtts BEGIN SELECT RAISE(ABORT, 'test failure'); END");
$filesBefore = glob($root . '/announcementtts_*.wav');
fails(static fn() => $service->save($id, array_replace($data, ['text' => 'DB failure'])), 'Database failure is propagated');
check($service->get($id) === $saved && glob($root . '/announcementtts_*.wav') === $filesBefore, 'Database rollback removes only unpublished replacement audio');
$db->exec('DROP TRIGGER reject_update');
$service->changeDestination('ext-local,100,1', 'app-blackhole,hangup,1');
check($service->get($id)['post_dest'] === 'app-blackhole,hangup,1', 'Destination hook uses bound SQL parameters');
$legacy = $root . '/announcementtts_42.wav'; wav($legacy);
check($service->audioPath($legacy) === $legacy, 'Existing numeric filenames remain supported');
check($service->audioPath('/etc/passwd') === null && $service->audioPath($root . '/../announcementtts_42.wav') === null, 'Audio paths are confined to module files');
symlink($legacy, $root . '/announcementtts_43.wav');
check($service->audioPath($root . '/announcementtts_43.wav') === null, 'Audio endpoint rejects symlinks');

$rows = $service->all();
$path = $rows[0]['audio_file'];
$sources = [basename($path) => $path];
$missing = $service->restoreRows($rows, $sources);
$restored = $service->get($id);
check($missing === [] && $restored['text'] === $rows[0]['text'] && $restored['voice'] === $rows[0]['voice'] && $restored['announcementtts_id'] === $id, 'Restore preserves all TTS fields and IDs');
check($restored['audio_file'] !== $path && hash_file('sha256', $restored['audio_file']) === hash_file('sha256', $path), 'Restore relocates audio to a validated immutable filename');
$before = $service->all();
fails(static fn() => $service->restoreRows([$rows[0], $rows[0]], $sources), 'Reject malformed backup with duplicate IDs');
check($service->all() === $before, 'Failed restore preserves existing configuration');
$db->exec("CREATE TRIGGER reject_insert BEFORE INSERT ON announcementtts BEGIN SELECT RAISE(ABORT, 'test failure'); END");
$filesBefore = glob($root . '/announcementtts_*.wav');
fails(static fn() => $service->restoreRows($rows, $sources), 'Restore database failure is propagated');
check($service->all() === $before && glob($root . '/announcementtts_*.wav') === $filesBefore, 'Failed restore rolls back table and staged audio');
$db->exec('DROP TRIGGER reject_insert');
$missing = $service->restoreRows($rows);
check($missing === [$id] && $service->get($id)['audio_file'] === null, 'Old config-only backups report missing audio without paid regeneration');
$before = $calls;
$service->save($id, $data);
check($calls === $before + 1, 'Saving unchanged restored metadata regenerates missing audio');

if (is_executable('/usr/bin/ffmpeg')) {
    $name = (new OfflineGenerator())->generate('Offline', 'fable', $root);
    TtsGenerator::assertWav($root . '/' . $name);
    check((fileperms($root . '/' . $name) & 0777) === 0640, 'Real FFmpeg produces verified 8 kHz mono PCM with restricted permissions');
    fails(static fn() => (new BrokenGenerator())->generate('Offline', 'fable', $root), 'Real FFmpeg rejects malformed provider audio');
    check(glob($root . '/.announcementtts-input-*') === [] && glob($root . '/.announcementtts-output-*') === [], 'Generation cleans temporary files on success and failure');
}

require __DIR__ . '/adapters.php';
check($service->get($id) !== [], 'Adapter tests completed');
$path = $service->get($id)['audio_file'];
$service->delete($id);
check($service->get($id) === [] && is_file($path), 'Delete removes row while preserving audio needed by in-progress calls');
echo $tests . ' checks passed.' . PHP_EOL;
ob_end_flush();
