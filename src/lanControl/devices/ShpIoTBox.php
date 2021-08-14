<?php

namespace Shipard\lanControl\devices;


/**
 * Class Passive
 */
class ShpIoTBox extends \Shipard\lanControl\devices\LanControlDeviceCore
{
	public function sendSystemInfo($fileName)
	{
		//  {"device": 14,"type": "system",
		//  "items":{"device-type": "iot-box-lan-core-esp32-evb",
		//  "version-fw": "0.81.4-842a7f3","version-os": "v3.2.3-14-gd3e562907","device-arch": "esp32"}}

		$srcInfo = $this->app->loadCfgFile($fileName);
		if (!$srcInfo)
		{
			return;
		}

		$agentInfo = [
			'osType' => 'iotbox',
			'osValues' => [
				'_saOS' => 'iotbox',
				'_saInfo' => 'os',

				'osName' => $srcInfo['items']['device-type'],
				'version-os' => $srcInfo['items']['version-fw'],
				'device-type' => $srcInfo['items']['device-type'],
				'version-fw' => $srcInfo['items']['version-os'],
			]
		];
		$this->app->sendUserAgentInfo($srcInfo['device'], $agentInfo);
	}
}

