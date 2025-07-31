<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); } ?>
<script type="text/javascript"
        src="modules/announcementtts/assets/js/announcementtts.js">
</script><?php
$request    = $_REQUEST;
$extdisplay = $request['extdisplay'] ?? '';

// Pobierz istniejący rekord, jeśli edycja
$row = [];
if (!empty($extdisplay)) {
    $row = announcementtts_get($extdisplay);
}


// Ustawienia formularza
$action      = $extdisplay ? 'edit' : 'add';
$description = $row['description'] ?? '';
$text        = $row['text']        ?? '';
$language    = $row['language']    ?? 'pl';
$voice       = $row['voice']       ?? 'fable';
$repeat_msg  = $row['repeat_msg']  ?? '';
$allow_skip  = !empty($row['allow_skip']);
$return_ivr  = !empty($row['return_ivr']);
$noanswer    = !empty($row['noanswer']);
$post_dest   = $row['post_dest']   ?? '';

// przygotuj opcje powtórzeń
$digits = range(0,9);
$digits[] = '*'; $digits[] = '#';
$repeatopts = '<option value=""'.($repeat_msg === ""?' SELECTED':'').'>'. _("Disable") ."</option>\n";
foreach ($digits as $d) {
    $sel = ($d == $repeat_msg) ? ' SELECTED' : '';
    $repeatopts .= '<option value="'.htmlspecialchars($d).'"'.$sel.'>'.htmlspecialchars($d).'</option>'."\n";
}

// lista języków i głosów
$LANGUAGES = [
  'af'=>'Afrikaans','ar'=>'Arabic','hy'=>'Armenian','az'=>'Azerbaijani',
  'be'=>'Belarusian','bs'=>'Bosnian','bg'=>'Bulgarian','ca'=>'Catalan',
  'zh'=>'Chinese','hr'=>'Croatian','cs'=>'Czech','da'=>'Danish',
  'nl'=>'Dutch','en'=>'English','et'=>'Estonian','fi'=>'Finnish',
  'fr'=>'French','gl'=>'Galician','de'=>'German','el'=>'Greek',
  'he'=>'Hebrew','hi'=>'Hindi','hu'=>'Hungarian','is'=>'Icelandic',
  'id'=>'Indonesian','it'=>'Italian','ja'=>'Japanese','kn'=>'Kannada',
  'kk'=>'Kazakh','ko'=>'Korean','lv'=>'Latvian','lt'=>'Lithuanian',
  'mk'=>'Macedonian','ms'=>'Malay','mr'=>'Marathi','mi'=>'Maori',
  'ne'=>'Nepali','no'=>'Norwegian','fa'=>'Persian','pl'=>'Polish',
  'pt'=>'Portuguese','ro'=>'Romanian','ru'=>'Russian','sr'=>'Serbian',
  'sk'=>'Slovak','sl'=>'Slovenian','es'=>'Spanish','sw'=>'Swahili',
  'sv'=>'Swedish','tl'=>'Tagalog','ta'=>'Tamil','th'=>'Thai',
  'tr'=>'Turkish','uk'=>'Ukrainian','ur'=>'Urdu','vi'=>'Vietnamese',
  'cy'=>'Welsh',
];
$VOICES = ['alloy','ash','ballad','coral','echo','fable','nova','onyx','sage','shimmer'];
// przygotuj opcje dla języków
$langopts = '';
foreach ($LANGUAGES as $code => $label) {
    $sel = ($code === $language) ? ' selected' : '';
    $langopts .= '<option value="'.htmlspecialchars($code,ENT_QUOTES).'"'.$sel.'>'.htmlspecialchars($label,ENT_QUOTES)."</option>\n";
}

// przygotuj opcje dla głosów
$voiceopts = '';
foreach ($VOICES as $v) {
    $sel = ($v === $voice) ? ' selected' : '';
    $voiceopts .= '<option value="'.htmlspecialchars($v,ENT_QUOTES).'"'.$sel.'>'.htmlspecialchars(ucfirst($v),ENT_QUOTES)."</option>\n";
}
?>
<form class="fpbx-submit"
      name="editAnnouncementtts"
      action="?display=announcementtts"
      method="post"
      onsubmit="return checkAnnouncementtts(this);"
      data-fpbx-delete="config.php?display=announcementtts&amp;extdisplay=<?php echo $extdisplay ?>&amp;action=delete">
  <input type="hidden" name="extdisplay"         value="<?php echo htmlspecialchars($extdisplay) ?>">
  <input type="hidden" name="announcementtts_id" value="<?php echo htmlspecialchars($extdisplay) ?>">
  <input type="hidden" name="action"             value="<?php echo $action ?>">
  <input type="hidden" name="display"            value="announcementtts">
  <input type="hidden" name="view"               value="form">
  <!-- Description -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="description" class="control-label"><?php echo _("Description") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="description"></i>
      </div>
      <div class="col-md-9">
        <input type="text"
               id="description"
               name="description"
               class="form-control"
               value="<?php echo htmlspecialchars($description) ?>"
        />
        <span id="description-help" class="help-block fpbx-help-block">
          <?php echo _("The name of this announcement.") ?>
        </span>
      </div>
    </div>
  </div>
  <!-- END Description -->


  <!-- Repeat -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="repeat_msg" class="control-label"><?php echo _("Repeat") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="repeat_msg"></i>
      </div>
      <div class="col-md-9">
        <select id="repeat_msg" name="repeat_msg" class="form-control">
          <?php echo $repeatopts ?>
        </select>
        <span id="repeat_msg-help" class="help-block fpbx-help-block">
          <?php echo _("Chose whether the message is to be played again and how many times.") ?>
        </span>
      </div>
    </div>
  </div>
  <!-- END Repeat -->

  <!-- Announcement Text -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="text" class="control-label"><?php echo _("Announcement Text") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="text"></i>
      </div>
      <div class="col-md-9">
        <textarea id="text"
                  name="text"
                  class="form-control"
                  rows="4"><?php echo htmlspecialchars($text) ?></textarea>
        <span id="text-help" class="help-block fpbx-help-block">
          <?php echo _("Enter the text you wish to convert to speech.") ?>
        </span>
      </div>
    </div>
  </div>
  <!-- END Announcement Text -->

