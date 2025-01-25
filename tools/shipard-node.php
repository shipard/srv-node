#!/usr/bin/env php
<?php

require_once __DIR__.'/../src/node.php';


/**
 * Class NodeApp
 */
class NodeApp extends \Shipard\Application
{
	function anyCamera ()
	{
		if (isset($this->nodeCfg['cfg']['cameras']) && count($this->nodeCfg['cfg']['cameras']))
			return TRUE;

		return FALSE;
	}

	public function getCfgFromServer ()
	{
		$hm = new \Shipard\host\Manager($this);
		if (!$hm->getCfgFromServer())
			return $this->err ('ERROR!');

		return TRUE;
	}

	public function getNodeTokensFromServer ()
	{
		$hm = new \Shipard\host\Manager($this);
		if (!$hm->getNodeTokensFromServer())
			return $this->err ('ERROR!');

		return TRUE;
	}

	public function cfgInstallCerts()
	{
		$cm = new \Shipard\host\CertsManager($this);
		if (!$cm->downloadCerts())
			return $this->err ('Download certificates failed...');

		return TRUE;
	}

	public function cfgInit ()
	{
		$serverId = intval($this->arg('server-id'));
		if (!$serverId)
			return $this->err("param `--server-id` not found...");

		$dsUrl = $this->arg('ds-url');
		if (!$dsUrl)
			return $this->err("param `--ds-url` not found...");

		$apiKey = $this->arg('api-key');
		if (!$apiKey)
			return $this->err("param `--api-key` not found...");

		$hm = new \Shipard\host\Manager($this);
		$hm->setServer($serverId, $dsUrl, $apiKey);

		$cmd = 'shipard-node cfg-get';
		passthru($cmd);

		$cmd = 'shipard-node host-check';
		passthru($cmd);

		return TRUE;
	}

	public function resetScripts ()
	{
		$cm = new \Shipard\host\Cameras($this);
		$cm->resetScripts();
		return TRUE;
	}

	public function fwDownload ()
	{
		$fwd = new \Shipard\host\FWDownload($this);
		$fwd->run();
		return TRUE;
	}

	public function fwDownload3rd ()
	{
		$debug = intval($this->arg('debug'));
		$fwd = new \Shipard\host\FWDownload($this);
		$fwd->download3rd($debug);
		return TRUE;
	}

	public function fwList ()
	{
		$fwd = new \Shipard\host\FWDownload($this);
		$fwd->fwListAll();
		return TRUE;
	}

	public function fwUpgradeIotBoxes ()
	{
		$fwd = new \Shipard\host\FWDownload($this);
		$fwd->fwUpgradeIotBoxes();
		return TRUE;
	}

	public function hostCheck ()
	{
		$hm = new \Shipard\host\Manager($this);
		$hm->check();
		return TRUE;
	}

	public function cleanupHourly()
	{
		$hm = new \Shipard\host\Manager($this);
		$hm->cleanupHourly();
		return TRUE;
	}

	public function cleanupDaily()
	{
		$hm = new \Shipard\host\Manager($this);
		$hm->cleanupDaily();
		return TRUE;
	}

