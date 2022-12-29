<?php

namespace Shipard\lanControl\devices;


/**
 * Class MikrotikRouter
 */
class MikrotikRouter extends \Shipard\lanControl\devices\LanControlDeviceCore
{
	function shellCommand($commandType)
	{
		$user = $this->deviceCfg['cfg']['userLogin'];
		if ($user === '')
			$user = 'admin';

		$ip = $this->deviceCfg['ipManagement'];
		$port = 30022;

		$cmd = 'ssh ';
		$cmd .= $user.'@'.$ip;
		$cmd .= ' -p '.$port;
		$cmd .= ' -F /var/lib/shipard-node/lc/ssh/config_mikrotik';
		$cmd .= ' -i /var/lib/shipard-node/lc/ssh/shn_ssh_key';
		$cmd .= ' -oStrictHostKeyChecking=no';

		switch ($commandType)
		{
			case 'getRunningConfig': $cmd .= ' "export terse;ip neighbor export terse verbose;tool export terse verbose;ip upnp export terse verbose;ip socks export terse verbose;ip cloud export terse verbose"'; break;
			case 'runScript': $cmd .= ' -T <'.$this->cmdScriptFileName; break;
			case 'getDeviceInfo': $cmd .= ' "/system resource print; /system routerboard print"'; break;
		}

		$cmd .= ' >'.$this->cmdResultFileName;
		$cmd .= ' 2>'.$this->cmdErrorsFileName;

		return $cmd;
	}

	function doDeviceInfo($dataStr, &$info)
	{
		/*
		   uptime: 1d14h6m41s
            version: 6.44.2 (stable)
         build-time: Apr/01/2019 12:47:57
   factory-software: 6.28
        free-memory: 15.5GiB
       total-memory: 15.9GiB
                cpu: tilegx
          cpu-count: 36
      cpu-frequency: 1200MHz
           cpu-load: 0%
     free-hdd-space: 882.7MiB
    total-hdd-space: 1024.0MiB
  architecture-name: tile
         board-name: CCR1036-8G-2S+
           platform: MikroTik
       routerboard: yes
             model: CCR1036-8G-2S+
     serial-number: 742907610A54
     firmware-type: tilegx
  factory-firmware: 3.33
  current-firmware: 6.44.2
  upgrade-firmware: 6.44.2
		 */

		$infoValues = [
			'serial-number' => 'device-sn', 'architecture-name' => 'device-arch', 'model' => 'device-type',
			'version' => 'version-os', 'current-firmware' => 'version-fw'
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
				'osType' => 'mikrotik',
				'osValues' => [
					'_saOS' => 'mikrotik',
					'_saInfo' => 'os',
				]
		];

		$agentInfo['osValues']['osName'] = 'RouterOS';

		foreach ($data as $key => $value)
		{
			if (isset($infoValues[$key]))
				$agentInfo['osValues'][$infoValues[$key]] = $value;

			$agentInfo['osValues'][$key] = $value;
		}

		$info['agentInfo'] = $agentInfo;
	}
}

