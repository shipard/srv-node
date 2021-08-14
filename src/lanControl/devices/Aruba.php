<?php

namespace Shipard\lanControl\devices;


/**
 * Class Aruba
 */
class Aruba extends \Shipard\lanControl\devices\LanControlDeviceCore
{
	function shellCommand($commandType)
	{
		$user = $this->deviceCfg['cfg']['userLogin'];
		if ($user === '')
			$user = 'admin';

		$password = $this->deviceCfg['cfg']['userPassword'];

		$ip = $this->deviceCfg['ipManagement'];

		$cmd = '';

		switch ($commandType)
		{
			case 'getRunningConfig': $cmd = '/usr/lib/shipard-node/tools/lanControl/ap-aruba_getCfg.sh '; break;
			case 'runScript': $cmd = '/usr/lib/shipard-node/tools/lanControl/ap-aruba_runScript.sh '; break;
		}

		$cmd .= $ip.' '.$user.' "'.$password.'" '.$this->cmdScriptFileName;

		$cmd .= ' >'.$this->cmdResultFileName;
		$cmd .= ' 2>'.$this->cmdErrorsFileName;

		return $cmd;
	}

	function polishRunningConfig($runningConfig)
	{
		$rows = preg_split("/\\r\\n|\\r|\\n/", $runningConfig);

		$txt = '';

		// -- search begin: show running-config
		while(1)
		{
			$line = array_shift($rows);

			if ($line === NULL || substr($line, -19) === 'show running-config')
			{
				if ($line !== NULL)
				{
					$hostName = strstr($line, '#', TRUE);
					if ($hostName && $hostName !== '')
						$txt .= 'hostname ' . $hostName . "\n";
				}
				break;
			}
		}

		// -- append lines to 'end'
		$prevLine = '';
		while(1)
		{
			$line = array_shift($rows);
			if ($line === NULL)
				break;
			$line = rtrim($line);

			if ($prevLine === '' && $line === '')
				continue;

			if ($prevLine === '' && substr($line, -1) === '#')
				break;

			$txt .= $line."\n";

			$prevLine = $line;
		}

		return $txt;
	}
}
