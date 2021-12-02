<?php

namespace Shipard\iotBox\devices;



class Device extends \Shipard\Utility
{
	public function loop()
	{
	}

	protected function mqttSend($topic, $value)
	{
		$mqttHost = $this->app->nodeCfg['cfg']['mqttServerHost'] ?? '';

		if ($mqttHost !== '')
		{
			$cmd = 'mosquitto_pub -h '.$mqttHost.' -t "'.$topic.'" -m "'.str_replace("\"", "\\\"", strval($value)).'"';
			exec($cmd);
		}
	}

	protected function loadLocalSensors(): array
	{
		$redis = $this->app->redis();
		$ls = json_decode($redis->get('localSensors'), TRUE);
		if (!$ls)
			$ls = [];

		return $ls;
	}

	protected function saveLocalSensors(array $localSensors)
	{
		$redis = $this->app->redis();
		$redis->set('localSensors', json_encode($localSensors));
	}
}
