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



function announcementttsEscapeHtml(value) {
  var element = document.createElement('span');
  element.textContent = String(value);
  return element.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function actionformatter(v,r){
  if (!/^[1-9][0-9]*$/.test(String(v))) { return ''; }
  var editLabel = announcementttsEscapeHtml(_('Edit'));
  var deleteLabel = announcementttsEscapeHtml(_('Delete'));
  return '<a href="?display=announcementtts&amp;view=form&amp;extdisplay='+v+'" title="'+editLabel+'" aria-label="'+editLabel+'"><i class="fa fa-edit" aria-hidden="true"></i></a> '
    + '<button type="button" class="announcementtts-delete" data-id="'+v+'" title="'+deleteLabel+'" aria-label="'+deleteLabel+'"><i class="fa fa-trash" aria-hidden="true"></i></button>';
}

// Grid rows are loaded asynchronously; use delegation and the token-protected POST form.
$(document).on('click', '#table-all .announcementtts-delete', function(event) {
  event.preventDefault();
  event.stopPropagation();
  if (this.disabled) { return; }
  var form = document.getElementById('announcementtts-delete-form');
  var id = this.getAttribute('data-id');
  if (!form || !/^[1-9][0-9]*$/.test(String(id))) { return; }
  if (!window.confirm(_('Delete this announcement?'))) { return; }
  form.elements['announcementtts_id'].value = String(id);
  this.disabled = true;
  form.submit();
});

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

// Keep the current voice when supported by both engines, otherwise select the engine's default.
function announcementttsUpdateVoices(preferred) {
    var engine = document.getElementById('backend');
    var voice = document.getElementById('voice');
    if (!engine || !voice || typeof announcementttsVoices === 'undefined') { return; }
    var config = announcementttsVoices[engine.value];
    if (!config) { return; }
    var candidate = typeof preferred === 'string' ? preferred : voice.value;
    var selected = config.voices.indexOf(candidate) !== -1 ? candidate : config.defaultVoice;
    while (voice.firstChild) { voice.removeChild(voice.firstChild); }
    config.voices.forEach(function (name) {
        var option = document.createElement('option');
        option.value = name;
        option.textContent = name.charAt(0).toUpperCase() + name.slice(1);
        option.selected = name === selected;
        voice.appendChild(option);
    });
    voice.value = selected;
}
$(document).on('change', '#backend', announcementttsUpdateVoices);
// Native reset restores server defaults after the event handler returns.
$(document).on('reset', 'form[name="editAnnouncementtts"]', function () {
    setTimeout(function () {
        var voice = document.getElementById('voice');
        announcementttsUpdateVoices(voice ? voice.getAttribute('data-initial-voice') : undefined);
    }, 0);
});
