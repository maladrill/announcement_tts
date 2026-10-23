<?php
namespace FreePBX\modules\Announcementtts;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
 
  public function runBackup($id,$transaction){
    $this->addDependency('Core');
    $service = $this->FreePBX->Announcementtts->service();
    $service->withLock(function () use ($service) {
      $rows = $service->all();
      $this->addConfigs(['schema' => 1, 'announcements' => $rows]);
      foreach ($rows as $row) {
        $path = $service->audioPath($row['audio_file'] ?? '');
        if ($path === null) {
          $this->log('Audio missing for announcement ID ' . $row['announcementtts_id'], 'WARNING');
          continue;
        }
        $this->addFile(basename($path), dirname($path), 'ASTVARLIBDIR', 'announcementtts-audio');
      }
    });
  }
}
