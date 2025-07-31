<?php
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2015 Sangoma Technologies and 2025 SQS Polska.
// vim: set ai ts=4 sw=4 ft=php: 
namespace FreePBX\modules;
class Announcementtts extends \FreePBX_Helpers implements \BMO {

	private $freepbx;
	public function __construct($freepbx = null) {
		parent::__construct($freepbx);
		$this->freepbx = $freepbx;
		$this->db = $this->freepbx->Database;
	}

	public function getAnnouncementstts() {
		$sql = "SELECT announcementtts_id, description, recording_id, allow_skip, post_dest, repeat_msg, return_ivr, noanswer FROM announcementtts";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		return $sth->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function getAnnouncementttsByID($id) {
		$sql = "SELECT announcementtts_id, description, recording_id, allow_skip, post_dest, repeat_msg, return_ivr, noanswer FROM announcementtts WHERE announcementtts_id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute([$id]);
		return $sth->fetch(\PDO::FETCH_ASSOC);


		$row = $db->getRow($sql,DB_FETCHMODE_ASSOC);
		if(DB::IsError($row)) {
			die_freepbx($row->getMessage()."<br><br>Error selecting row from announcementtts");
		}
		// Added Associative query above but put positional indexes back to maintain backward compatibility
		//
		$i = 0;
		if(!empty($row) && is_array($row)) {
			foreach ($row as $item) {
				$row[$i] = $item;
				$i++;
			}
			return $row;
		} else {
			return [];
		}
	}
	public function getALLAnnouncementstts($id) {
		$sql = "SELECT description FROM announcementtts";
		if ($id) {
			$sql .= ' where announcementtts_id != :id ';
		}
		$sth = $this->db->prepare($sql);
		if ($id) {
			$sth->execute([":id" => $id]);
		}
		$res = $sth->fetchAll(\PDO::FETCH_COLUMN, 0);
		return is_array($res)?$res:[];
	}

	/**
	 * Ajax Request
	 * @param string $req     The request type
	 * @param string $setting Settings to return back
	 */
	public function ajaxRequest($req, $setting){
		return match ($req) {
      "getData", "getJSON" => true,
      default => false,
  };
	}

	/**
	 * Handle AJAX
	 */
	public function ajaxHandler(){
		$request = $_REQUEST;
		switch($request['command']){
			case "getData":
			break;
			case "getJSON":
				return $this->getAnnouncementstts();
			default:
			break;
		}
	}

	public function getActionBar($request) {
		$buttons = [];
		if($request['display'] == 'announcementtts'){
			$buttons = ['delete' => ['name' => 'delete', 'id' => 'delete', 'value' => _('Delete')], 'reset' => ['name' => 'reset', 'id' => 'reset', 'value' => _('Reset')], 'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')]];
			if (empty($request['extdisplay'])) {
				unset($buttons['delete']);
			}
			if(empty($_GET['view']) || $_GET['view'] != 'form'){
				$buttons = [];
			}
		}
		return $buttons;
	}

	public function install() {
		//Tables added via module.xml
	}

	public function uninstall() {
		$sql = 'DROP TABLE announcementtts';
		$stmt = $this->db->prepare($sql);
		return $stmt->execute();
	}

	public function backup($backup) {
		//Unused See Backup.php
	}

	public function restore($backup) {
		//Unused See Restore.php
	}

	public function doTests($db) {
		return true;
	}
    
