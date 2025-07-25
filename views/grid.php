<?php
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2015 Sangoma Technologies.
//
//
?>
<script>
	var destinations = <?php echo json_encode(FreePBX::Modules()->getDestinations(), JSON_THROW_ON_ERROR)?>;
</script>
<div id="toolbar-grid">
	<a href="config.php?display=announcementtts&amp;view=form" class="btn btn-primary"><i class="fa fa-plus"></i> <?php echo _('Add')?></a>
</div>
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
