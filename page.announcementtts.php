<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// License for all code of this FreePBX module can be found in the license file inside the module directory
// Copyright 2006-2014 Schmooze Com Inc. and 2025 SQS Polska

$request = $_REQUEST;

// 1) Proceduralne przechwycenie DELETE
if (!empty($request['action']) && $request['action'] === 'delete' && !empty($request['extdisplay'])) {
    // funkcja z functions.inc.php
    announcementtts_delete($request['extdisplay']);
    // przeładuj dialplan i UI
    needreload();
    // needreload() natychmiast przerwie dalsze renderowanie i zrobi redirect
}

$heading   = _("Announcementtts");
$view      = !empty($_GET['view']) ? $_GET['view'] : '';
$usagehtml = '';

// 2) Twoja dotychczasowa logika widoków
switch ($view) {
    case "form":
        if (!empty($request['extdisplay'])) {
            $heading .= _(": Edit");
            $usagehtml = FreePBX::View()->destinationUsage(
                announcementtts_getdest($request['extdisplay'])
            );
        } else {
            $heading .= _(": Add");
        }
        $content = load_view(
            __DIR__ . '/views/form.php',
            ['request' => $request, 'amp_conf' => $amp_conf]
        );
        break;

    default:
        $content = load_view(__DIR__ . '/views/grid.php');
        break;
}

?>
<div class="container-fluid">
    <h1><?php echo $heading ?></h1>
    <?php echo $usagehtml ?>
    <div class="display full-border">
        <div class="row">
            <div class="col-sm-12">
                <div class="fpbx-container">
                    <div class="display <?php echo !empty($view) ? 'full' : 'no' ?>-border">
                        <?php echo $content ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
