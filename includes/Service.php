<?php
namespace FreePBX\modules\Announcementtts;
require_once __DIR__ . '/TtsGenerator.php';

class Service {
    public const LANGUAGES = ['af','ar','hy','az','be','bs','bg','ca','zh','hr','cs','da','nl','en','et','fi','fr','gl','de','el','he','hi','hu','is','id','it','ja','kn','kk','ko','lv','lt','mk','ms','mr','mi','ne','no','fa','pl','pt','ro','ru','sr','sk','sl','es','sw','sv','tl','ta','th','tr','uk','ur','vi','cy'];
    private $destinations;
    private $generate;

    public function __construct(private \PDO $db, private string $directory, callable $destinations, ?callable $generate = null) {
        $this->directory = rtrim($directory, '/');
        $this->destinations = $destinations;
        $this->generate = $generate ?? [new TtsGenerator(), 'generate'];
    }

    public static function id($id): int {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || filter_var($id, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('Invalid announcement ID.');
        }
        return (int) $id;
    }

    public static function optionalId($id): ?int {
        // FreePBX config.php represents a missing extdisplay as false in add forms.
        return $id === null || $id === false || $id === '' ? null : self::id($id);
    }

    public static function destination($value): string {
        if (!is_string($value) || strlen($value) > 255
            || !preg_match('/^[a-zA-Z0-9_!+.*#@:\/-]+,[a-zA-Z0-9_!+.*#@:\/-]+,[1-9][0-9]*$/D', $value)) {
            throw new \InvalidArgumentException('Invalid playback destination.');
        }
        return $value;
    }

    private static function field($value, string $label, int $max, bool $allowEmpty = false): string {
        if (!is_string($value) || !preg_match('//u', $value) || strpos($value, "\0") !== false
            || mb_strlen($value, 'UTF-8') > $max || (!$allowEmpty && trim($value) === '')) {
            throw new \InvalidArgumentException('Invalid ' . $label . ' (maximum ' . $max . ' characters).');
        }
        return $value;
    }

    public static function isSilent(string $text): bool {
        return preg_match('/^[\p{Z}\s]*$/uD', $text) === 1;
    }

    public function validate(array $data, bool $registered = true): array {
        $row = [
            'description' => self::field($data['description'] ?? null, 'description', 50),
            'text' => self::field($data['text'] ?? null, 'announcement text', 4096, true),
            'language' => self::field($data['language'] ?? 'pl', 'language', 10),
            'backend' => self::field($data['backend'] ?? 'realtime', 'TTS engine', 20),
            'voice' => self::field($data['voice'] ?? TtsGenerator::defaultVoice($data['backend'] ?? 'realtime'), 'voice', 20),
            'post_dest' => self::destination($data['post_dest'] ?? null),
        ];
        // Empty and whitespace-only text explicitly disable playback.
        if (self::isSilent($row['text'])) { $row['text'] = ''; }
        if (!in_array($row['language'], self::LANGUAGES, true)) { throw new \InvalidArgumentException('Unsupported language.'); }
        if (!in_array($row['voice'], TtsGenerator::voices($row['backend']), true)) { throw new \InvalidArgumentException('Unsupported voice for selected TTS engine.'); }
        if ($registered && !in_array($row['post_dest'], ($this->destinations)(), true)) {
            throw new \InvalidArgumentException('The selected destination no longer exists.');
        }
        return $row;
    }

