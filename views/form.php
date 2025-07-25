<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Include our validator script so that checkAnnouncementtts() is defined:
?>
<script type="text/javascript"
        src="modules/announcementtts/static/announcementttstts.js">
</script>
<?php
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

// opcje repeat
$digits = array_merge(range(0,9), ['*','#']);
$repeatopts = '<option value=""'.($repeat_msg===''?' selected':'').'>'. _("Disable") ."</option>\n";
foreach ($digits as $d) {
    $sel = ($d === $repeat_msg) ? ' selected' : '';
    $repeatopts .= '<option value="'.htmlspecialchars($d).'"'.$sel.'>'.htmlspecialchars($d)."</option>\n";
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
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="description">
          <?php echo _("Description")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="description"></i>
        </label>
      </div>
      <div class="col-md-9">
        <input type="text"
               class="form-control"
               id="description"
               name="description"
               value="<?php echo htmlspecialchars($description,ENT_QUOTES) ?>">
      </div>
    </div>
  </div>

  <!-- Announcement Text -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="text">
          <?php echo _("Announcement Text")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="text"></i>
        </label>
      </div>
      <div class="col-md-9">
        <textarea class="form-control"
                  id="text"
                  name="text"
                  rows="4"
                  required><?php echo htmlspecialchars($text,ENT_QUOTES) ?></textarea>
      </div>
    </div>
  </div>

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
          <?php foreach($LANGUAGES as $code => $label): ?>
            <option value="<?php echo $code?>"
                    <?php echo $code===$language?'selected':''?>>
              <?php echo $label?>
            </option>
          <?php endforeach?>
        </select>
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
          <?php foreach($VOICES as $v): ?>
            <option value="<?php echo $v?>"
                    <?php echo $v===$voice?'selected':''?>>
              <?php echo ucfirst($v)?>
            </option>
          <?php endforeach?>
        </select>
      </div>
    </div>
  </div>

  <!-- Audio File Preview -->
  <?php if (!empty($row['audio_file']) && file_exists($row['audio_file'])):
    $id        = intval($row['announcementtts_id']);
    $base      = htmlspecialchars($_SERVER['PHP_SELF'],ENT_QUOTES);
    $audio_url = $base.'?display=announcementtts&download_audio='.$id;
  ?>
    <div class="element-container">
      <div class="row">
        <div class="col-md-3"><?php echo _("Audio file")?></div>
        <div class="col-md-9">
          <strong><?php echo htmlspecialchars(basename($row['audio_file']),ENT_QUOTES) ?></strong><br>
          <audio controls
                 src="<?php echo htmlspecialchars($audio_url,ENT_QUOTES) ?>"
                 type="audio/wav">
            <?php echo _("Your browser does not support audio playback.")?>
          </audio>
        </div>
      </div>
    </div>
  <?php endif ?>

  <!-- Repeat -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="repeat_msg">
          <?php echo _("Repeat")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="repeat_msg"></i>
        </label>
      </div>
      <div class="col-md-9">
        <select class="form-control" id="repeat_msg" name="repeat_msg">
          <?php echo $repeatopts ?>
        </select>
      </div>
    </div>
  </div>

  <!-- Allow Skip -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label">
          <?php echo _("Allow Skip")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="allow_skip"></i>
        </label>
      </div>
      <div class="col-md-9 radioset">
        <input type="radio" name="allow_skip" id="allow_skipyes" value="1" <?php echo $allow_skip?'checked':''?>>
        <label for="allow_skipyes"><?php echo _("Yes")?></label>
        <input type="radio" name="allow_skip" id="allow_skipno"  value="0" <?php echo $allow_skip?'':'checked'?>>
        <label for="allow_skipno"><?php echo _("No")?></label>
      </div>
    </div>
  </div>

  <!-- Return to IVR -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label">
          <?php echo _("Return to IVR")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="return_ivr"></i>
        </label>
      </div>
      <div class="col-md-9 radioset">
        <input type="radio" name="return_ivr" id="return_ivryes" value="1" <?php echo $return_ivr?'checked':''?>>
        <label for="return_ivryes"><?php echo _("Yes")?></label>
        <input type="radio" name="return_ivr" id="return_ivrno"  value="0" <?php echo $return_ivr?'':'checked'?>>
        <label for="return_ivrno"><?php echo _("No")?></label>
      </div>
    </div>
  </div>

  <!-- Don't Answer Channel -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label">
          <?php echo _("Don't Answer Channel")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="noanswer"></i>
        </label>
      </div>
      <div class="col-md-9 radioset">
        <input type="radio" name="noanswer" id="noansweryes" value="1" <?php echo $noanswer?'checked':''?>>
        <label for="noansweryes"><?php echo _("Yes")?></label>
        <input type="radio" name="noanswer" id="noanswerno"  value="0" <?php echo $noanswer?'':'checked'?>>
        <label for="noanswerno"><?php echo _("No")?></label>
      </div>
    </div>
  </div>

  <!-- Destination after Playback -->
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label">
          <?php echo _("Destination after Playback")?>
          <i class="fa fa-question-circle fpbx-help-icon" data-for="post_dest"></i>
        </label>
      </div>
      <div class="col-md-9">
        <?php echo drawselects($post_dest, 0) ?>
      </div>
    </div>
  </div>
</form>

<script type="text/javascript">
// Ensure the validator knows existing names before it runs:
var announcementttsnames = <?php 
    echo json_encode(\FreePBX::Announcementtts()->getALLAnnouncementstts($extdisplay), JSON_THROW_ON_ERROR);
?>;
</script>
