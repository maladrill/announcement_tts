<?php
// License for all code of this FreePBX module can be found in the license file inside the module directory
// Copyright 2015 Sangoma Technologies and 2025 SQS Polska.
// vim: set ai ts=4 sw=4 ft=php: 

namespace FreePBX\modules;

class Announcementtts extends \FreePBX_Helpers implements \BMO {

	private $freepbx;

	public function __construct($freepbx = null) {
		parent::__construct($freepbx);
		$this->freepbx = $freepbx;
		$this->db = $this->freepbx->Database;
	}

	/**
	 * Fetch all announcements (basic fields).
	 */
	public function getAnnouncementstts() {
		$sql = "SELECT announcementtts_id, description, post_dest, text, language, voice, audio_file FROM announcementtts";
		$sth = $this->db->prepare($sql);
		$sth->execute();
		return $sth->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Get a single announcement by ID.
	 */
	public function getAnnouncementttsByID($id) {
		$sql = "SELECT announcementtts_id, description, post_dest, text, language, voice, audio_file FROM announcementtts WHERE announcementtts_id = ?";
		$sth = $this->db->prepare($sql);
		$sth->execute([$id]);
		return $sth->fetch(\PDO::FETCH_ASSOC);
	}

	/**
	 * Return all descriptions except for the current ID (used for validation).
	 */
	public function getALLAnnouncementstts($id) {
		$sql = "SELECT description FROM announcementtts";
		if ($id) {
			$sql .= ' WHERE announcementtts_id != :id ';
		}
		$sth = $this->db->prepare($sql);
		if ($id) {
			$sth->execute([":id" => $id]);
		}
		$res = $sth->fetchAll(\PDO::FETCH_COLUMN, 0);
		return is_array($res) ? $res : [];
	}

	/**
	 * Determine whether an AJAX request should be handled.
	 */
	public function ajaxRequest($req, $setting) {
		return match ($req) {
			"getData", "getJSON" => true,
			default => false,
		};
	}

	/**
	 * Handle AJAX commands.
	 */
	public function ajaxHandler() {
		$request = $_REQUEST;
		switch ($request['command']) {
			case "getData":
				break;
			case "getJSON":
				return $this->getAnnouncementstts();
			default:
				break;
		}
	}

	/**
	 * Return action bar buttons depending on context.
	 */
	public function getActionBar($request) {
		$buttons = [];
		if ($request['display'] == 'announcementtts') {
			$buttons = [
				'delete' => ['name' => 'delete', 'id' => 'delete', 'value' => _('Delete')],
				'reset'  => ['name' => 'reset', 'id' => 'reset', 'value' => _('Reset')],
				'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')],
			];
			if (empty($request['extdisplay'])) {
				unset($buttons['delete']);
			}
			if (empty($_GET['view']) || $_GET['view'] != 'form') {
				$buttons = [];
			}
		}
		return $buttons;
	}

	/**
	 * Module installation handler (currently handled by module.xml).
	 */
	public function install() {
		// Tables are created via module.xml
	}

	/**
	 * Module uninstallation handler – drop the main table.
	 */
        public function uninstall() {
            try {
                $sql = 'DROP TABLE IF EXISTS announcementtts';
                $stmt = $this->db->prepare($sql); 
                $stmt->execute();
                return true;
            } catch (\PDOException $e) {
                error_log("[announcementtts] Uninstall error: " . $e->getMessage());
                return false;
            }
        }

	public function backup($backup) {
		// Unused – see Backup.php
	}

	public function restore($backup) {
		// Unused – see Restore.php
	}

	public function doTests($db) {
		// Basic test stub – always passes
		return true;
	}

	/**
	 * Add a new announcement using OpenAI TTS.
	 */
	public function addAnnouncementtts($description, $post_dest) {
		$text     = $_REQUEST['text']     ?? '';
		$language = $_REQUEST['language'] ?? 'en';
		$voice    = $_REQUEST['voice']    ?? 'nova';

		// Insert database record
		$sql = "INSERT INTO announcementtts (
			description, post_dest, `text`, language, voice
		) VALUES (
			:description, :post_dest, :text, :language, :voice
		)";
		$params = [
			':description' => $description,
			':post_dest'   => $post_dest,
			':text'        => $text,
			':language'    => $language,
			':voice'       => $voice,
		];
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		$id = $this->freepbx->Database->lastInsertId();

		// Generate TTS file
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
			error_log("AnnouncementTTS TTS error for ID {$id}: " . $e->getMessage());
		}

		return $id;
	}

	/**
	 * Edit an existing announcement.
	 * Regenerates audio file only if text, language or voice changed.
	 */
	public function editAnnouncementtts($announcementtts_id, $description, $post_dest) {
		$old = $this->getAnnouncementttsByID($announcementtts_id);
		$oldText  = $old['text']     ?? '';
		$oldLang  = $old['language'] ?? '';
		$oldVoice = $old['voice']    ?? '';

		$text     = $_REQUEST['text']     ?? '';
		$language = $_REQUEST['language'] ?? 'en';
		$voice    = $_REQUEST['voice']    ?? 'nova';

		// Update database
		$sql = "UPDATE announcementtts SET
			description = :description,
			post_dest   = :post_dest,
			`text`      = :text,
			language    = :language,
			voice       = :voice
		WHERE announcementtts_id = :id";
		$params = [
			':description' => $description,
			':post_dest'   => $post_dest,
			':text'        => $text,
			':language'    => $language,
			':voice'       => $voice,
			':id'          => $announcementtts_id,
		];
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		// Only regenerate audio if content changed
		if ($text !== $oldText || $language !== $oldLang || $voice !== $oldVoice) {
			try {
				$wav = generate_tts($announcementtts_id, $text, $language, $voice);
				$fullPath = "/var/lib/asterisk/sounds/custom/{$wav}";
				$upd = $this->db->prepare(
					"UPDATE announcementtts SET audio_file = :audio_file WHERE announcementtts_id = :id"
				);
				$upd->execute([
					':audio_file' => $fullPath,
					':id'         => $announcementtts_id,
				]);
			} catch (\Exception $e) {
				error_log("AnnouncementTTS regen error for ID {$announcementtts_id}: " . $e->getMessage());
			}
		}

		return true;
	}

	/**
	 * Handle form submission for add/edit/delete from GUI.
	 */
	public function doConfigPageInit($page) {
		$request = $_REQUEST;
		$action = $request['action'] ?? '';
		if (isset($request['delete'])) {
			$action = 'delete';
		}

		$announcementtts_id = $request['announcementtts_id'] ?? false;
		$description = $request['description'] ?? '';
		$post_dest = $request['post_dest'] ?? '';

		// Handle GUI destination picker
		if (isset($request['goto0']) && $request['goto0']) {
			$post_dest = $request[$request['goto0'].'0'];
		}

		switch ($action) {
			case 'add':
				$this->addAnnouncementtts($description, $post_dest);
				needreload();
				break;
			case 'edit':
				$this->editAnnouncementtts($announcementtts_id, $description, $post_dest);
				needreload();
				break;
			default:
				break;
		}
	}

	/**
	 * Load right-side navigation in form view.
	 */
	public function getRightNav($request) {
		if (isset($_GET['view']) && $_GET['view'] == 'form') {
			return load_view(__DIR__."/views/rnav.php", []);
		}
	}
}