	public function serverBackup ()
	{
		$thisHostName = $this->cfgItem($this->serverCfg, 'thisHostName', gethostname());
		$localBackupOwner = 'root:root';
		if (is_dir ('/home/johns'))
			$localBackupOwner = 'johns:root';
		elseif (is_dir ('/home/js') || is_dir ('/var/lib/e10/js'))
			$localBackupOwner = 'js:root';

		$localBackupDir = $this->cfgItem($this->serverCfg, 'localBackupDir', "/var/lib/shipard-node/backups");
		$thisLocalBackupDir = $localBackupDir . '/' . date ('Y-m-d');
		mkdir ($thisLocalBackupDir, 0770, TRUE);

		// -- /etc
		exec ("cd / && tar -Pczf $thisLocalBackupDir/etc-$thisHostName-" . date ('Y-m-d') . ".tgz /etc/");

		// -- /opt/shipard-node
		if (is_dir('/opt/shipard-node'))
			exec ("cd / && tar -Pczf $thisLocalBackupDir/opt-shipard-node-$thisHostName-" . date ('Y-m-d') . ".tgz /opt/shipard-node/");

		// -- /opt/shn-exts
		if (is_dir('/opt/shn-exts'))
			exec ("cd / && tar -Pczf $thisLocalBackupDir/opt-shn-exts-$thisHostName-" . date ('Y-m-d') . ".tgz /opt/shn-exts/");

		// -- /var/lib/shipard-node/lc/ssh
		if (is_dir('/var/lib/shipard-node/lc/ssh'))
			exec ("cd / && tar -Pczf $thisLocalBackupDir/lcssh-$thisHostName-" . date ('Y-m-d') . ".tgz /var/lib/shipard-node/lc/ssh/");

		exec ("chown -R $localBackupOwner $thisLocalBackupDir");

		// -- remove backup week ago
		$thisLocalBackupDir = $localBackupDir . '/' . date ('Y-m-d', strtotime('-1 week'));
		if (is_dir($thisLocalBackupDir))
			exec ('rm -rf '.$thisLocalBackupDir);

		// -- upload to server
		if (0)
		{
			$backupUser = $this->cfgItem($this->serverCfg, 'backupUser', '');
			$backupHost = $this->cfgItem($this->serverCfg, 'backupHost', '');
			$remoteBackupDir = $this->cfgItem($this->serverCfg, 'remoteBackupDir', "/home/{$backupUser}/backups");

			$remoteThisBackupDir = $remoteBackupDir . '/' . date('Y/m/d');
			$uploadCmd = "ssh -l {$backupUser} {$backupHost} mkdir -p $remoteThisBackupDir";
			exec($uploadCmd);
			$uploadCmd = "scp $thisLocalBackupDir/* {$backupUser}@{$backupHost}:/$remoteThisBackupDir";
			exec($uploadCmd);
		}
	}

	public function serverUpgrade ()
	{
		$hm = new \Shipard\host\Manager($this);
		$hm->upgrade();
		return TRUE;
	}

	public function hostReboot ()
	{
		$disableDailyReboot = $this->cfgItem($this->serverCfg, 'disableDailyReboot', 0);
		if (!$disableDailyReboot)
			exec ('/sbin/reboot');
		return TRUE;
	}

	public function hostUpload ()
	{
		$hm = new \Shipard\host\Upload($this);
		$hm->run();
		return TRUE;
	}

	public function scanArchive ()
	{
		if (!$this->anyCamera())
			return FALSE;

		$ca = new \Shipard\cameras\Archive ($this);
		$ca->scan();
		return TRUE;
	}

	public function lanMonitor ()
	{
		$eng = new \Shipard\lan\LanSearchIP($this);
		$eng->monitor();

		$lan = new \Shipard\lan\LanInfo($this);
		$data = $lan->status();

		$requestData = [
			'info' => 'devicesStatus',
			'serverId' => $this->serverCfg['serverId'],
			'serverVersion' => $this->versionInfo,
			'data' => $data
		];

		$url = $this->serverCfg['dsUrl'].'/api/objects/call/mac-lan-info-upload';
		$result = $this->apiSend ($url, $requestData);
		if ($this->debug)
		{
			echo "--- send data: [{$url}]\n";
			echo json_encode($requestData)."\n";
			echo "------> result:\n";
			echo json_encode($result)."\n\n";
		}
		//echo json_encode($result)."\n\n";

		if ($this->nodeCfg['ver'] !== $result['cfgDataVer'])
		{ // get new settings from server
			shell_exec('shipard-node cfg-get');
			shell_exec('shipard-node lan-monitoring-get-dashboards');
			shell_exec('shipard-node lan-monitoring-get-snmp-cfg');
		}

		// -- lanControl - changed devices
		if (isset($result['changedDevices']) && $result['changedDevices'])
		{
			touch ($this->rootDir.'tmp/lanControlChangedDevices');
		}
	}

	public function lanSearchArp ()
	{
		$eng = new \Shipard\lan\LanSearchARP($this);
		$eng->search();
		return TRUE;
	}

