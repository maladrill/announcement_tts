<?php
// Exercise the actual installer and FreePBX-style registration against an isolated MariaDB schema.
if (PHP_SAPI !== 'cli') { throw new RuntimeException('This test must run from the command line.'); }
set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$dsn = getenv('ANNOUNCEMENTTTS_INSTALL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:unix_socket=')
    || !preg_match('/;dbname=announcementtts_install_test(?:;|$)/D', $dsn)) {
    throw new RuntimeException('Set a Unix-socket DSN for the isolated announcementtts_install_test schema only.');
}

class InstallerDatabase extends PDO {
    // The BMO PDO connection returns a quoted literal, unlike the legacy DB wrapper.
    public function escapeSimple($value) { return $this->quote($value); }
}
class InstallerLegacyDatabase {
    public function __construct(private PDO $connection) {}
    public function escapeSimple($value) { return substr($this->connection->quote($value), 1, -1); }
    public function query($sql) { return $this->connection->query($sql); }
}
class FreePBX {
    public static PDO $database;
    public static string $root;
    public static function Database() { return self::$database; }
    public static function Config() {
        return new class {
            public function get($key) {
                if ($key !== 'ASTVARLIBDIR') { throw new RuntimeException('Unexpected configuration key.'); }
                return FreePBX::$root;
            }
        };
    }
}
class InstallerHarness {
    public function includeScript($filename, $modulename): void {
        // Match module_functions::_doinclude(): its $db is a reference to the global wrapper.
        global $db, $amp_conf, $asterisk_conf;
        $expectedFilename = $filename;
        $expectedModule = $modulename;
        include $filename;
        installCheck($filename === $expectedFilename && $modulename === $expectedModule,
            'Installer preserves the include caller variables');
    }
    public function register(string $name, string $version, bool $update = false): void {
        global $db;
        // Match FreePBX's registration statements, including its legacy escaping contract.
        $escapedName = $db->escapeSimple($name);
        $escapedVersion = $db->escapeSimple($version);
        $sql = $update
            ? "UPDATE modules SET version='$escapedVersion' WHERE modulename='$escapedName'"
            : "INSERT INTO modules (modulename, version, enabled) VALUES ('$escapedName', '$escapedVersion', 1)";
        $db->query($sql);
    }
}
$checks = 0;
function installCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

$connection = new InstallerDatabase($dsn, getenv('ANNOUNCEMENTTTS_INSTALL_DB_USER') ?: 'runner', null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($connection->query('SELECT DATABASE()')->fetchColumn() !== 'announcementtts_install_test') {
    throw new RuntimeException('Refusing to modify a schema other than announcementtts_install_test.');
}
$testRoot = sys_get_temp_dir() . '/announcementtts-installer-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
FreePBX::$database = $connection;
FreePBX::$root = $testRoot;
define('FREEPBX_IS_AUTH', true);
$db = new InstallerLegacyDatabase($connection);
$expectedDb = $db;
$amp_conf = ['scope_marker' => 'preserve_amp_conf'];
$asterisk_conf = ['scope_marker' => 'preserve_asterisk_conf'];
$harness = new InstallerHarness();
$version = (string) simplexml_load_file(dirname(__DIR__) . '/module.xml')->version;

try {
    $connection->exec('DROP TABLE IF EXISTS modules, announcementtts');
    $connection->exec('CREATE TABLE modules (modulename VARCHAR(50) PRIMARY KEY, version VARCHAR(40), enabled INT) ENGINE=InnoDB');

    // Reproduce the reported 1064 before checking the fixed installation path.
    $db = $connection;
    $reproduced = false;
    try { $harness->register('announcementtts', '17.0.4'); }
    catch (PDOException $e) { $reproduced = $e->getCode() === '42000' && ($e->errorInfo[1] ?? null) === 1064; }
    finally { $db = $expectedDb; }
    installCheck($reproduced, 'Replacing the legacy wrapper reproduces MariaDB SQLSTATE 42000 / 1064');

    $harness->includeScript(dirname(__DIR__) . '/install.php', 'announcementtts');
    installCheck($db === $expectedDb, 'Fresh installation preserves the legacy global database wrapper');
    installCheck($amp_conf === ['scope_marker' => 'preserve_amp_conf']
        && $asterisk_conf === ['scope_marker' => 'preserve_asterisk_conf'], 'Installer preserves FreePBX configuration globals');
    $harness->register('announcementtts', $version);
    installCheck($connection->query("SELECT version FROM modules WHERE modulename='announcementtts'")->fetchColumn() === $version,
        'FreePBX-style registration succeeds after a fresh installation');
    $fields = $connection->query('SHOW COLUMNS FROM announcementtts')->fetchAll(PDO::FETCH_COLUMN);
    installCheck(count(array_intersect($fields, ['announcementtts_id', 'description', 'text', 'language', 'voice', 'audio_file', 'post_dest'])) === 7,
        'Fresh MariaDB table contains every required announcement field');
    installCheck(is_dir($testRoot . '/sounds/custom'), 'Installer creates the custom sounds directory');

    // Simulate upgrading a legacy MyISAM table while preserving data and obsolete fields.
    $connection->exec('DROP TABLE announcementtts');
    $connection->exec('CREATE TABLE announcementtts (announcementtts_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, description VARCHAR(50), recording_id INT) ENGINE=MyISAM');
    $connection->exec("INSERT INTO announcementtts (description, recording_id) VALUES ('Existing announcement', 123)");
    $harness->includeScript(dirname(__DIR__) . '/install.php', 'announcementtts');
    installCheck($db === $expectedDb, 'Upgrade preserves the legacy global database wrapper');
    $harness->register('announcementtts', $version, true);
    installCheck($connection->query("SELECT version FROM modules WHERE modulename='announcementtts'")->fetchColumn() === $version,
        'FreePBX-style version update succeeds after upgrading');
    $engine = $connection->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='announcementtts'")->fetchColumn();
    installCheck(strcasecmp($engine, 'InnoDB') === 0, 'Upgrade converts the legacy table to transactional InnoDB');
    $row = $connection->query('SELECT * FROM announcementtts')->fetch(PDO::FETCH_ASSOC);
    installCheck($row['description'] === 'Existing announcement' && (int) $row['recording_id'] === 123
        && array_key_exists('text', $row) && array_key_exists('audio_file', $row), 'Upgrade preserves existing data and adds missing TTS fields');

    $harness->includeScript(dirname(__DIR__) . '/install.php', 'announcementtts');
    $harness->register('announcementtts', $version, true);
    installCheck($connection->query('SELECT COUNT(*) FROM announcementtts')->fetchColumn() == 1,
        'Repeated installation is idempotent and does not lose existing rows');
    installCheck($db === $expectedDb, 'Repeated installation still preserves legacy escaping');
} finally {
    $connection->exec('DROP TABLE IF EXISTS modules, announcementtts');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($testRoot);
}
echo $checks . ' MariaDB installation checks passed.' . PHP_EOL;
