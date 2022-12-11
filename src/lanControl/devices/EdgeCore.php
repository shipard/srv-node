<?php

namespace Shipard\lanControl\devices;


/**
 * Class EdgeCore
 */
class EdgeCore extends \Shipard\lanControl\devices\LanControlDeviceCore
{
	function shellCommand($commandType)
	{
		$user = 'js';
		$ip = $this->deviceCfg['ipManagement'];

		$cmd = '';

		switch ($commandType)
		{
			case 'getRunningConfig': $cmd = '/usr/lib/shipard-node/tools/lanControl/switch-edgecore_getCfg.sh '; break;
			case 'runScript': $cmd = '/usr/lib/shipard-node/tools/lanControl/switch-edgecore_runScript.sh '; break;
			case 'getDeviceInfo': $cmd = '/usr/lib/shipard-node/tools/lanControl/switch-edgecore_getDeviceInfo.sh '; break;
		}

		$cmd .= $ip.' '.$user.' '.$this->cmdScriptFileName;

		$cmd .= ' >'.$this->cmdResultFileName;
		$cmd .= ' 2>'.$this->cmdErrorsFileName;

		return $cmd;
	}

	function polishRunningConfig($runningConfig)
	{
		$rows = preg_split("/\\r\\n|\\r|\\n/", $runningConfig);

		$txt = '';

		// -- search begin: Building running configuration. Please wait...
		while(1)
		{
			$line = array_shift($rows);

			if ($line === NULL || $line === 'Building running configuration. Please wait...')
				break;
		}

		// -- append lines to 'end'
		$prevLine = '';
		while(1)
		{
			$line = array_shift($rows);
			if ($line === NULL)
				break;
			$line = rtrim($line);
			if ($line === '')
				continue;

			if ($prevLine === '!' && $line === '!')
				continue;

			if ($prevLine === 'end' && $line === '!')
				break;

			$txt .= $line."\n";

			$prevLine = $line;
		}

		return $txt;
	}

	function doDeviceInfo($dataStr, &$info)
	{
		/*
		Unit 1
		 Serial Number          : EC1821004423
		 Hardware Version       : R01
		 Number of Ports        : 28
		 Main Power Status      : Up
		 Role                   : Master
		 Loader Version         : 0.2.1.1
		 Linux Kernel Version   : 2.6.19
		 Operation Code Version : 1.2.2.9

		System Description : ECS2100-28T
		System OID String  : 1.3.6.1.4.1.259.10.1.43.104
		System Information
		 System Up Time         : 44 days, 17 hours, 23 minutes, and 30.63 seconds
		 System Name            : Z406_SW_1U
		 System Location        :
		 System Contact         :
		 MAC Address (Unit 1)   : 3C-2C-99-CB-AA-20
		 Web Server             : Disabled
		 Web Server Port        : 80
		 Web Secure Server      : Enabled
		 Web Secure Server Port : 443
		 Telnet Server          : Disabled
		 Telnet Server Port     : 23
		 Jumbo Frame            : Disabled
		Unit 1

		 Main Power Status      : Up
		 */

		$infoValues = [
			'Serial Number' => 'device-sn', 'System Description' => 'device-type',
			'Operation Code Version' => 'version-os', 'Loader Version' => 'version-fw',
			'Linux Kernel Version' => 'Linux Kernel Version'
		];

		$data = $this->parseDoublePointRows($dataStr);
		if (!$data || !count($data))
			return;

		foreach ($data as $key => $value)
		{
			if (isset($infoValues[$key]))
				$info[$infoValues[$key]] = $value;
		}

		// -- shipard agent
		$agentInfo = [
			'osType' => 'edgecore',
			'osValues' => [
				'_saOS' => 'edgecore',
				'_saInfo' => 'os',
			]
		];

		$typeParts = explode('-', $info['device-type'] ?? 'unknown-type');
		$osName = 'edgecore-'.strtolower((isset($typeParts[0]) ? $typeParts[0] : 'unknown'));
		$agentInfo['osValues']['osName'] = $osName;

		foreach ($data as $key => $value)
		{
			if (isset($infoValues[$key]))
				$agentInfo['osValues'][$infoValues[$key]] = $value;

			$agentInfo['osValues'][$key] = $value;
		}

		$info['agentInfo'] = $agentInfo;
	}
}
