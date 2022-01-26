<?php


namespace Shipard\host;


/**
 * Class Core
 */
class Core extends \Shipard\Utility
{
	var $redis;
	var $logFileName;

	public function __construct($app)
	{
		parent::__construct($app);

		$today = new \DateTime();
		$this->logFileName = '/var/lib/shipard-node/tmp/cams-log-'.$today->format('Y-m-d');

		$this->redis = new \Redis ();
		$this->redis->connect('127.0.0.1');
	}

	function log($msg)
	{
		$now = new \DateTime();
		$data = $now->format ('Y-m-d_H:i:s').' '.$msg."\n";
		file_put_contents($this->logFileName, $data, FILE_APPEND);

		if ($this->app->debug)
			echo $data;
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

	public function checkCronSymlink ($cfg)
	{
		$targetFileName = '/usr/lib/shipard-node/etc/cron.d/'.$cfg.'.conf';
		chmod($targetFileName, 0600);
		chown($targetFileName, 'root');
		chgrp($targetFileName, 'root');

		$cronFileName = '/etc/cron.d/'.$cfg;
		if (!is_file($cronFileName))
		{
			symlink($targetFileName, $cronFileName);
		}
		chmod($cronFileName, 0600);
	}

	public function checkDirs ()
	{
		if (!is_dir('/etc/shipard-node'))
			mkdir('/etc/shipard-node', 0775, TRUE);

		if (!is_dir('/etc/shipard-node/scripts'))
			mkdir('/etc/shipard-node/scripts', 0775, TRUE);

		if ($this->needCamerasSupport())
		{
			if (!is_dir('/etc/shipard-node/scripts/cameras'))
				mkdir('/etc/shipard-node/scripts/cameras', 0775, TRUE);
		}

		if (!is_dir('/var/lib/shipard-node/tmp'))
			mkdir('/var/lib/shipard-node/tmp', 0777, TRUE);

		if (!is_dir('/var/lib/shipard-node/imgcache'))
			mkdir('/var/lib/shipard-node/imgcache', 0777, TRUE);
		chmod('/var/lib/shipard-node/imgcache', 0777);

		if (!is_dir('/var/lib/shipard-node/hostInfo'))
			mkdir('/var/lib/shipard-node/hostInfo', 0775, TRUE);

		if (!is_dir('/var/lib/shipard-node/upload'))
			mkdir('/var/lib/shipard-node/upload', 0775, TRUE);

		if (!is_dir('/var/lib/shipard-node/upload/alerts'))
			mkdir('/var/lib/shipard-node/upload/alerts', 0775, TRUE);

		if (!is_dir('/var/lib/shipard-node/upload/sensors'))
			mkdir('/var/lib/shipard-node/upload/sensors', 0775, TRUE);

		if (!is_dir('/var/lib/shipard-node/upload/iot'))
			mkdir('/var/lib/shipard-node/upload/iot', 0775, TRUE);
		
		if (!is_file('/var/lib/shipard-node/upload/iot/.settings'))
		{
			$settingsData = ['table' => 'mac.iot.devices'];
			file_put_contents('/var/lib/shipard-node/upload/iot/.settings', json_encode($settingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
	
		if (!is_dir('/var/www/shipard-node'))
		{
			mkdir('/var/www/shipard-node', 0755, TRUE);
			symlink('/usr/lib/shipard-node/www/index.php', '/var/www/shipard-node/index.php');
		}

		if (!is_dir('/var/lib/shipard-node/www'))
			mkdir('/var/lib/shipard-node/www', 0775, TRUE);

		if (!is_dir('/var/lib/shipard-node/www/dashboards'))
			mkdir('/var/lib/shipard-node/www/dashboards', 0775, TRUE);

		if ($this->needCamerasSupport())
		{
			if (!is_dir($this->app->camsDir))
				mkdir($this->app->camsDir, 0775, TRUE);

			if (!is_dir($this->app->picturesDir))
				mkdir($this->app->picturesDir, 0775, TRUE);
			chown($this->app->picturesDir, 'ftp');

			if (!is_dir($this->app->videoDir))
				mkdir($this->app->videoDir, 0775, TRUE);

			if (!is_dir($this->app->vehicleDetectDir))
				mkdir($this->app->vehicleDetectDir, 0775, TRUE);
		}
	}

	function checkHostService ($serviceBaseFileName, $serviceFileSrcFolder)
	{
		$serviceDstFileName = '/etc/systemd/system/'.$serviceBaseFileName.'.service';
		$serviceSrcFileName = $serviceFileSrcFolder.'/'.$serviceBaseFileName.'.service';

		if (is_file($serviceDstFileName))
		{ // new version?
			$srcCheckSum = md5_file($serviceSrcFileName);
			$dstCheckSum = md5_file($serviceDstFileName);
			if ($srcCheckSum !== $dstCheckSum)
			{
				copy ($serviceSrcFileName, $serviceDstFileName);
				$cmd = "systemctl daemon-reload && systemctl stop {$serviceBaseFileName}.service && systemctl start $serviceBaseFileName";
				shell_exec($cmd);
			}
			return;
		}

		// -- install
		copy ($serviceSrcFileName, $serviceDstFileName);
		$cmd = "cd /etc/systemd/system/ && systemctl enable {$serviceBaseFileName}.service && systemctl start $serviceBaseFileName";
		shell_exec($cmd);
		// -- start
		$cmd = "systemctl daemon-reload && systemctl start $serviceBaseFileName";
		shell_exec($cmd);
	}

	function needCamerasSupport ()
	{
		if (isset($this->app->nodeCfg['cfg']['enableCams']) && $this->app->nodeCfg['cfg']['enableCams'])
			return TRUE;
		return FALSE;
	}

	protected function restartHostService($service, $operation = 'restart')
	{
		shell_exec('/usr/sbin/service '.$service.' '.$operation);
	}

	protected function restartHostServices($services, $operation = 'restart')
	{
		foreach ($services as $service)
			$this->restartHostService($service, $operation);
	}
}
