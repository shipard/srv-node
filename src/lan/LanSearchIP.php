<?php


namespace Shipard\lan;


/**
 * Class LanSearchIP
 */
class LanSearchIP extends \Shipard\lan\Lan
{
	protected function scanRange ($rangeId, $unknownsOnly)
	{
		$range = $this->lansCfg['ranges'][$rangeId];
		if ($unknownsOnly)
		{
			$rangeInfo = $this->ipv4rangeInfo($range['range']);
			$hosts = [];
			foreach ($rangeInfo['hosts'] as $ip)
			{
				if (isset($this->lansCfg['ip'][$ip]))
					continue;
				$hosts[] = $ip;
			}

			$hostsList = implode(' ', $hosts);
			$cmd = "/usr/bin/fping -aqe " . $hostsList;

			if ($this->app->debug)
				echo $cmd."\n";

			$result = shell_exec($cmd);
			$data = $this->parseFping($result, $rangeId);

			if ($this->app->debug === 2)
				print_r($data);

			foreach ($data as $ip)
				$this->addUnknownIp($ip);
		}
		else
		{
			foreach ($this->lansCfg['ip'] as $ip)
			{
				if ($ip['r'] !== $rangeId)
					continue;
				if ($ip['ip'] === '')
					continue;

				$hosts[] = $ip['ip'];

				$existedIp = $this->storage->getItem(self::icIP, $ip['ip']); // TODO: better place
				if (!count($existedIp))
				{
					$newIp = ['ip' => $ip['ip'], 'mac' => $ip['mac'], 'd' => $ip['d'], 'r' => $ip['r']];
					$this->addUnknownIp($newIp);
				}
			}
			$hostsList = implode(' ', $hosts);

			$cmd = '/usr/bin/fping -aqe ' . $hostsList;
			if ($this->app->debug)
				echo $cmd."\n";
			$result = shell_exec($cmd);
			$data = $this->parseFping($result, $rangeId);

			if ($this->app->debug === 2)
				print_r($data);

			$done = [];
			foreach ($data as $ip)
			{
				$this->addIpMonitoringInfo($ip);
				$done[] = $ip['ip'];
			}
			foreach ($hosts as $hostIp)
			{
				if (in_array($hostIp, $done))
					continue;
				$existedIp = $this->storage->getItem(self::icIP, $hostIp);
				if (!count($existedIp))
					continue;
				if (!isset($existedIp['ip']))
					$existedIp['ip'] = $hostIp;
				$existedIp['r'] = $rangeId;
				$existedIp['rt'] = -1;
				$existedIp['time'] = time()+date('Z');
				$this->addIpMonitoringInfo($existedIp);
			}
			$this->checkChanges($rangeId);
		}
	}

	public function searchUnknowns()
	{
		if (!$this->lansCfg)
			return;

		foreach ($this->lansCfg['ranges'] as $rangeId => $range)
		{
			$this->scanRange($rangeId, TRUE);
		}
	}

	function checkChanges($rangeId)
	{
		$tmpPath = '/var/lib/shipard-node/tmp/';
		$mqttHost = isset($this->app->nodeCfg['cfg']['mqttServerHost']) ? $this->app->nodeCfg['cfg']['mqttServerHost'] : '';
		$alertKey = 'alert-mac-lan';

		$thisTime = time()+date('Z');

		foreach ($this->lansCfg['ip'] as $ip)
		{
			if ($ip['ip'] === '')
				continue;
			if ($ip['r'] !== $rangeId)
				continue;

			$alertMode = isset($ip['a']) ? $ip['a'] : 2;

			$info = $this->storage->getItem(self::icIP, $ip['ip']);
			$lastAlertTime = isset($info[$alertKey]) ? intval($info[$alertKey]) : 0;

			if (!isset($info['rt0v']) || !isset($info['rt1v']) || !isset($info['rt2v']))
				continue;

			$changeState = 0;
			if (floatval($info['rt0v']) < 0.0 && floatval($info['rt1v']) < 0.0 && floatval($info['rt2v']) > 0.0)
			{ // really down
				$changeState = 1;
			}
			elseif (floatval($info['rt0v']) > 0.0 && floatval($info['rt1v']) > 0.0 && floatval($info['rt2v']) < 0.0)
			{ // really up
				$changeState = 2;
			}
			elseif (floatval($info['rt0v']) < 0.0 && floatval($info['rt1v']) < 0.0 && floatval($info['rt2v']) < 0.0)
			{ // still down
				if (!$lastAlertTime || ($thisTime - $lastAlertTime > 3600))
					$changeState = 1;
			}

			if (!$changeState)
				continue;

			$deviceNdx = isset($ip['d']) ? $ip['d'] : 0;
			$eventData = [
				'type' => 'ip-state-change',
				'ip' => $ip['ip'],
				'device' => $deviceNdx,
				'srcDevice' => intval($this->app->serverCfg['serverId']),
				'time' => $info['rt0t'],
				'state' => $changeState,
				'ipInfo' => $info,
			];

			$fn = $tmpPath.'maclan-alerts-' . time() . '-' . mt_rand(100000, 999999) .'-'.$ip['ip']. '.json';
			file_put_contents($fn, json_encode($eventData));
			$this->storage->setField (Lan::icIP, $ip['ip'], $alertKey, strval($thisTime));


			// -- alert send
			if ($alertMode == 1)
			{
				$alert = [
					'alertType' => 'mac-lan',
					'alertKind' => 'mac-lan-device-state',
					'alertSubject' => 'Device #'.$deviceNdx.' / '.$ip['ip'].' is '.($changeState === 2 ? 'UP' : 'DOWN'),
					'alertId' => 'mac-lan-device-state-' . ($deviceNdx ? $deviceNdx : $ip['ip']),
					'payload' => $eventData,

				];
				$this->app->sendAlert($alert);
			}

			// -- mqtt send
			$topic = 'shp/lan/ip-state';
			$cmd = 'mosquitto_pub -h ' . $mqttHost . ' -t ' . $topic . ' -f ' . $fn;

			if ($mqttHost !== '')
				exec($cmd);
		}
	}

	public function monitor()
	{
		if (!$this->lansCfg)
		{
			echo "cfg not found \n";
			return;
		}

		foreach ($this->lansCfg['ranges'] as $rangeId => $range)
		{
			$this->scanRange($rangeId, FALSE);
		}
	}

	public function saveToUpload ()
	{
		$ips = $this->storage->getItems(Lan::icIP);
		if (count($ips))
		{
			$data = ['type' => Lan::uiUnknownIp, 'data' => []];

			foreach ($ips as $ip)
			{
				if (isset($ip['ip']) && isset($ip['time']))
				{
					if (isset($this->lansCfg['ip'][$ip['ip']]))
						continue;
					$data['data'][] = $ip;
				}
			}

			$this->saveUploadInfo(Lan::uiUnknownIp, $data);
		}
	}
}
