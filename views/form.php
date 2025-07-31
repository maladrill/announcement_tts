<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); } ?> 

<!-- Load custom JS -->
<script type="text/javascript" src="modules/announcementtts/assets/js/announcementtts.js"></script>

<?php
// Load record if editing
$request    = $_REQUEST;
$extdisplay = $request['extdisplay'] ?? '';
$row = !empty($extdisplay) ? announcementtts_get($extdisplay) : [];

// Defaults
$action      = $extdisplay ? 'edit' : 'add';
$description = $row['description'] ?? '';
$text        = $row['text']        ?? '';
$language    = $row['language']    ?? 'pl';
$voice       = $row['voice']       ?? 'fable';
$post_dest   = $row['post_dest']   ?? '';

// Language and voice options
$LANGUAGES = [
  'af'=>'Afrikaans','ar'=>'Arabic','hy'=>'Armenian','az'=>'Azerbaijani','be'=>'Belarusian','bs'=>'Bosnian','bg'=>'Bulgarian','ca'=>'Catalan',
  'zh'=>'Chinese','hr'=>'Croatian','cs'=>'Czech','da'=>'Danish','nl'=>'Dutch','en'=>'English','et'=>'Estonian','fi'=>'Finnish','fr'=>'French',
  'gl'=>'Galician','de'=>'German','el'=>'Greek','he'=>'Hebrew','hi'=>'Hindi','hu'=>'Hungarian','is'=>'Icelandic','id'=>'Indonesian','it'=>'Italian',
  'ja'=>'Japanese','kn'=>'Kannada','kk'=>'Kazakh','ko'=>'Korean','lv'=>'Latvian','lt'=>'Lithuanian','mk'=>'Macedonian','ms'=>'Malay','mr'=>'Marathi',
  'mi'=>'Maori','ne'=>'Nepali','no'=>'Norwegian','fa'=>'Persian','pl'=>'Polish','pt'=>'Portuguese','ro'=>'Romanian','ru'=>'Russian','sr'=>'Serbian',
  'sk'=>'Slovak','sl'=>'Slovenian','es'=>'Spanish','sw'=>'Swahili','sv'=>'Swedish','tl'=>'Tagalog','ta'=>'Tamil','th'=>'Thai','tr'=>'Turkish',
  'uk'=>'Ukrainian','ur'=>'Urdu','vi'=>'Vietnamese','cy'=>'Welsh',
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
    <div class="form-group row">
      <div class="col-md-3">
        <label for="description" class="control-label"><?php echo _("Description") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="description"></i>
      </div>
      <div class="col-md-9">
        <input type="text" id="description" name="description" class="form-control"
               value="<?php echo htmlspecialchars($description) ?>" />
        <span id="description-help" class="help-block fpbx-help-block">
          <?php echo _("The name of this announcement.") ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Announcement Text -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="text" class="control-label"><?php echo _("Announcement Text") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="text"></i>
      </div>
      <div class="col-md-9">
        <textarea id="text" name="text" class="form-control" rows="4"><?php echo htmlspecialchars($text) ?></textarea>
        <span id="text-help" class="help-block fpbx-help-block">
          <?php echo _("Enter the text to be converted to speech.") ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Language -->
  <?php
    $langopts = '';
    foreach ($LANGUAGES as $code => $label) {
        $sel = ($code === $language) ? ' selected' : '';
        $langopts .= "<option value=\"$code\"$sel>" . htmlspecialchars($label, ENT_QUOTES) . "</option>\n";
    }
  ?>
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="language" class="control-label"><?php echo _("Language") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="language"></i>
      </div>
      <div class="col-md-9">
        <select id="language" name="language" class="form-control"><?php echo $langopts ?></select>
        <span id="language-help" class="help-block fpbx-help-block">
          <?php echo _("Select the language used for text-to-speech conversion.") ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Voice -->
  <?php
    $voiceopts = '';
    foreach ($VOICES as $v) {
        $sel = ($v === $voice) ? ' selected' : '';
        $voiceopts .= "<option value=\"$v\"$sel>" . ucfirst($v) . "</option>\n";
    }
  ?>
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="voice" class="control-label"><?php echo _("Voice") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="voice"></i>
      </div>
      <div class="col-md-9">
        <select id="voice" name="voice" class="form-control"><?php echo $voiceopts ?></select>
        <span id="voice-help" class="help-block fpbx-help-block">
          <?php echo _("Choose the voice model for speech synthesis.") ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Audio File (if exists) -->
  <?php if (!empty($row['audio_file']) && file_exists($row['audio_file'])):
    $id = intval($row['announcementtts_id']);
    $audio_url = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) . '?display=announcementtts&download_audio=' . $id;
  ?>
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3"><?php echo _("Audio File") ?></div>
      <div class="col-md-9">
        <strong><?php echo htmlspecialchars(basename($row['audio_file'])) ?></strong><br>
        <audio controls src="<?php echo $audio_url ?>" type="audio/wav">
          <?php echo _("Your browser does not support audio playback.") ?>
        </audio>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Post-Destination -->
  <div class="element-container">
    <div class="form-group row">
      <div class="col-md-3">
        <label for="post_dest" class="control-label"><?php echo _("Destination after Playback") ?></label>
        <i class="fa fa-question-circle fpbx-help-icon" data-for="post_dest"></i>
      </div>
      <div class="col-md-9">
        <?php echo drawselects($post_dest, 0); ?>
        <span id="post_dest-help" class="help-block fpbx-help-block">
          <?php echo _("Where to send the call after playback ends.") ?>
        </span>
      </div>
    </div>
  </div>
</form>

<!-- JavaScript array of names for duplicate check -->
<script>
<?php
$all = announcementtts_list();
if (!empty($extdisplay)) {
    $all = array_filter($all, fn($r) => $r['announcementtts_id'] != $extdisplay);
}
$existingDescriptions = array_column($all, 'description');
echo 'var announcementttsnames = ' . json_encode($existingDescriptions, JSON_THROW_ON_ERROR) . ';';
?>
</script>
