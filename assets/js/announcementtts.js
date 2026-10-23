//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2016 Sangoma Technologies.
//

function checkAnnouncementtts(theForm) {
  var msgInvalidDescription = _('Invalid description specified');

  // set up the Destination stuff
  setDestinations(theForm, '_post_dest');

  // form validation
  defaultEmptyOK = false;
  if (isEmpty(theForm.description.value))
    return warnInvalid(theForm.description, msgInvalidDescription);
	if (announcementttsnames.indexOf($("#description").val()) >= 0) {
				return warnInvalid($("#description"),_("Announcementtts Description Already Exist"));
	}
  if (!validateDestinations(theForm, 1, true))
    return false;

  return true;
}

$(document).on('click-row.bs.table', '#announcegrid-side', function(e,row,elem){
	window.location = '?display=announcementtts&view=form&extdisplay='+row['announcementtts_id'];
});



function actionformatter(v,r){
  if (!/^[1-9][0-9]*$/.test(String(v))) { return ''; }
  return '<a href="?display=announcementtts&amp;view=form&amp;extdisplay='+v+'"><i class="fa fa-edit"></i></a>';
}
function aDestFormatter(value){
	if(value === null || value.length == 0){
		return _("No Destination");
	}else{
		if(typeof destinations[value] !== "undefined") {
			if(typeof destinations[value].edit_url !== "undefined" && destinations[value].edit_url !== false) {
				return $('<span>').text(destinations[value].name + ": " + destinations[value].description).prop('outerHTML');
			} else {
				return $('<span>').text(destinations[value].name + ": " + destinations[value].description).prop('outerHTML');
			}
		} else {
			return $('<span>').text(value).prop('outerHTML');
		}
	}
}
