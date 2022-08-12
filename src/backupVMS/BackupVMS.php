<?php


namespace Shipard\backupVMS;



class BackupVMS extends \Shipard\host\Core
{
	var $backupCfg = NULL;
	var $dateStr = '';
	var $dateStrDir = '';
	var $now;

	public function init()
	{
		$this->backupCfg = $this->app->loadCfgFile('/etc/shipard-node/backupVMS.json');
		if (!$this->backupCfg)
			return $this->app->err("parsing file `/etc/shipard-node/backupVMS.json` failed");

		$this->now = new \DateTime();
		$this->dateStr = $this->now->format('Y-m-d');
		$this->dateStrDir = $this->now->format('Y/m/d');

		return TRUE;
	}

	protected function backupContainers()
	{
		if (!isset($this->backupCfg['containers']))
			return;

		foreach ($this->backupCfg['containers'] as $vm)
		{
			$this->backupContainerFull($vm);
		}
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

		echo $cmd."\n";
		passthru($cmd);

		$timeEnd = time();
		$now = new \DateTime();
		$vmBackupInfo['timeEnd'] = $now->format('Y-m-d H:i:s');
		$vmBackupInfo['timeLen'] = $timeEnd - $timeBegin;

		file_put_contents ($vmBackupInfoFileName, json_encode($vmBackupInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
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
