<?php


namespace Shipard\backupServer;



class BackupServer extends \Shipard\host\Core
{
	var $backupCfg = NULL;
	var $dateStr = '';
	var $dateStrDir = '';
	var $server = '';

	public function init()
	{
		$this->backupCfg = $this->app->loadCfgFile('/etc/shipard-node/backupServer.json');

		$dateNow = new \DateTime();
		$this->dateStr = $dateNow->format('Y-m-d');
		$this->dateStrDir = $dateNow->format('Y/m/d');
	}

	protected function downloadDataSources()
	{
		foreach ($this->backupCfg['dsServers'] as $srv)
		{
			if ($this->server && $this->server !== $srv['hostName'])
				continue;

			$this->downloadDataSourcesServer($srv);
		}
	}

	protected function downloadDataSourcesServer($srv)
	{
		$remoteUser = $this->hostRemoteUser($srv);
		$port = $this->hostPort($srv);
		$hostName = $this->hostName($srv);
		$hostBackupDir = $this->hostBackupDir($srv);

		// -- prepare server dir
		$serverDestDir = $this->backupCfg['destFolder'].'dsServers/'.$hostName.'/'.$this->dateStr;
		if (!is_dir($serverDestDir))
			mkdir ($serverDestDir, 0750, TRUE);

		// -- load backup info
		$cmd = "scp -P $port {$remoteUser}@$hostName:$hostBackupDir/{$this->dateStr}/backupInfo.json $serverDestDir/";
		echo $cmd . "\n";
		passthru($cmd);
		$backupInfoFileName = $serverDestDir.'/backupInfo.json';
		$backupInfo = json_decode(file_get_contents($backupInfoFileName), TRUE);

		// -- host files
		if (isset($backupInfo['hostFiles']))
		{
			foreach ($backupInfo['hostFiles'] as $hf)
			{
				$cmd = "scp -P $port {$remoteUser}@$hostName:{$hf['fileName']} $serverDestDir/";
				echo $cmd . "\n";
				passthru($cmd);
			}
		}

		// -- databases
		foreach ($backupInfo['dataSources'] as $backup)
		{
			$dsDestDir = $this->backupCfg['destFolder'].'dataSources/'.$backup['dsid'].'/'.$this->dateStrDir;
			if (!is_dir($dsDestDir))
				mkdir ($dsDestDir, 0750, TRUE);

			$cmd = "scp -P $port {$remoteUser}@$hostName:{$backup['bkpFileName']} {$dsDestDir}/";
			echo $cmd . "\n";
			passthru($cmd);
		}

		// -- attachments
		foreach ($backupInfo['dataSources'] as $backup)
		{
			if (!$backup['syncAttachments'])
				continue;

			$syncDestDir = $this->backupCfg['destFolder'].'dataSources/'.$backup['dsid'].'/sync';
			if (!is_dir($syncDestDir))
				mkdir ($syncDestDir, 0750, TRUE);

			$cmd = "rsync -azk -e \"ssh -p $port\" {$remoteUser}@$hostName:{$backup['serverPath']}/att $syncDestDir";
			echo $cmd . "\n";
			passthru($cmd);
		}
	}

	protected function downloadNodeServers()
	{
		if (!isset($this->backupCfg['nodeServers']))
			return;

		foreach ($this->backupCfg['nodeServers'] as $srv)
		{
			if ($this->server && $this->server !== $srv['hostName'])
				continue;

			echo $srv['hostName']."\n";
			$this->downloadNodeServer($srv);
		}
	}

	protected function downloadNodeServer($srv)
	{
		$remoteUser = $this->hostRemoteUser($srv);
		$port = $this->hostPort($srv);
		$hostName = $this->hostName($srv);
		$hostBackupDir = '/var/lib/shipard-node/backups/';
		$localDestDir = $this->backupCfg['destFolder'].'nodeServers/'.$hostName.'/'.$this->dateStr;

		if (!is_dir($localDestDir))
			mkdir ($localDestDir, 0750, TRUE);

		$cmd = "scp -r -P $port {$remoteUser}@$hostName:$hostBackupDir/{$this->dateStr}/* $localDestDir";
		echo $cmd . "\n";
		passthru($cmd);
	}

	protected function hostRemoteUser($h)
	{
		if (isset($h['remoteUser']))
			return $h['remoteUser'];
		if (isset($this->backupCfg['defaults']['remoteUser']))
			return $this->backupCfg['defaults']['remoteUser'];

		return '';
	}

	protected function hostName($h)
	{
		if (isset($h['hostName']))
			return $h['hostName'];

		return '';
	}

	protected function hostBackupDir($h)
	{
		if (isset($h['backupDir']))
			return $h['backupDir'];

		return '/var/lib/shipard/backups';
	}

	protected function hostPort($h)
	{
		if (isset($h['port']))
			return $h['port'];
		if (isset($this->backupCfg['defaults']['port']))
			return $this->backupCfg['defaults']['port'];

		return 22;
	}

	public function downloadAll()
	{
		$this->downloadDataSources();
		$this->downloadNodeServers();
	}
}