	public function lanSearchSNMP ()
	{
		$host = $this->arg('host');
		$eng = new \Shipard\lan\LanSearchSNMP($this);
		if ($host === FALSE)
			$eng->search();
		else
			$eng->scanHost($host);
		return TRUE;
	}

	public function lanSearchUnknowns ()
	{
		$eng = new \Shipard\lan\LanSearchIP($this);
		$eng->searchUnknowns();
		$eng->saveToUpload();
		return TRUE;
	}

	public function lanControlGet()
	{
		$eng = new \Shipard\lanControl\LanControlHost($this);
		$eng->lanControlGet();
		return TRUE;
	}

	public function lanControlRequests()
	{
		$eng = new \Shipard\lanControl\LanControlHost($this);
		$eng->runAllRequests();
		return TRUE;
	}

	public function lanMonitoringGetDashboards()
	{
		$hm = new \Shipard\host\Manager($this);
		if (!$hm->lanMonitoringGetDashboards())
			return $this->err ('ERROR!');

		return TRUE;
	}

	public function lanMonitoringGetSnmpCfg()
	{
		$hm = new \Shipard\host\Manager($this);
		if (!$hm->lanMonitoringGetSnmpCfg())
			return $this->err ('ERROR!');

		return TRUE;
	}

	function lanMonitoringMacs()
	{
		$eng = new \Shipard\lanControl\LanControlHost($this);
		$eng->getMacs();
		return TRUE;
	}

	function netDataAlarm()
	{
		$fileName = $this->arg('file');
		if (!$fileName || !is_readable($fileName))
			return FALSE;

		$eng = new \Shipard\host\NetDataAlarm($this);
		$eng->loadFromFile($fileName);
		$eng->send();

		return TRUE;
	}

	function netDataAlarmsAPIOn()
	{
		$hm = new \Shipard\host\Manager($this);
		$hm->netDataAlarmsApiOn();

		return TRUE;
	}

	function iotBoxInfo()
	{
		// @TODO: delete!
		$fileName = $this->arg('file');
		$eng = new \Shipard\lanControl\devices\ShpIoTBox($this);
		$eng->sendSystemInfo($fileName);
		return TRUE;
	}

	function esignsImages()
	{
		$eng = new \Shipard\esigns\ESignManager($this);
		$eng->run();
		return TRUE;
	}

	function rtiCapsmanServer()
	{
		$hm = new Shipard\lanControl\devices\mikrotik\RTICapsmanServer($this);
		$hm->run();

		return TRUE;
	}

	protected function bkpSrvDownload()
	{
		$server = $this->arg('server');

		$eng = new \Shipard\backupServer\BackupServer($this);
		if ($server)
			$eng->server = $server;
		$eng->init();
		$eng->downloadAll();
		return TRUE;
	}

	protected function bkpSrvCheckAtts()
	{
		$eng = new \Shipard\backupServer\BackupServer($this);

		$server = $this->arg('server');
		if ($server)
			$eng->server = $server;
		$dsId = $this->arg('dsId');
		if ($dsId)
			$eng->dsId = $dsId;

		$checkPeriod = $this->arg('checkPeriod');
		if ($checkPeriod)
			$eng->checkPeriod = $checkPeriod;

		$eng->init();
		$eng->bkpSrvCheckAtts();
		return TRUE;
	}

	protected function	bkpSrvRepairAtts()
	{
		$eng = new \Shipard\backupServer\BackupServer($this);

		$eng->init();
		$eng->bkpSrvRepairAtts();
		return TRUE;
	}

	protected function bkpVMSBackup()
	{
		$eng = new \Shipard\backupVMS\BackupVMS($this);

		if (!$eng->init())
			return FALSE;

		$eng->backupAll();
		return TRUE;
	}

	protected function incusSync()
	{
		$eng = new \Shipard\incus\IncusSync($this);

		if (!$eng->init())
			return FALSE;

		$eng->syncAll();
		return TRUE;
	}