<!-- Language -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="language">
          <?php echo _("Language")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="language"></i>
        </label>
      </div>
      <div class="col-md-9">
        <select class="form-control" id="language" name="language">
          <?php echo $langopts ?>
        </select>
        <span id="language-help" class="help-block fpbx-help-block">
          <?php echo _("Select the language for TTS.") ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Voice -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="voice">
          <?php echo _("Voice")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="voice"></i>
        </label>
      </div>
      <div class="col-md-9">
        <select class="form-control" id="voice" name="voice">
          <?php echo $voiceopts ?>
        </select>
        <span id="voice-help" class="help-block fpbx-help-block">
          <?php echo _("Choose which TTS voice model to use.") ?>
        </span>
      </div>
    </div>
  </div>  
  <!-- END Voice -->

  <!-- Audio File Preview -->
  <?php if (!empty($row['audio_file']) && file_exists($row['audio_file'])):
    $id        = intval($row['announcementtts_id']);
    $base      = htmlspecialchars($_SERVER['PHP_SELF'],ENT_QUOTES);
    $audio_url = $base . '?display=announcementtts&download_audio=' . $id;
  ?>
    <div class="element-container">
      <div class="row">
        <div class="col-md-3"><?php echo _("Audio file")?></div>
        <div class="col-md-9">
          <strong><?php echo htmlspecialchars(basename($row['audio_file']),ENT_QUOTES) ?></strong><br>
          <audio controls src="<?php echo htmlspecialchars($audio_url,ENT_QUOTES) ?>" type="audio/wav">
            <?php echo _("Your browser does not support audio playback.")?>
          </audio>
        </div>
      </div>
    </div>
  <?php endif ?>

  <!-- Allow Skip -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label class="control-label"><?php echo _("Allow Skip") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="allow_skip"></i>
      </div>
      <div class="col-md-9 radioset">
        <input type="radio" id="allow_skip_yes" name="allow_skip" value="1" <?php echo $allow_skip ? 'CHECKED':''?>>
        <label for="allow_skip_yes"><?php echo _("Yes") ?></label>
        <input type="radio" id="allow_skip_no"  name="allow_skip" value="" <?php echo !$allow_skip?'CHECKED':''?>>
        <label for="allow_skip_no"><?php echo _("No") ?></label>
        <span id="allow_skip-help" class="help-block fpbx-help-block">
          <?php echo _("Allow callers to skip ahead by pressing any key.") ?>
        </span>
      </div>
    </div>
  </div>
  <!-- END Allow Skip -->

  <!-- Return to IVR -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label class="control-label"><?php echo _("Return to IVR") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="return_ivr"></i>
      </div>
      <div class="col-md-9 radioset">
        <input type="radio" id="return_ivr_yes" name="return_ivr" value="1" <?php echo $return_ivr?'CHECKED':''?>>
        <label for="return_ivr_yes"><?php echo _("Yes") ?></label>
        <input type="radio" id="return_ivr_no"  name="return_ivr" value="" <?php echo !$return_ivr?'CHECKED':''?>>
        <label for="return_ivr_no"><?php echo _("No") ?></label>
        <span id="return_ivr-help" class="help-block fpbx-help-block">
          <?php echo _("Return to parent IVR after playback.") ?>
        </span>
      </div>
    </div>
  </div>
  <!-- END Return to IVR -->

  <!-- Don't Answer Channel -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label class="control-label"><?php echo _("Don't Answer Channel") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="noanswer"></i>
      </div>
      <div class="col-md-9 radioset">  
        <input type="radio" id="noanswer_yes"	name="noanswer" value="1" <?php echo $noanswer?'CHECKED':''?>>
        <label for="noanswer_yes"><?php echo _("Yes") ?></label>
        <input type="radio" id="noanswer_no"	name="noanswer"	value="" <?php echo !$noanswer?'CHECKED':''?>>
        <label for="noanswer_no"><?php echo _("No") ?></label>
        <span id="noanswer-help" class="help-block fpbx-help-block"><?php echo _("Play as early‑media instead of formally answering.")?></span>
      </div>
    </div>
  </div>
  <!-- END Don't Answer Channel -->

  <!-- Destination after Playback -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="post_dest" class="control-label"><?php echo _("Destination after Playback") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="post_dest"></i>
      </div>
      <div class="col-md-9">
        <?php echo drawselects($post_dest, 0) ?>
        <span id="post_dest-help" class="help-block fpbx-help-block">
          <?php echo _("Where to send the call once playback completes (or is skipped).") ?>
        </span>
      </div>
    </div>
  </div>
  <!-- END Destination -->

</form>

<script>
// lista istniejących opisów do JS‐owej walidacji
var announcementttsnames = <?php
  echo json_encode(array_column(announcementtts_list(), 'description'), JSON_THROW_ON_ERROR);
?>;
</script>