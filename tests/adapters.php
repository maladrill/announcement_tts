<?php
// Minimal framework doubles test adapter contracts; live FreePBX tests are documented separately.
namespace {
    if (!function_exists('_')) { function _($text) { return $text; } }
    interface BMO {}
    class FreePBX_Helpers { public function __construct($freepbx) {} }
    class FreePBX {
        public static $module;
        public static function Announcementtts() { return self::$module; }
    }
    function needreload() { $GLOBALS['reloadCount'] = ($GLOBALS['reloadCount'] ?? 0) + 1; }
    class TestExtension {
        public function __construct(public $data) {}
    }
    class ext_answer extends TestExtension {}
    class ext_wait extends TestExtension {}
    class ext_noop extends TestExtension {}
    class ext_playback extends TestExtension {}
    class ext_goto extends TestExtension {}
    class FakeType {
        public array $callbacks = [];
        public function __construct(private string $name) {}
        public function getObject() { return $this->name; }
        public function getConnectionType() { return $this->name . 'Connection'; }
        public function __call($name, $arguments) { $this->callbacks[$name] = $arguments[0]; }
    }
    class FakeTypes {
        public array $types = [];
        public function get($name) { return $this->types[$name] ??= new FakeType($name); }
        public function create($name) { return $this->get($name); }
    }
}
namespace FreePBX\modules\Api\Gql {
    class Base {
        protected $freepbx;
        protected $typeContainer;
        protected $module;
        public bool $canRead = true;
        public bool $canWrite = true;
        public function __construct($freepbx, $container, $module) { $this->freepbx = $freepbx; $this->typeContainer = $container; $this->module = $module; }
        protected function checkAllReadScope() { return $this->canRead; }
        protected function checkAllWriteScope() { return $this->canWrite; }
        protected function getNodeDefinition() { return ['nodeInterface' => 'Node']; }
    }
}
namespace GraphQL\Type\Definition {
    class Type {
        public static function nonNull($type) { return $type . '!'; }
        public static function string() { return 'String'; }
        public static function int() { return 'Int'; }
        public static function id() { return 'ID'; }
        public static function boolean() { return 'Boolean'; }
        public static function listOf($type) { return '[' . $type . ']'; }
    }
}
namespace GraphQLRelay {
    class Relay {
        public static function mutationWithClientMutationId($config) { return $config; }
        public static function connectionArgs() { return []; }
        public static function connectionFromArray($rows, $args) { return ['edges' => array_map(static fn($row) => ['node' => $row], $rows)]; }
        public static function globalIdField($name, $resolver) { return ['type' => 'ID', 'resolve' => $resolver]; }
    }
}
namespace FreePBX\modules\Backup {
    class BackupBase {
        public array $configs = [];
        public array $files = [];
        public array $logs = [];
        public function __construct(public $FreePBX) {}
        public function addDependency($name) {}
        public function addConfigs($configs) { $this->configs = $configs; }
        public function addFile($filename, $path, $base, $type) { $this->files[] = compact('filename', 'path', 'base', 'type'); }
        protected function log($message, $level = 'INFO') { $this->logs[] = [$message, $level]; }
    }
    class RestoreBase {
        public array $configs = [];
        public array $files = [];
        public array $logs = [];
        public string $tmpdir;
        public function __construct(public $FreePBX) {}
        public function getConfigs() { return $this->configs; }
        public function getFiles() { return $this->files; }
        protected function log($message, $level = 'INFO') { $this->logs[] = [$message, $level]; }
    }
}
namespace {
    require dirname(__DIR__) . '/Announcementtts.class.php';
    $fpbx = new stdClass();
    $fpbx->Database = $db;
    $fpbx->Config = new class {
        public function get($key) { return '/var/lib/asterisk'; }
    };
    $fpbx->Modules = new class {
        public function getDestinations() { return ['app-blackhole,hangup,1' => [], 'ext-local,100,1' => []]; }
    };
    $module = new \FreePBX\modules\Announcementtts($fpbx);
    (new ReflectionProperty($module, 'service'))->setValue($module, $service);
    $fpbx->Announcementtts = $module;
    FreePBX::$module = $module;
    check(count($module->getALLAnnouncementstts()) === count($service->all()), 'BMO description list executes without an excluded ID');
    $settings = [];
    check($module->ajaxRequest('audio', $settings) && $settings['authenticate'] === true, 'Audio AJAX requires framework authentication');
    $_SESSION = [];
    $_REQUEST = ['action' => 'delete'];
    $_POST = ['announcementtts_id' => $id];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $module->doConfigPageInit('announcementtts');
    check($module->formError() !== null && $service->get($id) !== [], 'Tokenless form POST cannot delete an announcement');
    $module->csrfToken();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = ['announcementtts_id' => $id, 'announcementtts_csrf' => $_SESSION['announcementtts_csrf']];
    $module->doConfigPageInit('announcementtts');
    check($service->get($id) !== [], 'GET cannot mutate an announcement even with a valid token');
    $_REQUEST = ['command' => 'audio'];
    ob_start(); $handled = $module->ajaxCustomHandler(); ob_end_clean();
    check($handled && http_response_code() === 403, 'Audio preview denies a session without module permission');
    http_response_code(200);

