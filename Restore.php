<?php
namespace FreePBX\modules\Announcementtts;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
	public function runRestore(){
		$configs = $this->getConfigs();
		$this->processData($configs);
	}

	public function processLegacy($pdo, $data, $tables, $unknownTables){
		$this->restoreLegacyDatabase($pdo);
	}

	public function processData($configs){
		$fields = [
			'description',
			'recording_id',
			'post_dest',
		];
		$sth = $this->FreePBX->Database->prepare("INSERT INTO announcementtts (`announcementtts_id`, `description`, `post_dest`) VALUES (:announcement_id, :description, :recording_id, :post_dest)");
		foreach ($configs as $config) {
			foreach ($fields as $field) {
				isset($config[$field])? ${$field} = $config[$field] : ${$field} = null;
			}
			$sth->execute([
				":announcementtts_id" => $config['announcement_id'],
				":description" => $config['description'],
				":post_dest" => $config['post_dest'],
			]);
		}
	}
}
