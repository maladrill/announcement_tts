<?php
// License: GPLv3+. Copyright Sangoma Technologies and SQS Polska.
namespace FreePBX\modules;
require_once __DIR__ . '/includes/Service.php';
use FreePBX\modules\Announcementtts\Service;

class Announcementtts extends \FreePBX_Helpers implements \BMO {
    private $freepbx;
    private Service $service;
    private ?string $formError = null;
    private bool $formSaved = false;

    public function __construct($freepbx = null) {
        parent::__construct($freepbx);
        $this->freepbx = $freepbx;
        $root = $freepbx->Config->get('ASTVARLIBDIR') ?: '/var/lib/asterisk';
        $this->service = new Service($freepbx->Database, rtrim($root, '/') . '/sounds/custom', function (): array {
            return array_keys($this->freepbx->Modules->getDestinations());
        });
    }
    public function service(): Service { return $this->service; }
    public function getAnnouncementstts() { return $this->service->all(); }
    public function getAnnouncementttsByID($id) { return $this->service->get($id); }
    public function getALLAnnouncementstts($id = null) {
        $id = $id ? Service::id($id) : null;
        return array_values(array_column(array_filter($this->getAnnouncementstts(), static fn($r) => $id === null || (int) $r['announcementtts_id'] !== $id), 'description'));
    }
    public function addAnnouncementtts($description, $post_dest, $text = '', $language = 'pl', $voice = null, $backend = 'realtime') {
        $id = $this->service->save(null, compact('description', 'post_dest', 'text', 'language', 'voice', 'backend'));
        needreload();
        return $id;
    }
    public function editAnnouncementtts($id, $description, $post_dest, $text, $language = 'pl', $voice = null, $regenerate = false, $backend = null) {
        $id = $this->service->save(Service::id($id), compact('description', 'post_dest', 'text', 'language', 'voice', 'backend'), (bool) $regenerate);
        needreload();
        return $id;
    }
    public function deleteAnnouncementtts($id) {
        $this->service->delete($id);
        needreload();
        return true;
    }
    public function csrfToken(): string {
        if (empty($_SESSION['announcementtts_csrf'])) { $_SESSION['announcementtts_csrf'] = bin2hex(random_bytes(32)); }
        return $_SESSION['announcementtts_csrf'];
    }
    public function doConfigPageInit($page) {
        if ($page !== 'announcementtts') { return; }
        $action = $_REQUEST['action'] ?? '';
        if (isset($_POST['delete'])) { $action = 'delete'; }
        if (!in_array($action, ['add', 'edit', 'delete'], true)) { return; }
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new \InvalidArgumentException('Changes require POST.'); }
            $token = $_POST['announcementtts_csrf'] ?? null;
            if (!is_string($token) || !isset($_SESSION['announcementtts_csrf']) || !hash_equals($_SESSION['announcementtts_csrf'], $token)) {
                throw new \InvalidArgumentException('Session verification failed. Reload the form and retry.');
            }
            $id = $_POST['announcementtts_id'] ?? null;
            if ($action === 'delete') {
                $this->deleteAnnouncementtts($id);
            } else {
                $description = $_POST['description'] ?? null;
                $post_dest = $_POST['post_dest'] ?? '';
                if (!empty($_POST['goto0']) && is_string($_POST['goto0'])) { $post_dest = $_POST[$_POST['goto0'] . '0'] ?? ''; }
                $text = $_POST['text'] ?? null;
                $language = $_POST['language'] ?? 'pl';
                $voice = $_POST['voice'] ?? null;
                $backend = $_POST['backend'] ?? ($action === 'add' ? 'realtime' : null);
                if ($action === 'add') { $this->addAnnouncementtts($description, $post_dest, $text, $language, $voice, $backend); }
                else { $this->editAnnouncementtts($id, $description, $post_dest, $text, $language, $voice, isset($_POST['regenerate']), $backend); }
            }
            $this->formSaved = true;
            // Post/Redirect/Get prevents repeated paid generation on a browser refresh.
            if (!headers_sent()) { header('Location: config.php?display=announcementtts', true, 303); exit; }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->formError = $e instanceof \PDOException ? _('Database operation failed. Existing audio was preserved.') : $e->getMessage();
            error_log('[announcementtts] Save failed: ' . get_class($e));
        } catch (\Throwable $e) {
            $this->formError = _('Unable to save the announcement. Check the server configuration and retry.');
            error_log('[announcementtts] Save failed: ' . get_class($e));
        }
    }
    public function formError(): ?string { return $this->formError; }
    public function formSaved(): bool { return $this->formSaved; }
    public function ajaxRequest($req, &$setting) {
        $setting['authenticate'] = true;
        return in_array($req, ['getJSON', 'audio'], true);
    }
    private function requireGuiAccess(): void {
        $user = $_SESSION['AMP_user'] ?? null;
        if (!is_object($user) || !$user->checkSection('announcementtts')) { throw new \RuntimeException('Access denied.'); }
    }
    public function ajaxHandler() { $this->requireGuiAccess(); return $this->getAnnouncementstts(); }
    public function ajaxCustomHandler() {
        if (($_REQUEST['command'] ?? '') !== 'audio') { return false; }
        try { $this->requireGuiAccess(); }
        catch (\Throwable $e) { http_response_code(403); return true; }
        try {
            $row = $this->getAnnouncementttsByID($_GET['id'] ?? null);
            $path = $this->service->audioPath($row['audio_file'] ?? '');
            if ($path === null) { throw new \RuntimeException('Audio is unavailable.'); }
            $file = fopen($path, 'rb');
            if ($file === false) { throw new \RuntimeException('Audio is unavailable.'); }
        } catch (\Throwable $e) { http_response_code(404); return true; }
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('Content-Length: ' . fstat($file)['size']);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') { fpassthru($file); }
        fclose($file);
        return true;
    }
    public function getActionBar($request) {
        if (($request['display'] ?? '') !== 'announcementtts' || ($_GET['view'] ?? '') !== 'form') { return []; }
        return [
            'reset' => ['name' => 'reset', 'id' => 'reset', 'value' => _('Reset')],
            'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')],
        ];
    }
    public function getRightNav($request) {
        return ($_GET['view'] ?? '') === 'form' ? load_view(__DIR__ . '/views/rnav.php', []) : null;
    }
    public function install() {}
    public function uninstall() { $this->freepbx->Database->exec('DROP TABLE IF EXISTS announcementtts'); }
    public function backup($backup) {}
    public function restore($backup) {}
}
