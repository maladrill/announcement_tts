<?php
// Render actual module pages with the request sentinels used by FreePBX config.php.
use FreePBX\modules\Announcementtts\Service;

function load_view($view_filename_protected, $vars = []) {
    // Match FreePBX's view scope and variable extraction without loading the entire PBX.
    extract((array) $vars, EXTR_SKIP);
    ob_start();
    try { include $view_filename_protected; return ob_get_contents(); }
    finally { ob_end_clean(); }
}
function drawselects($destination, $index) {
    return '<select name="post_dest"><option value="' . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8') . '">Destination</option></select>';
}
if (!defined('FREEPBX_IS_AUTH')) { define('FREEPBX_IS_AUTH', true); }
$previousModule = FreePBX::$module;
$previousRequest = $_REQUEST;
$previousPost = $_POST;
$previousGet = $_GET;
$uiModule = new \FreePBX\modules\Announcementtts($fpbx);
(new ReflectionProperty($uiModule, 'service'))->setValue($uiModule, $service);
FreePBX::$module = $uiModule;
$fpbx->View = new class {
    public array $requests = [];
    public function destinationUsage($destinations) {
        $this->requests[] = $destinations;
        return '<div class="test-destination-usage">Usage</div>';
    }
};
$renderPage = static function (array $request): string {
    $_GET = $request;
    $_POST = [];
    $_REQUEST = $request;
    return load_view(dirname(__DIR__) . '/page.announcementtts.php');
};
$baseRequest = ['display' => 'announcementtts', 'view' => 'form'];
$beforeRows = $service->all();
$beforeCalls = $calls;
try {
    foreach ([null, false, ''] as $absent) {
        check(Service::optionalId($absent) === null, 'Optional form ID accepts absence: ' . json_encode($absent));
    }
    foreach ([0, '0', true, [], 'invalid', '1 OR 1=1', '-1'] as $invalid) {
        fails(static fn() => Service::optionalId($invalid), 'Optional form ID still rejects malformed input: ' . json_encode($invalid));
    }
    foreach (['missing', false, null, ''] as $absent) {
        $request = $baseRequest;
        if ($absent !== 'missing') { $request['extdisplay'] = $absent; }
        $html = $renderPage($request);
        check(str_contains($html, 'name="editAnnouncementtts"') && preg_match('/name="action"\s+value="add"/', $html) === 1
            && !str_contains($html, 'Invalid announcement ID.'), 'Add page renders for absent extdisplay: ' . json_encode($absent));
        check(preg_match('/name="announcementtts_id"\s+value=""/', $html) === 1 && !str_contains($html, 'btn-danger'),
            'Add page has an empty record ID and no delete action: ' . json_encode($absent));
    }
    check($fpbx->View->requests === [], 'Add page never looks up usage for an absent record ID');
    $html = $renderPage(['display' => 'announcementtts', 'extdisplay' => false]);
    check(str_contains($html, 'id="table-all"'), 'Module landing page renders its list with the FreePBX false sentinel');
    check(preg_match('/announcementtts\.js\?v=[a-f0-9]{12}/', $html) === 1,
        'List scripts use a content hash to refresh cached action handlers after updates');
    check(preg_match('/announcementtts\.css\?v=[a-f0-9]{12}/', $html) === 1,
        'List loads versioned styles for compact icon actions');
    check(preg_match('/<form\b[^>]*id="announcementtts-delete-form"[^>]*method="post"[^>]*action="config\.php\?display=announcementtts"/', $html) === 1,
        'List deletion uses a POST form targeting the module controller');
    check(preg_match('/<form\b[^>]*id="announcementtts-delete-form"[^>]*>(.*?)<\/form>/s', $html, $deleteForm) === 1
        && str_contains($deleteForm[1], 'name="action" value="delete"')
        && str_contains($deleteForm[1], 'name="announcementtts_csrf" value="' . $uiModule->csrfToken() . '"')
        && str_contains($deleteForm[1], 'name="announcementtts_id" value=""'),
        'List deletion includes a session token and a separate record ID field');
    foreach ([$id, (string) $id] as $existingId) {
        $html = $renderPage(array_replace($baseRequest, ['extdisplay' => $existingId]));
        check(preg_match('/name="action"\s+value="edit"/', $html) === 1 && str_contains($html, 'name="announcementtts_id" value="' . $id . '"'),
            'Edit page renders for a valid existing ID: ' . json_encode($existingId));
        check(str_contains($html, '<audio controls') && str_contains($html, 'btn-danger'), 'Edit page includes the stored audio preview and delete form');
    }
    $html = $renderPage(array_replace($baseRequest, ['extdisplay' => '999999']));
    check(str_contains($html, 'Announcement does not exist.') && !str_contains($html, 'name="editAnnouncementtts"'),
        'Missing records produce a handled form message');
    foreach ([0, '0', true, [], 'invalid', '1 OR 1=1', '-1'] as $invalid) {
        $html = $renderPage(array_replace($baseRequest, ['extdisplay' => $invalid]));
        check(str_contains($html, 'Invalid announcement ID.') && !str_contains($html, 'name="editAnnouncementtts"'),
            'Malformed edit IDs produce a handled page message: ' . json_encode($invalid));
    }
    $_REQUEST = array_replace($baseRequest, ['extdisplay' => []]);
    $html = load_view(dirname(__DIR__) . '/views/form.php');
    check(str_contains($html, 'Invalid announcement ID.') && !str_contains($html, 'name="editAnnouncementtts"'),
        'Direct form rendering also handles malformed IDs safely');
    check($service->all() === $beforeRows && $calls === $beforeCalls, 'Opening and rejecting forms neither changes records nor calls the provider');
} finally {
    FreePBX::$module = $previousModule;
    $_REQUEST = $previousRequest;
    $_POST = $previousPost;
    $_GET = $previousGet;
}
