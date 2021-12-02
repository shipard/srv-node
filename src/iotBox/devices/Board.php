<?php

namespace Shipard\iotBox\devices;

class Board extends Device
{
	var array $temperatureFiles = ['cpu' => '/sys/class/thermal/thermal_zone0/temp'];
	var int $nextMeasure = 0;
	var int $measureInterval = 30;
	var array $values = [];

	public function loop()
	{
		$now = time();
		if ($now < $this->nextMeasure)
			return;

		$ls = $this->loadLocalSensors();
		$changes = 0;

		forEach ($this->temperatureFiles as $deviceId => $deviceFileName)
		{
			if (!is_readable($deviceFileName))
				continue;
			
			$tempStr = trim(file_get_contents($deviceFileName));
			$temp = round(intval($tempStr) / 1000, 1);
			if (isset($this->values[$deviceId]) && $this->values[$deviceId] === $temp)
				continue;
			$this->values[$deviceId] = $temp;	

			$topic = 'shp/sensors/temperature/'.$this->app->nodeCfg['cfg']['id'].'/'.$deviceId;
			$ls[$deviceId] = [
				'value' => $temp, 
				'topic' => $topic,
				'time' => time(),	
			];
			
			//echo $deviceId.": `$topic` ==> `{$this->values[$deviceId]}` \n";

			$this->mqttSend($topic, $temp);
			$changes++;
		}

		if ($changes)
			$this->saveLocalSensors($ls);

		$this->nextMeasure = time() + $this->measureInterval;
	}
}