    require dirname(__DIR__) . '/functions.inc.php';
    $db->prepare('UPDATE announcementtts SET description = ? WHERE announcementtts_id = ?')->execute(['${LEN(test)}', $id]);
    $GLOBALS['ext'] = new class {
        public array $commands = [];
        public function add($context, $extension, $label, $command) { $this->commands[] = [$context, $command->data]; }
    };
    announcementtts_get_config('asterisk');
    $dialplan = json_encode($GLOBALS['ext']->commands);
    check(!str_contains($dialplan, '${LEN(test)}') && str_contains($dialplan, 'AnnouncementTTS ID ' . $id), 'User description cannot inject an Asterisk dialplan expression');
    check(count(announcementtts_check_destinations(['ext-local,100,1'])) === 1, 'Destination usage hook reports referenced targets');
    $db->prepare('UPDATE announcementtts SET description = ? WHERE announcementtts_id = ?')->execute([$data['description'], $id]);

    require dirname(__DIR__) . '/Api/Gql/Announcementtts.php';
    $types = new FakeTypes();
    $gql = new \FreePBX\modules\Announcementtts\Api\Gql\Announcementtts($fpbx, $types, 'announcementtts');
    $gql->initializeTypes();
    $gql->postInitializeTypes();
    $mutations = ($gql->mutationCallback())();
    $input = array_replace($data, ['description' => 'GraphQL record']);
    $record = $mutations['addAnnouncementtts']['mutateAndGetPayload']($input);
    check($record['post_dest'] === $input['post_dest'] && $record['text'] === $input['text'], 'GraphQL add passes explicit TTS fields and destination');
    $input['announcementtts_id'] = $record['announcementtts_id'];
    $input['text'] = 'Updated over GraphQL';
    $updated = $mutations['updateAnnouncementtts']['mutateAndGetPayload']($input);
    check($updated['announcementtts_id'] === $record['announcementtts_id'] && $updated['text'] === $input['text'], 'GraphQL update resolves the actual edited ID');
    $resolved = ($types->get('destination')->callbacks['addResolveValueCallback'])('app-announcementtts-' . $record['announcementtts_id'] . ',s,1');
    check($resolved['announcementtts_id'] === $record['announcementtts_id'], 'GraphQL destination resolver parses the complete prefix');
    $removed = $mutations['removeAnnouncementtts']['mutateAndGetPayload'](['id' => $record['announcementtts_id']]);
    check($removed['id'] === $record['announcementtts_id'] && $service->get($record['announcementtts_id']) === [], 'GraphQL remove invokes the implemented delete method');
    $gql->canRead = false; $gql->canWrite = false;
    check($gql->queryCallback() === null && $gql->mutationCallback() === null, 'GraphQL callbacks enforce read/write scope');
    check(($types->get('destination')->callbacks['addResolveValueCallback'])('app-announcementtts-' . $id . ',s,1') === null, 'GraphQL destination resolver does not bypass read scope');

    require dirname(__DIR__) . '/Backup.php';
    require dirname(__DIR__) . '/Restore.php';
    $backup = new \FreePBX\modules\Announcementtts\Backup($fpbx);
    $backup->runBackup('test', 'offline');
    check($backup->configs['schema'] === 1 && count($backup->files) === 1 && $backup->files[0]['type'] === 'announcementtts-audio', 'FreePBX backup adapter includes configuration and current WAV');
    $restore = new \FreePBX\modules\Announcementtts\Restore($fpbx);
    $restore->tmpdir = $root . '/archive';
    $restore->configs = $backup->configs;
    foreach ($backup->files as $file) {
        $source = $restore->tmpdir . '/files' . $file['path'] . '/' . $file['filename'];
        mkdir(dirname($source), 0700, true);
        copy($file['path'] . '/' . $file['filename'], $source);
        $restore->files[] = new class($source) extends SplFileInfo {
            public function getType(): string { return 'announcementtts-audio'; }
        };
    }
    $restore->runRestore();
    check($service->get($id)['text'] === $backup->configs['announcements'][0]['text'] && $restore->logs === [], 'FreePBX restore adapter consumes archived WAV and preserves text');
    $restore->files = [new class($legacy) extends SplFileInfo {
        public function getType(): string { return 'announcementtts-audio'; }
    }];
    fails(static fn() => $restore->runRestore(), 'FreePBX restore adapter rejects files outside the extracted archive');
    $restore->files = [];
    $restore->configs = $backup->configs['announcements'];
    $restore->runRestore();
    check($service->get($id)['audio_file'] === null && count($restore->logs) === 1, 'FreePBX restore adapter supports old configuration-only backups with an explicit warning');
    $service->save($id, $data);
}
