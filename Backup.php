<?php
namespace FreePBX\modules\Announcementtts;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
  public function runBackup($id,$transaction){
    $this->addDependency('Core');
    $this->addDependency('Recordings');
    $this->addConfigs($this->FreePBX->Announcementtts->getAnnouncementstts());
  }
}