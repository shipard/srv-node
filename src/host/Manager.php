<?php


namespace Shipard\host;


/**
 * Class Manager
 */
class Manager extends \Shipard\host\Core
{
	public function check ()
	{
		$this->checkCronSymlink('shn-host');

		if ($this->needCamerasSupport())
			$this->checkCronSymlink('shn-cameras');

		$this->checkCronSymlink('shn-lan');

		$this->checkCmdSymlink('shipard-node', '/usr/lib/shipard-node/tools/shipard-node.php');

		$this->checkDirs();

		$this->checkSubsystems();

		$this->checkHostService ('shn-upload', '/usr/lib/shipard-node/etc/systemd');
		$this->checkHostService ('shn-lan-monitor', '/usr/lib/shipard-node/etc/systemd');
	}

	public function checkSubsystems ()
	{
		$sse = new \Shipard\host\Subsystems($this->app);
		$sse->check();
	}

	public function getCfgFromServer ()
	{
		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-get-node-server-cfg/'.$this->app->serverCfg['serverId'];
		$cfg = $this->app->apiCall($url);

		if (!$cfg || !$cfg['success'])
			return FALSE;

		$restartServices = [];

		// -- mqtt engine & things
		if (isset($cfg['cfg']['cfg']['iotThings']) && count($cfg['cfg']['cfg']['iotThings']))
		{
			$oldCheckSum = '';
			if (is_readable('/etc/shipard-node/mqtt-engine.json'))
				$oldCheckSum = sha1_file('/etc/shipard-node/mqtt-engine.json');

			file_put_contents('/etc/shipard-node/mqtt-engine.json', json_encode($cfg['cfg']['cfg']['iotThings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			unset($cfg['cfg']['cfg']['iotThings']);

			$newCheckSum = sha1_file('/etc/shipard-node/mqtt-engine.json');
			if ($oldCheckSum !== $newCheckSum)
				$restartServices[] = 'shn-mqtt-engine';
		}
		else
			@unlink('/etc/shipard-node/mqtt-engine.json');

		// -- iot-boxes-cfgs
		if (isset($cfg['cfg']['cfg']['iotBoxes']))
		{
			file_put_contents('/etc/shipard-node/iot-boxes.json', json_encode($cfg['cfg']['cfg']['iotBoxes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

			$dstPath = '/var/www/iot-boxes/cfg';
			if (!is_dir($dstPath))
				mkdir($dstPath, 0775, TRUE);

			foreach ($cfg['cfg']['cfg']['iotBoxes'] as $ib)
			{
				if (!count($ib['mac']))
					continue;
				$dataStr = json_encode($ib['cfg'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				foreach ($ib['mac'] as $mac)
				{
					$baseFileName = strtolower(str_replace(':', '-', $mac));
					$fullFileName = $dstPath.'/'.$baseFileName.'.json';
					file_put_contents($fullFileName, $dataStr);
				}
			}

			unset($cfg['cfg']['cfg']['iotBoxes']);
		}
		else
			@unlink('/etc/shipard-node/iot-boxes.json');


		// -- iotSensors
		if (isset($cfg['cfg']['cfg']['iotSensors']) && count($cfg['cfg']['cfg']['iotSensors']))
		{
			$oldCheckSum = '';
			if (is_readable('/etc/shipard-node/iot-sensors.json'))
				$oldCheckSum = sha1_file('/etc/shipard-node/iot-sensors.json');

			file_put_contents('/etc/shipard-node/iot-sensors.json', json_encode($cfg['cfg']['cfg']['iotSensors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			unset($cfg['cfg']['cfg']['iotSensors']);

			$newCheckSum = sha1_file('/etc/shipard-node/iot-sensors.json');
			//if ($oldCheckSum !== $newCheckSum)
			//	$restartServices[] = 'shn-mqtt-engine';
		}
		else
			@unlink('/etc/shipard-node/iot-sensors.json');

		file_put_contents('/etc/shipard-node/config.json', json_encode($cfg['cfg'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$this->restartHostServices($restartServices);

		return TRUE;
	}

	public function lanMonitoringGetDashboards()
	{
		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-get-lan-monitoring-dashboards/'.$this->app->serverCfg['serverId'];
		$cfg = $this->app->apiCall($url);

		if (!$cfg || !$cfg['success'] || !isset($cfg['cfg']['dashboards']))
			return FALSE;

		foreach ($cfg['cfg']['dashboards'] as $dashboardId => $dashboardCode)
		{
			$fn = '/var/lib/shipard-node/www/dashboards/'.$dashboardId.'.html';
			file_put_contents($fn, $dashboardCode);
		}

		if (!is_dir('/var/www/shipard-node/dashboards/'))
		{
			symlink('/var/lib/shipard-node/www/dashboards', '/var/www/shipard-node/dashboards');
		}

		return TRUE;
	}

	public function lanMonitoringGetSnmpCfg()
	{
		$netdataEtcDir = $this->netdataEtcDir();

		if (!$netdataEtcDir)
			return FALSE;

		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-get-lan-monitoring-snmp/'.$this->app->serverCfg['serverId'];
		$cfg = $this->app->apiCall($url);

		print_r($cfg);

		if (!$cfg || !$cfg['success'] || !isset($cfg['cfg']['realtime']) || !count($cfg['cfg']['realtime']))
		{
			return FALSE;
		}	

		$snmpConf = [
			'enable_autodetect' => FALSE,
			'update_every' => 10,
			'servers' => $cfg['cfg']['realtime'],
		];
		$snmpConfTxt = json_encode($snmpConf,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$newCheckSum = sha1($snmpConfTxt);

		$fn = $netdataEtcDir.'node.d/snmp.conf';

		$currentCheckSum = '';
		if (is_readable($fn))
			$currentCheckSum = sha1_file($fn);

		if ($newCheckSum !== $currentCheckSum)
		{
			file_put_contents($fn, $snmpConfTxt);
			shell_exec('service netdata restart');
		}

		return TRUE;
	}

	public function netDataAlarmsApiOn()
	{
		$netdataEtcDir = $this->netdataEtcDir();
		if (!$netdataEtcDir)
			return FALSE;

		if (!is_readable($netdataEtcDir.'health_alarm_notify.conf'))
		{
			echo "copy new health_alarm_notify.conf\n";
			copy($netdataEtcDir.'orig/health_alarm_notify.conf', $netdataEtcDir.'health_alarm_notify.conf');
		}

		$str = file_get_contents($netdataEtcDir.'health_alarm_notify.conf');
		if (!$str || $str === '')
		{
			echo "ERROR: file `{$netdataEtcDir}health_alarm_notify.conf` is not readable\n";
			return FALSE;
		}

		$changes = 0;

		$pos = strchr ($str, 'source /usr/lib/shipard-node/tools/shn-netdata-send.sh');
		if ($pos === FALSE)
		{
			echo "install `source /usr/lib/shipard-node/tools/shn-netdata-send.sh`\n";
			$str = "source /usr/lib/shipard-node/tools/shn-netdata-send.sh\n\n".$str;
			$changes++;
		}
		else
			echo "found `source /usr/lib/shipard-node/tools/shn-netdata-send.sh`\n";

		// -- DEFAULT_RECIPIENT_CUSTOM=""
		$pos = strchr ($str, 'DEFAULT_RECIPIENT_CUSTOM=""');
		if ($pos !== FALSE)
		{
			echo "install `DEFAULT_RECIPIENT_CUSTOM=\"sysadmin\"`\n";
			$str = str_replace('DEFAULT_RECIPIENT_CUSTOM=""', 'DEFAULT_RECIPIENT_CUSTOM="sysadmin"', $str);
			$changes++;
		}
		else
			echo "found `DEFAULT_RECIPIENT_CUSTOM=\"sysadmin\"`\n";

		// -- SEND_EMAIL=NO
		$pos = strchr ($str, 'SEND_EMAIL="YES"');
		if ($pos !== FALSE)
		{
			echo "install `SEND_EMAIL=\"NO\"`\n";
			$str = str_replace('SEND_EMAIL="YES"', 'SEND_EMAIL="NO"', $str);
			$changes++;
		}
		else
			echo "found `SEND_EMAIL=\"NO\"`\n";

		// -- info "not sending custom notification to ${to}, for ${status} of '${host}.${chart}.${name}' - custom_sender() is not configured."
		$pos = strchr ($str, 'info "not sending custom notification to ${to}, for ${status} of \'${host}.${chart}.${name}\' - custom_sender() is not configured."');
		if ($pos !== FALSE)
		{
			echo "install `send_to_shipard`\n";
			$str = str_replace('info "not sending custom notification to ${to}, for ${status} of \'${host}.${chart}.${name}\' - custom_sender() is not configured."', 'send_to_shipard', $str);
			$changes++;
		}
		else
			echo "found `send_to_shipard`\n";

		if ($changes)
		{
			echo "CHANGES: ".$changes."\n";
			file_put_contents($netdataEtcDir.'health_alarm_notify.conf', $str);
			echo "done, test delivery by run `/usr/libexec/netdata/plugins.d/alarm-notify.sh test`\n";
		}

		return TRUE;
	}

	public function cleanupDaily ()
	{
		$oldDir = getcwd();
		$cmdCleanOldFiles = 'find . -mmin +1440 -type f -delete';

		if (is_dir('/var/lib/shipard-node/imgcache'))
		{
			chdir ('/var/lib/shipard-node/imgcache');
			passthru ($cmdCleanOldFiles);
		}

		if (is_dir('/var/lib/shipard-node/tmp'))
		{
			chdir ('/var/lib/shipard-node/tmp');
			passthru ($cmdCleanOldFiles);
		}

		chdir ($oldDir);
	}

	public function cleanupHourly()
	{
		$this->log('cleanupHourly BEGIN');
		if (!is_dir($this->app->camsDir) || !$this->app->anyCamera())
		{
			$this->log('   no camera');
			return;
		}
		$minFreeSpaceGBVideos = isset ($this->app->serverCfg['diskFreeSpaceVideos']) ? intval($this->app->serverCfg['diskFreeSpaceVideos']) : 100;
		$minFreeSpaceVideos = $minFreeSpaceGBVideos*1024*1024*1024;

		$this->log ("   minFreeSpaceGBVideos: `$minFreeSpaceGBVideos` ==> `$minFreeSpaceVideos`");

		$videoDrivePath = isset ($this->app->serverCfg['diskFreeSpaceVideosDrive']) ? $this->app->serverCfg['diskFreeSpaceVideosDrive'] : $this->app->videoDir;

		$this->log("   videoDrivePath #1: `$videoDrivePath`");
		while (substr($videoDrivePath, -1) === '/')
			$videoDrivePath = substr($videoDrivePath, 0, -1);

		$this->log ("   videoDrivePath #2: `$videoDrivePath`");

		$df = disk_free_space($videoDrivePath);
		$sizeToFree = $minFreeSpaceVideos - $df;
		$this->log ("   df #0 `$df`; sizeToFree `$sizeToFree`");

		$cnt = 0;
		$ca = new \Shipard\cameras\Archive ($this->app);
		while ($sizeToFree > 0)
		{
			$ca->removeFirstHourVideo();
			$df = disk_free_space($videoDrivePath);
			$sizeToFree = $minFreeSpaceVideos - $df;
			$cnt++;
			$this->log ("   df #{$cnt} `$df`; sizeToFree `$sizeToFree`");

			if ($cnt > 12)
				break;
		}

		$this->log('cleanupHourly END');
	}

	public function upgrade ()
	{
		$channel = 'devel';
		if (isset($this->app->serverCfg['channel']))
			$channel = $this->app->serverCfg['channel'];

		// new
		$nodePkgInfoFileName = 'https://download.shipard.org/shipard-node/server-app-2/shipard-node-'.$channel.'.info';
		$nodePkgInfo = json_decode(file_get_contents($nodePkgInfoFileName), TRUE);
		if (!$nodePkgInfo)
			return;

		// this
		$currentVersionInfo = $this->app->loadCfgFile('/usr/lib/shipard-node/shipard-node.info');

		$doUpgrade = FALSE;
		if (!$currentVersionInfo || $currentVersionInfo['version'] !== $nodePkgInfo['version'])
			$doUpgrade = TRUE;

		if (intval($this->app->arg('force')))
			$doUpgrade = TRUE;

		if (!$doUpgrade)
			return;

		$nodePkgArchiveSrcFileName = 'https://download.shipard.org/shipard-node/server-app-2/'.$nodePkgInfo['fileName'];
		$nodePkgArchiveFileName = $nodePkgInfo['fileName'];

		$res = copy ($nodePkgArchiveSrcFileName, $nodePkgArchiveFileName);
		if (!$res)
			return;

		$archiveCheckSum = sha1_file($nodePkgArchiveFileName);

		if ($archiveCheckSum !== $nodePkgInfo['checkSum'])
			return;

		$cmd = "tar xzf $nodePkgArchiveFileName -C /";
		shell_exec($cmd);
		$cmd = "mv /usr/lib/shipard-node /usr/lib/shipard-node-old && mv /usr/lib/shipard-node-{$nodePkgInfo['version']} /usr/lib/shipard-node && rm -rf /usr/lib/shipard-node-old";

		shell_exec($cmd);

		$cmd = "chmod 0600 /usr/lib/shipard-node/etc/cron.d/*.conf";
		shell_exec($cmd);

		file_put_contents('/usr/lib/shipard-node/shipard-node.info', json_encode($nodePkgInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		//$this->check();
		$cmd = 'shipard-node server-check';
		shell_exec($cmd);
	}

	public function setServer($serverId, $dsUrl, $apiKey)
	{
		$serverCfg = "{\n";
		$serverCfg .= "\t\"dsUrl\": \"".$dsUrl."\",\n";
		$serverCfg .= "\t\"serverId\": \"".$serverId."\",\n";
		$serverCfg .= "\t\"apiKey\": \"".$apiKey."\"\n";
		$serverCfg .= "}\n";
		file_put_contents('/etc/shipard-node/server.json', $serverCfg);
	}
}
