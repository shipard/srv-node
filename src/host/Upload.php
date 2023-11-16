<?php


namespace Shipard\host;


use E10\json;
use E10\utils;

/**
 * Class Upload
 */
class Upload extends \Shipard\host\Core
{

	function uploadSensors($files)
	{
		$sensors = $this->app->loadCfgFile('/etc/shipard-node/iot-sensors.json');

		/*
		if (!$sensors)
		{
			return;
		}
		*/

		$iotDataSource = isset($this->app->nodeCfg['cfg']['iotDataSource']) ? $this->app->nodeCfg['cfg']['iotDataSource'] : NULL;
		//echo "iotDataSource: ".json_encode($iotDataSource)."\n";

		$tsdbData = '';
		$shpData = [
			'serverId' => $this->app->serverCfg['serverId'],
			'sensorsData' => [],
		];

		$cnt = 0;
		foreach ($files as $fileName)
		{
			$bn = basename($fileName);
			if ($bn[0] === '.')
				continue;

			//echo $bn . "\n";

			$sensorData = $this->app->loadCfgFile($fileName);
			if (!$sensorData)
				continue;

			$sensorCfg = isset($sensors[$sensorData['topic']]) ? $sensors[$sensorData['topic']] : NULL;

			$shpSensorData = $sensorData;
			if ($sensorCfg)
				$shpSensorData['sensorNdx'] = $sensorCfg['ndx'];
			$shpData['sensorsData'][] = $shpSensorData;

			if (!$sensorCfg || !isset($sensorCfg['id']) || $sensorCfg['id'] === '')
				continue;

			$tsdbData .= $sensorCfg['id'];
			if (isset($sensorCfg['tags']) && count($sensorCfg['tags']))
			{
				foreach ($sensorCfg['tags'] as $tagKey => $tagValue)
				{
					$tsdbData .= ','.$tagKey.'='.$tagValue;
				}
			}

			if ($sensorData['value'] === '' || $sensorData['value'] === 'nan' || $sensorData['value'] === false)
				$sensorData['value'] = 0;
			elseif ($sensorData['value'] === true)
				$sensorData['value'] = 1;

			if (is_string($sensorData['value']))
				$sensorData['value'] = floatval($sensorData['value']);

			$tsdbData .= ' value='.$sensorData['value'];
			$tsdbData .= ' '.$sensorData['time'];
			$tsdbData .= "\n";

			$cnt++;
			if ($cnt > 1000)
				break;
		}

		// -- shipard
		$url = $this->app->serverCfg['dsUrl'] . 'api/objects/call/iot-upload-sensors-data';

		$result = $this->app->apiSend ($url, $shpData);

		//echo "API-SEND `$url`: ".json_encode($result)."\n";

		// -- influxDb
		if ($iotDataSource !== NULL && $tsdbData !== '')
		{
			$url = $iotDataSource['url'].'api/v2/write';
			$url .= '?org=' . $iotDataSource['organizationId'];
			$url .= '&bucket=' . $iotDataSource['bucketId'];
			$url .= '&precision=ms';

			//echo "URL: " . $url . "\n";
			//echo "DATA:\n" . $tsdbData . "\n";

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_HTTPHEADER, [
				'Connection: Close',
				'Authorization: ' . 'Token ' . $iotDataSource['token'],
			]);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $tsdbData);
			$resultCode = curl_exec($curl);
			if ($resultCode !== '')
			{
				echo "ERROR-INFLUX-DB: " . json_encode($resultCode) . "\n";
				return;
			}
		}

		foreach ($files as $fileName)
		{
			unlink($fileName);
		}
	}

	function uploadFiles ($settings, $files)
	{
		$baseUrl = $settings['dsUrl'] ?? $this->app->serverCfg['dsUrl'];
		$uploadUrl = $baseUrl.'upload/'.$settings['table'].'/';

		$ch = curl_init();
		foreach ($files as $fileName)
		{
			$bn = basename($fileName);
			if ($bn[0] === '.')
				continue;

			//echo $bn."\n";

			$postData = file_get_contents($fileName);
			curl_setopt ($ch, CURLOPT_VERBOSE, 0);
			curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
			curl_setopt ($ch, CURLOPT_POST, true);
			curl_setopt ($ch, CURLOPT_POSTFIELDS, $postData);
			$result = curl_exec ($ch);

			if ($result === 'OK')
				unlink ($fileName);
		}

		curl_close ($ch);
	}

	public function run()
	{
		$uploadDir = '/var/lib/shipard-node/upload/';
		$counter = 0;
		while (1)
		{
			forEach (glob ($uploadDir.'*', GLOB_ONLYDIR) as $dir)
			{
				$id = strrchr($dir, '/');
				if ($id === FALSE)
				{
					continue;
				}
				$id = substr($id, 1);
				//echo $id." --> ".$dir."\n";


				$files = glob ($dir.'/*.json');

				if ($id === 'sensors')
				{
					if (count($files))
						$this->uploadSensors($files);
					continue;
				}


				$cfgString = @file_get_contents($dir.'/.settings');
				if ($cfgString === FALSE)
				{
					continue;
				}

				$cfgData = json_decode($cfgString, TRUE);
				if ($cfgData === FALSE)
					continue;

				//echo json_encode($files)."\n";
				if (count($files))
				{
					$this->uploadFiles ($cfgData, $files);
				}
			}

			$counter++;
			if ($counter > 100)
				break;

			sleep (10);
		}
	}
}
