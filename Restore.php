<?php
namespace FreePBX\modules\Announcementtts;
use FreePBX\modules\Backup as Base;

class Restore extends Base\RestoreBase {
    public function runRestore() {
        $configs = $this->getConfigs();
        if (!is_array($configs)) { throw new \RuntimeException('Invalid announcement backup.'); }
        if (isset($configs['schema']) && $configs['schema'] !== 1) { throw new \RuntimeException('Unsupported announcement backup schema.'); }
        $rows = $configs['announcements'] ?? $configs;
        if (!is_array($rows)) { throw new \RuntimeException('Invalid announcement backup rows.'); }
        $sources = [];
        $root = realpath($this->tmpdir . '/files');
        foreach ($this->getFiles() as $file) {
            if ($file->getType() !== 'announcementtts-audio') { continue; }
            $path = $file->getPathname();
            $real = realpath($path);
            $name = $file->getFilename();
            if (!preg_match('/^announcementtts_(?:[1-9][0-9]*|[a-f0-9]{32})\.wav$/D', $name)
                || $root === false || $real === false || is_link($path) || !str_starts_with($real, $root . '/')) {
                throw new \RuntimeException('Unsafe audio path in backup.');
            }
            $sources[$name] = $real;
        }
        $this->processData($rows, $sources);
    }
    public function processData($configs, array $sources = []) {
        $missing = $this->FreePBX->Announcementtts->service()->restoreRows($configs, $sources);
        foreach ($missing as $id) {
            $this->log('Audio not included in backup for announcement ID ' . $id . '; regenerate it explicitly after restore.', 'WARNING');
        }
        needreload();
    }
    public function processLegacy($pdo, $data, $tables, $unknownTables) {
        $rows = $pdo->query('SELECT * FROM announcementtts')->fetchAll(\PDO::FETCH_ASSOC);
        $this->processData($rows);
    }
}
