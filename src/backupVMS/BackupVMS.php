<?php


namespace Shipard\backupVMS;



class BackupVMS extends \Shipard\host\Core
{
	var $backupCfg = NULL;
	var $dateStr = '';
	var $dateStrDir = '';
	var $now;

	var $info = [];

	public function init()
	{
		$this->backupCfg = $this->app->loadCfgFile('/etc/shipard-node/backupVMS.json');
		if (!$this->backupCfg)
			return $this->app->err("parsing file `/etc/shipard-node/backupVMS.json` failed");

		$this->now = new \DateTime();
		$this->dateStr = $this->now->format('Y-m-d');
		$this->dateStrDir = $this->now->format('Y/m/d');

		$this->info['dateBegin'] = $this->now->format('Y-m-d H:i:s');
		$this->info['dateEnd'] = NULL;

		return TRUE;
	}

	protected function backupContainers()
	{
		if (!isset($this->backupCfg['containers']))
			return;

		$this->info['containers']	= [];

		foreach ($this->backupCfg['containers'] as $vm)
		{
			$this->backupContainerFull($vm);
		}

		$now = new \DateTime();
		$this->info['dateEnd'] = $now->format('Y-m-d H:i:s');

		$backupInfoFileName = $this->backupCfg['destFolder'].'/'.'backup-info-'.$this->dateStr.'.json';
		file_put_contents ($backupInfoFileName, json_encode($this->info, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}

	public function backupContainerFull($vm)
	{
		if (!$this->checkDay($vm))
			return;

		$timeBegin = time();
		$now = new \DateTime();
		$vmBackupInfo = ['timeBegin' => $now->format('Y-m-d H:i:s')];

		$dstPath = $this->backupCfg['destFolder'].'/'.$vm['id'].'/'.$this->dateStr;
		if (!is_dir($dstPath))
			mkdir ($dstPath, 0750, TRUE);

		$vmBackupInfoFileName = $dstPath.'/'.'backupInfo.json';
		file_put_contents ($vmBackupInfoFileName, json_encode($vmBackupInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

		$dstFileName = $dstPath.'/'.$vm['id'].'-'.$this->dateStr.'.xz';

		$cmd = 'lxc export '.$vm['id'].' '.$dstFileName.' -q > /dev/null';

		//echo $cmd."\n";
		passthru($cmd);

		$timeEnd = time();
		$now = new \DateTime();
		$vmBackupInfo['timeEnd'] = $now->format('Y-m-d H:i:s');
		$vmBackupInfo['timeLen'] = $timeEnd - $timeBegin;
		$vmBackupInfo['bkpFileName'] = $dstFileName;

		file_put_contents ($vmBackupInfoFileName, json_encode($vmBackupInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

		$this->info['containers'][$vm['id']] = $vmBackupInfo;
	}

	public function checkDay($vm)
	{
		if (isset($vm['dow']))
		{
			$dow = intval($this->now->format('N'));
			if (is_array($vm['dow']) && !in_array($dow, $vm['dow']))
				return FALSE;
			if (!is_array($vm['dow']) && $dow !== $vm['dow'])
				return FALSE;
		}

		return TRUE;
	}

	public function backupAll()
	{
		$this->backupContainers();
	}
}