public function addAnnouncementtts($description, $recording_id, $allow_skip,
                                   $post_dest, $return_ivr, $noanswer, $repeat_msg) {
    // 1) Sanitizacja recording_id
    if ($recording_id === '' || $recording_id === null) {
        $recording_id = null;
    } else {
        $recording_id = intval($recording_id);
    }
    $allow_skip = $allow_skip ? 1 : 0;
    $return_ivr = $return_ivr ? 1 : 0;
    $noanswer   = $noanswer   ? 1 : 0;
    $repeat_msg = $repeat_msg ?: '';

    // 2) Pobranie pól TTS
    $text     = $_REQUEST['text']     ?? '';
    $language = $_REQUEST['language'] ?? 'en';
    $voice    = $_REQUEST['voice']    ?? 'sage';

    // 3) Wstawiamy rekord razem z kolumnami text, language, voice
    $sql = "INSERT INTO announcementtts (
                description, recording_id, allow_skip, post_dest,
                return_ivr, noanswer, repeat_msg,
                `text`, language, voice
            ) VALUES (
                :description, :recording_id, :allow_skip, :post_dest,
                :return_ivr, :noanswer, :repeat_msg,
                :text, :language, :voice
            )";
    $params = [
        ':description'   => $description,
        ':recording_id'  => $recording_id,
        ':allow_skip'    => $allow_skip,
        ':post_dest'     => $post_dest,
        ':return_ivr'    => $return_ivr,
        ':noanswer'      => $noanswer,
        ':repeat_msg'    => $repeat_msg,
        ':text'          => $text,
        ':language'      => $language,
        ':voice'         => $voice,
    ];
    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    // 4) Pobranie ID nowego rekordu
    $id = $this->freepbx->Database->lastInsertId();

    // 5) Wywołanie OpenAI TTS + ffmpeg + zapis ścieżki w kolumnie audio_file
    try {
        $wavFilename = generate_tts($id, $text, $language, $voice);
        $fullPath = "/var/lib/asterisk/sounds/custom/{$wavFilename}";
        $upd = $this->db->prepare(
            "UPDATE announcementtts SET audio_file = :audio_file WHERE announcementtts_id = :id"
        );
        $upd->execute([
            ':audio_file' => $fullPath,
            ':id'         => $id,
        ]);
    } catch (\Exception $e) {
        // jeśli nie uda się TTS, logujemy błąd i zostawiamy rekord bez audio_file
        error_log("AnnouncementTTS TTS error for ID {$id}: " . $e->getMessage());
    }

    return $id;
}

    /**
     * Edit an announcement and regenerate audio only if TTS fields changed.
     */
    public function editAnnouncementtts(
        $announcementtts_id,
        $description,
        $recording_id,
        $allow_skip,
        $post_dest,
        $return_ivr,
        $noanswer,
        $repeat_msg
    ) {
        // 1) sanitize recording_id
        if ($recording_id === '' || $recording_id === null) {
            $recording_id = null;
        } else {
            $recording_id = intval($recording_id);
        }
        $allow_skip = $allow_skip ? 1 : 0;
        $return_ivr = $return_ivr ? 1 : 0;
        $noanswer   = $noanswer   ? 1 : 0;
        $repeat_msg = $repeat_msg ?: '';

        // 2) fetch old TTS values for comparison
        $old = $this->getAnnouncementttsByID($announcementtts_id);
        $oldText  = $old['text']     ?? '';
        $oldLang  = $old['language'] ?? '';
        $oldVoice = $old['voice']    ?? '';

        // 3) grab new TTS values from form
        $text     = $_REQUEST['text']     ?? '';
        $language = $_REQUEST['language'] ?? 'en';
        $voice    = $_REQUEST['voice']    ?? 'sage';

        // 4) update every column, including TTS fields
        $sql = "UPDATE announcementtts SET
                    description   = :description,
                    recording_id  = :recording_id,
                    allow_skip    = :allow_skip,
                    post_dest     = :post_dest,
                    return_ivr    = :return_ivr,
                    noanswer      = :noanswer,
                    repeat_msg    = :repeat_msg,
                    `text`        = :text,
                    language      = :language,
                    voice         = :voice
                WHERE announcementtts_id = :id";
        $params = [
            ':description'   => $description,
            ':recording_id'  => $recording_id,
            ':allow_skip'    => $allow_skip,
            ':post_dest'     => $post_dest,
            ':return_ivr'    => $return_ivr,
            ':noanswer'      => $noanswer,
            ':repeat_msg'    => $repeat_msg,
            ':text'          => $text,
            ':language'      => $language,
            ':voice'         => $voice,
            ':id'            => $announcementtts_id,
        ];
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // 5) if any TTS field changed, regenerate the audio
        if ($text !== $oldText || $language !== $oldLang || $voice !== $oldVoice) {
            try {
                $wav       = generate_tts($announcementtts_id, $text, $language, $voice);
                $fullPath  = "/var/lib/asterisk/sounds/custom/{$wav}";
                $upd = $this->db->prepare(
                    "UPDATE announcementtts SET audio_file = :audio_file WHERE announcementtts_id = :id"
                );
                $upd->execute([
                    ':audio_file' => $fullPath,
                    ':id'         => $announcementtts_id,
                ]);
            } catch (\Exception $e) {
                // log and continue if TTS fails
                error_log("AnnouncementTTS regen error for ID {$announcementtts_id}: " . $e->getMessage());
            }
        }

        return true;    }	

	public function doConfigPageInit($page) {
		$request = $_REQUEST;
		$action = $request['action'] ?? '';
		if (isset($request['delete'])){
			$action = 'delete';
		}
		$announcementtts_id = $request['announcementtts_id'] ?? false;
		$description = $request['description'] ?? '';
		$recording_id = $request['recording_id'] ?? '';
		$allow_skip = $request['allow_skip'] ?? 0;
		$return_ivr = $request['return_ivr'] ?? 0;
		$noanswer = $request['noanswer'] ?? 0;
		$post_dest = $request['post_dest'] ?? '';
		$repeat_msg = $request['repeat_msg'] ?? '';

		if (isset($request['goto0']) && $request['goto0']) {
			// 'ringgroup_post_dest'  'ivr_post_dest' or whatever
			$post_dest = $request[ $request['goto0'].'0' ];
		}


		switch ($action) {
			case 'add':
				$this->addAnnouncementtts($description, $recording_id, $allow_skip, $post_dest, $return_ivr, $noanswer, $repeat_msg);
				needreload();
			break;
			case 'edit':
				$this->editAnnouncementtts($announcementtts_id, $description, $recording_id, $allow_skip, $post_dest, $return_ivr, $noanswer, $repeat_msg);
				needreload();
			break;
			
			default:
			break;
		}
	}

	public function getRightNav($request) {
		if(isset($_GET['view']) && $_GET['view'] == 'form'){
		    return load_view(__DIR__."/views/rnav.php",[]);
		}
	}
}
