<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$module = FreePBX::Announcementtts();
$error = $module->formError();
$view = $_REQUEST['view'] ?? '';
if ($module->formSaved()) { $view = ''; }
$usagehtml = '';
if ($view === 'form' && !empty($_REQUEST['extdisplay'])) {
    try { $usagehtml = FreePBX::View()->destinationUsage(announcementtts_getdest($_REQUEST['extdisplay'])); }
    catch (\InvalidArgumentException $e) { $error = $e->getMessage(); $view = ''; }
}
$content = load_view(__DIR__ . ($view === 'form' ? '/views/form.php' : '/views/grid.php'));
?>
<div class="container-fluid">
    <h1><?php echo _('Announcements TTS') ?></h1>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php echo $usagehtml ?>
    <div class="display full-border"><?php echo $content ?></div>
</div>