    public function all(): array {
        return $this->db->query('SELECT announcementtts_id, description, post_dest, `text`, language, voice, backend, audio_file FROM announcementtts ORDER BY announcementtts_id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function get($id): array {
        $stmt = $this->db->prepare('SELECT * FROM announcementtts WHERE announcementtts_id = ?');
        $stmt->execute([self::id($id)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function withLock(callable $callback) {
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new \RuntimeException('Custom sounds directory is missing or not writable.');
        }
        $path = $this->directory . '/.announcementtts.lock';
        if (is_link($path)) { throw new \RuntimeException('Invalid module lock file.'); }
        $lock = fopen($path, 'c');
        if ($lock === false) { throw new \RuntimeException('Unable to open module lock.'); }
        if (!chmod($path, 0640)) { fclose($lock); throw new \RuntimeException('Unable to protect module lock file.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Another announcement operation is running. Please retry.'); }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function save(?int $id, array $data, bool $regenerate = false): int {
        return $this->withLock(function () use ($id, $data, $regenerate): int {
            $old = $id === null ? [] : $this->get($id);
            if ($id !== null && !$old) { throw new \InvalidArgumentException('Announcement does not exist.'); }
            // Resolve omitted editing options under the same lock as generation and persistence.
            if ($old) {
                $data['backend'] = $data['backend'] ?? $old['backend'];
                if (!isset($data['voice']) && $data['backend'] === $old['backend']) { $data['voice'] = $old['voice']; }
            }
            $data = $this->validate($data);
            if ($id !== null && $data['post_dest'] === 'app-announcementtts-' . $id . ',s,1') {
                throw new \InvalidArgumentException('An announcement cannot route directly to itself.');
            }
            $stmt = $this->db->prepare('SELECT announcementtts_id FROM announcementtts WHERE description = ? AND announcementtts_id <> ?');
            $stmt->execute([$data['description'], $id ?? 0]);
            if ($stmt->fetchColumn() !== false) { throw new \InvalidArgumentException('Announcement description already exists.'); }
            $silent = $data['text'] === '';
            $changed = !$old || $regenerate || !$this->audioPath($old['audio_file'] ?? '');
            foreach (['text', 'language', 'voice', 'backend'] as $key) { $changed = $changed || ($old[$key] ?? null) !== $data[$key]; }
            $newPath = null;
            try {
                if (!$silent && $changed) {
                    $name = ($this->generate)($data['text'], $data['voice'], $this->directory, $data['backend']);
                    $newPath = $this->audioPath($this->directory . '/' . $name);
                    if ($newPath === null) { throw new \RuntimeException('Generator did not publish valid module audio.'); }
                    TtsGenerator::assertWav($newPath);
                }
                $data['audio_file'] = $silent ? null : ($newPath ?? $old['audio_file']);
                $this->db->beginTransaction();
                if ($id === null) {
                    $stmt = $this->db->prepare('INSERT INTO announcementtts (description, `text`, language, voice, backend, post_dest, audio_file) VALUES (:description, :text, :language, :voice, :backend, :post_dest, :audio_file)');
                } else {
                    $stmt = $this->db->prepare('UPDATE announcementtts SET description=:description, `text`=:text, language=:language, voice=:voice, backend=:backend, post_dest=:post_dest, audio_file=:audio_file WHERE announcementtts_id=:id');
                    $data['id'] = $id;
                }
                $stmt->execute($data);
                $savedId = $id ?? (int) $this->db->lastInsertId();
                $this->db->commit();
                // Retain retired audio: the currently loaded dialplan may still reference it until reload.
                return $savedId;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                if ($newPath !== null) { @unlink($newPath); }
                throw $e;
            }
        });
    }

    public function delete($id): void {
        $id = self::id($id);
        $this->withLock(function () use ($id): void {
            if (!$this->get($id)) { throw new \InvalidArgumentException('Announcement does not exist.'); }
            $stmt = $this->db->prepare('DELETE FROM announcementtts WHERE announcementtts_id = ?');
            $stmt->execute([$id]);
            // Audio is retained until old dialplans and in-progress calls no longer use it.
        });
    }

    public function changeDestination(string $old, string $new): void {
        self::destination($new);
        $this->withLock(function () use ($old, $new): void {
            $stmt = $this->db->prepare('UPDATE announcementtts SET post_dest = ? WHERE post_dest = ?');
            $stmt->execute([$new, $old]);
        });
    }

    public function audioPath($path): ?string {
        if (!is_string($path) || $path === '') { return null; }
        $name = basename($path);
        if (!preg_match('/^announcementtts_(?:[1-9][0-9]*|[a-f0-9]{32})\.wav$/D', $name)) { return null; }
        $expected = $this->directory . '/' . $name;
        if ($path !== $expected || is_link($expected) || !is_file($expected) || !is_readable($expected)) { return null; }
        if (realpath(dirname($expected)) !== realpath($this->directory)) { return null; }
        return $expected;
    }

    public function directory(): string { return $this->directory; }

    public function restoreRows(array $rows, array $audioSources = []): array {
        return $this->withLock(function () use ($rows, $audioSources): array {
            $prepared = [];
            $created = [];
            $missing = [];
            try {
                foreach ($rows as $row) {
                    $id = self::id($row['announcementtts_id'] ?? null);
                    if (isset($prepared[$id])) { throw new \InvalidArgumentException('Duplicate announcement ID in backup.'); }
                    // Destinations can be restored before their dependent modules are available.
                    $row['backend'] = $row['backend'] ?? 'tts-1';
                    $data = $this->validate($row, false);
                    $data['announcementtts_id'] = $id;
                    $data['audio_file'] = null;
                    $oldName = basename((string) ($row['audio_file'] ?? ''));
                    if ($data['text'] === '') {
                        // A silent announcement intentionally has no WAV, including in older backups.
                    } elseif (isset($audioSources[$oldName])) {
                        $source = $audioSources[$oldName];
                        TtsGenerator::assertWav($source);
                        $name = 'announcementtts_' . bin2hex(random_bytes(16)) . '.wav';
                        $target = $this->directory . '/' . $name;
                        $tmp = tempnam($this->directory, '.announcementtts-restore-');
                        if ($tmp === false) { throw new \RuntimeException('Unable to stage restored audio.'); }
                        try {
                            if (!copy($source, $tmp) || !chmod($tmp, 0640) || !rename($tmp, $target)) {
                                throw new \RuntimeException('Unable to restore audio.');
                            }
                        } finally { if (is_file($tmp)) { @unlink($tmp); } }
                        $created[] = $target;
                        $data['audio_file'] = $target;
                    } else { $missing[] = $id; }
                    $prepared[$id] = $data;
                }
                $this->db->beginTransaction();
                $this->db->exec('DELETE FROM announcementtts');
                $stmt = $this->db->prepare('INSERT INTO announcementtts (announcementtts_id, description, `text`, language, voice, backend, post_dest, audio_file) VALUES (:announcementtts_id, :description, :text, :language, :voice, :backend, :post_dest, :audio_file)');
                foreach ($prepared as $data) { $stmt->execute($data); }
                $this->db->commit();
                return $missing;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                foreach ($created as $path) { @unlink($path); }
                throw $e;
            }
        });
    }
}
