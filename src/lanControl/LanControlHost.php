<?php

namespace Shipard\lanControl;


/**
 * Class LanControlHost
 */
class LanControlHost
{
	/** @var  \Shipard\Application */
	var $app;

	public function __construct($app)
	{
		$this->app = $app;
	}

	function lanControlGet()
	{
		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-get-lan-control-cfg/'.$this->app->serverCfg['serverId'];
		$cfg = $this->app->apiCall($url);

		if (!$cfg || !$cfg['success'])
			return FALSE;

		$devices = json_encode($cfg['cfg']['devices'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		file_put_contents('/etc/shipard-node/lanControlDevices.json', $devices);

		foreach ($cfg['cfg']['requests'] as $request)
		{
			$fn = '/var/lib/shipard-node/lc/requests/'.$request['device'].'.json';
			$requestStr = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			file_put_contents($fn, $requestStr);

			// -- send request state
			$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-set-lan-control-request-state/'.$this->app->serverCfg['serverId'];
			$resultState = ['device' => $request['device'], 'state' => 3, 'result' => 1, 'resultLog' => ''];

			$res = $this->app->apiSend($url, $resultState);
		}

		return TRUE;
	}

	function runAllRequests()
	{
		$mask = '/var/lib/shipard-node/lc/requests/*.json';
		forEach (glob($mask) as $requestFileName)
		{
			$requestCfg = $this->app->loadCfgFile($requestFileName);
			if (!$requestCfg)
			{

				continue;
			}

			if ($this->runRequest($requestCfg))
			{
				unlink($requestFileName);
			}
		}
	}

	function runRequest($request)
	{
		/** @var \lib\lanControl\devices\LanControlDeviceCore $d */
		$d = $this->createDevice($request['device']);
		if (!$d)
			return FALSE;

		return $d->runRequest($request);
	}

	function createDevice($deviceNdx)
	{
		$deviceCfg = $this->app->lanControlDeviceCfg($deviceNdx);
		if (!$deviceCfg)
			return NULL;

		$deviceType = $deviceCfg['macDeviceType'];

		$devicesClasses = [
			'router-mikrotik' => 'MikrotikRouter',
			'switch-mikrotik-crs1-crs2' => 'MikrotikSwitchCRS12',
			'switch-mikrotik-crs3' => 'MikrotikSwitchCRS3',
			'switch-edgecore' => 'EdgeCore',
			'switch-passive' => 'Passive',
			'ap-aruba' => 'Aruba',
		];

		if (!isset($devicesClasses[$deviceType]))
			return NULL;

		$fullClassName = "\\lib\\lanControl\\devices\\".$devicesClasses[$deviceType];
		if (class_exists ($fullClassName))
		{
			/** @var \lib\lanControl\devices\LanControlDeviceCore $o */
			$o = new $fullClassName ($this->app);
			$o->setDeviceCfg($deviceCfg);
			return $o;
		}

		return NULL;
	}

	public function getMacs()
	{
		$allFileName = '/var/lib/shipard-node/lans/macs-all.json';
		$diffFileName = '/var/lib/shipard-node/lans/macs-diff.json';

		$now = new \DateTime();
		$allMacs = ['created' => $now->format('Y-m-d H:i:s'), 'type' => 'all', 'macs' => [], 'devices' => []];
		$diffMacs = ['created' => $now->format('Y-m-d H:i:s'), 'type' => 'diff', 'macs' => [], 'devices' => []];

		$latestAllMacs = [];
		if (is_readable($allFileName))
			$latestAllMacs = $this->app->loadCfgFile($allFileName);
		if (!$latestAllMacs)
			$latestAllMacs = [];

		$sendData = 'all';
		if (isset ($latestAllMacs['sentTime']) && (time() - $latestAllMacs['sentTime']) < 3600)
		{
			$allMacs['sentTime'] = $latestAllMacs['sentTime'];
			$sendData = 'diff';
		}
		else
			$allMacs['sentTime'] = time();

		$allDevices = $this->app->lanControlDeviceCfg(NULL);
		if (!$allDevices)
			return;

		snmp_set_oid_numeric_print(SNMP_OID_OUTPUT_NUMERIC);
		snmp_set_quick_print(1);
		snmp_set_enum_print(1);

		foreach ($allDevices as $deviceNdx => $deviceCfg)
		{
			if (!isset($deviceCfg['ipManagement']))
				continue;

			if (!isset($deviceCfg['macDeviceType']))
				continue;
			if (substr($deviceCfg['macDeviceType'], 0, 6) !== 'switch' && substr($deviceCfg['macDeviceType'], 0, 6) !== 'router')
				continue;

			if ($this->app->debug)
				echo '==== get macs for device '.$deviceCfg['ipManagement']." ====\n";

			$data = @snmprealwalk($deviceCfg['ipManagement'], 'public', '1.3.6.1.2.1.17.4.3.1.2');
			if ($this->app->debug === 2)
			{
				echo "    DATA:\n";
				print_r($data);
				echo "----\n";
			}

			if ($data === FALSE || $data === NULL)
				continue;

			$parsedMacs = [];
			foreach ($data as $key => $val)
			{
				$portNumber = intval($this->app->format_snmp_string($val));

				$macDec = substr($key, 24);
				$macDecParts  = explode ('.', $macDec);
				if (count($macDecParts) !== 6)
					continue;

				$macHex = sprintf('%02x:%02x:%02x:%02x:%02x:%02x', $macDecParts[0], $macDecParts[1], $macDecParts[2], $macDecParts[3], $macDecParts[4], $macDecParts[5]);
				$parsedMacs[$portNumber][] = $macHex;

				$allMacs['devices'][$deviceNdx][$portNumber][] = $macHex;
				$allMacs['macs'][$macHex][] = ['d' => $deviceNdx, 'p' => $portNumber];
			}
		}

		// -- create diff

		// -- new/modified macs on ports
		foreach ($allMacs['macs'] as $mac => $onPorts)
		{
			if (!isset($latestAllMacs['macs'][$mac]) || json_encode($latestAllMacs['macs'][$mac]) !== json_encode($onPorts))
				$diffMacs['macs'][$mac] = $onPorts;
		}
		// -- deleted macs on ports¨
		if (isset($latestAllMacs['macs']))
		{
			foreach ($latestAllMacs['macs'] as $mac => $onPorts) {
				if (!isset($allMacs['macs'][$mac]))
					$diffMacs['macs'][$mac] = NULL;
			}
		}

		// -- new/modified devices ports
		foreach ($allMacs['devices'] as $deviceNdx => $devicePorts)
		{
			foreach ($devicePorts as $portNum => $portMacs)
			{
				if (!isset($latestAllMacs['devices'][$deviceNdx][$portNum]) ||
					json_encode($latestAllMacs['devices'][$deviceNdx][$portNum]) !== json_encode($portMacs))
					$diffMacs['devices'][$deviceNdx][$portNum] = $portMacs;
			}
		}

		// -- deleted devices ports
		if (isset($latestAllMacs['devices']))
		{
			foreach ($latestAllMacs['devices'] as $deviceNdx => $devicePorts) {
				foreach ($devicePorts as $portNum => $portMacs) {
					if (!isset($allMacs['devices'][$deviceNdx][$portNum]))
						$diffMacs['devices'][$deviceNdx][$portNum] = NULL;
				}
			}
		}

		// -- save
		$diffMacsStr = json_encode($diffMacs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		file_put_contents($diffFileName, $diffMacsStr);
		file_put_contents($diffFileName.'.data', serialize($diffMacs));

		$allMacsStr = json_encode($allMacs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		file_put_contents($allFileName, $allMacsStr);
		file_put_contents($allFileName.'.data', serialize($allMacs));

		// -- upload
		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-lan-macs-upload/'.$this->app->serverCfg['serverId'];

		if ($sendData === 'all')
			$res = $this->app->apiSend($url, $allMacs);
		else
			$res = $this->app->apiSend($url, $diffMacs);

		if ($this->app->debug)
		{
			echo "==== site macs: ====\n";
			echo "count: ".count($allMacs['macs']).", size: ".strlen($allMacsStr)."\n";

			if ($this->app->debug === 2)
				echo $allMacsStr."\n";
		}
	}
}


