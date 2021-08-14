<?php

namespace lib;


class Manager
{
	/** @var  \lib\Application */
	var $app;

	public function __construct($app)
	{
		$this->app = $app;
	}

	public function checkCronSymlink ($cfg)
	{
		$targetFileName = '/usr/lib/shipard-agent/etc/cron.d/'.$cfg.'.conf';
		chmod($targetFileName, 0640);
		chown($targetFileName, 'root');
		chgrp($targetFileName, 'root');

		$cronFileName = '/etc/cron.d/'.$cfg;
		if (!is_file($cronFileName))
		{
			symlink($targetFileName, $cronFileName);
		}
		chmod($cronFileName, 0640);
	}

	public function checkCmdSymlink ($cmd, $path)
	{
		$fileName = '/bin/'.$cmd;
		if (!is_file($fileName))
		{
			echo "$fileName --> {$path}\n";
			symlink($path, $fileName);
		}
	}

	public function check ()
	{
		$this->checkCronSymlink('shipard-agent');
		$this->checkCmdSymlink('shipard-agent', '/usr/lib/shipard-agent/bin/shipard-agent.php');
		$this->checkDirs();
	}

	public function checkDirs ()
	{
		if (!is_dir('/etc/shipard-agent'))
			mkdir('/etc/shipard-agent', 0775, TRUE);

		if (!is_dir('/var/lib/shipard-agent/tmp'))
			mkdir('/var/lib/shipard-agent/tmp', 0777, TRUE);
	}

	public function upgrade ()
	{
		$channel = 'stable';
		if (isset($this->app->agentCfg['channel']))
			$channel = $this->app->agentCfg['channel'];

		// new
		$pkgInfoFileName = 'https://download.shipard.org/shipard-agent/linux/shipard-agent-'.$channel.'.info';
		$pkgInfo = json_decode(file_get_contents($pkgInfoFileName), TRUE);
		if (!$pkgInfo)
			return;

		// this
		$currentVersionInfo = $this->app->loadCfgFile('/usr/lib/shipard-agent/shipard-agent.info');

		$doUpgrade = FALSE;
		if (!$currentVersionInfo || $currentVersionInfo['version'] !== $pkgInfo['version'])
			$doUpgrade = TRUE;

		if (!$doUpgrade)
			return;

		$pkgArchiveSrcFileName = 'https://download.shipard.org/shipard-agent/linux/'.$pkgInfo['fileName'];
		$pkgArchiveFileName = $pkgInfo['fileName'];

		$res = copy ($pkgArchiveSrcFileName, $pkgArchiveFileName);
		if (!$res)
			return;

		$archiveCheckSum = sha1_file($pkgArchiveFileName);

		if ($archiveCheckSum !== $pkgInfo['checkSum'])
			return;

		$cmd = "tar xzf $pkgArchiveFileName -C /";
		shell_exec($cmd);
		$cmd = "mv /usr/lib/shipard-agent /usr/lib/shipard-agent-old && mv /usr/lib/shipard-agent-{$pkgInfo['version']} /usr/lib/shipard-agent && rm -rf /usr/lib/shipard-agent-old";

		//echo $cmd."\n";
		shell_exec($cmd);

		file_put_contents('/usr/lib/shipard-agent/shipard-agent.info', json_encode($pkgInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$this->check();
	}
}