<?php
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2015 Sangoma Technologies.
//
//
?>
<link rel="stylesheet" href="modules/announcementtts/assets/css/announcementtts.css?v=<?php echo substr(hash_file('sha256', __DIR__ . '/../assets/css/announcementtts.css'), 0, 12) ?>">
<script src="modules/announcementtts/assets/js/announcementtts.js?v=<?php echo substr(hash_file('sha256', __DIR__ . '/../assets/js/announcementtts.js'), 0, 12) ?>"></script>
<script>
	var destinations = <?php echo json_encode(FreePBX::Modules()->getDestinations(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
</script>
<div id="toolbar-grid">
	<a href="config.php?display=announcementtts&amp;view=form" class="btn btn-primary"><i class="fa fa-plus"></i> <?php echo _('Add')?></a>
</div>
<form id="announcementtts-delete-form" method="post" action="config.php?display=announcementtts" hidden>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="announcementtts_id" value="">
    <input type="hidden" name="announcementtts_csrf" value="<?php echo htmlspecialchars(FreePBX::Announcementtts()->csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
</form>
<table data-toolbar="#toolbar-grid" data-escape="true" data-toggle="table" data-url="ajax.php?module=announcementtts&amp;command=getJSON&amp;jdata=grid" data-maintain-selected="true" data-show-columns="true" data-show-toggle="true" data-toggle="table" data-pagination="true" data-search="true"  id="table-all">
	<thead>
		<tr>
			<th data-sortable="true" data-field="description"><?php echo _("Description")?></th>
			<th data-sortable="true" data-field="post_dest" data-formatter="aDestFormatter"><?php echo _("Destination")?></th>
			<th data-field="announcementtts_id" data-formatter="actionformatter"><?php echo _("Actions")?></th>
		</tr>
	</thead>
	<tbody>
	</tbody>
</table>