	function sendUserAgentInfo($deviceNdx, $agentInfo)
	{
		$now = new \DateTime();
		$introText = ";;;shipard-agent: node ".$this->versionInfo['version']."\n";
		$introText .= ";;;os: ".$agentInfo['osType']."\n";
		$introText .= ";;;deviceNdx: ".$deviceNdx."\n";
		$introText .= ";;;date: ".$now->format('Ymd\THms')."\n";
		$introText .= "\n\n";
		$introText .= ';;;shipard-agent-system-info';
		$introText .= "\n\n";

		foreach ($agentInfo['osValues'] as $key => $value)
		{
			$introText .= trim($key).' : '.trim($value)."\n";
		}
		$introText .= "\n\n";

		$url = $this->serverCfg['dsUrl'].'/upload/mac.lan.lans';
		$result = $this->http_post($url, $introText);
	}

	function version ()
	{
		if ($this->versionInfo['version'] === '0.0')
		{
			$pkg = $this->loadCfgFile('/usr/lib/shipard-node/shipard-version.json');
			if ($pkg)
			{
				echo "devel ".$pkg['version']."\n";
				return;
			}
		}
		echo $this->versionInfo['version']."\n";
	}

	public function run ()
	{
		//if (!$this->superuser())
		//	return $this->err ('Need to be root');

		switch ($this->command ())
		{
			case	'cfg-init':     				return $this->cfgInit();
			case	'cfg-get':     					return $this->getCfgFromServer();
			case	'cfg-get-node-tokens':  return $this->getNodeTokensFromServer();
			case	'cfg-install-certs':    return $this->cfgInstallCerts();
			case	'cfg-reset-scripts':   	return $this->resetScripts();

			case	'host-check':   				return $this->hostCheck();
			case	'host-cleanup-daily':   return $this->cleanupDaily();
			case	'host-cleanup-hourly':	return $this->cleanupHourly();
			case	'server-backup':  			return $this->serverBackup();
			case	'server-check':   			return $this->hostCheck();
			case	'server-upgrade':  			return $this->serverUpgrade();
			case	'host-reboot':  				return $this->hostReboot();
			case	'host-upload':  				return $this->hostUpload();

			case	'fw-download':  				return $this->fwDownload();
			case	'fw-list':  						return $this->fwList();
			case	'fw-upgrade-iot-boxes': return $this->fwUpgradeIotBoxes();
			case	'fw-download-3rd':  		return $this->fwDownload3rd();


			case	'cameras-scan-archive': return $this->scanArchive();

			case	'lan-monitor':					return $this->lanMonitor();
			case	'lan-search-arp':				return $this->lanSearchArp();
			case	'lan-search-snmp':			return $this->lanSearchSNMP();
			case	'lan-search-unknowns':	return $this->lanSearchUnknowns();

			case	'lan-control-get':			return $this->lanControlGet();
			case	'lan-control-requests':	return $this->lanControlRequests();

			case	'lan-monitoring-get-dashboards':	return $this->lanMonitoringGetDashboards();
			case	'lan-monitoring-get-snmp-cfg':		return $this->lanMonitoringGetSnmpCfg();
			case	'lan-monitoring-macs':	return $this->lanMonitoringMacs();

			case	'rti-capsman-server':		return $this->rtiCapsmanServer();

			case	'iot-box-info':					return $this->iotBoxInfo();

			case	'bkpsrv-download':			return $this->bkpSrvDownload();
			case	'bkpsrv-check-atts':		return $this->bkpSrvCheckAtts();
			case	'bkpsrv-repair-atts':		return $this->bkpSrvRepairAtts();

			case	'bkpvms-backup':				return $this->bkpVMSBackup();
			case	'incus-sync':						return $this->incusSync();

			case	'netdata-alarm':				return $this->netDataAlarm();
			case	'netdata-alarms-api-on':	return $this->netDataAlarmsApiOn();

			case	'esigns-images':				return $this->esignsImages();

			case	'version':							return $this->version();
		}

		echo ("unknown or nothing param....\r\n");
		return FALSE;
	}
}

$myApp = new NodeApp ($argv);
$myApp->run ();
