<div id="toolbar-all">
	<a href="?display=announcementtts" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _("List Announcementstts")?></a>
	<a href="?display=announcementtts&amp;view=form" class="btn btn-default"><i class="fa fa-plus"></i> <?php echo _("Add Announcementstts")?></a>
</div>
 <table id="announcegrid-side" data-escape="true" data-url="ajax.php?module=announcementtts&amp;command=getJSON&amp;jdata=grid" data-cache="false" data-toolbar="#toolbar-all" data-toggle="table" data-search="true" class="table">
    <thead>
        <tr>
            <th data-field="description" data-sortable="true"><?php echo _("Destinations")?></th>
        </tr>
    </thead>
</table>
