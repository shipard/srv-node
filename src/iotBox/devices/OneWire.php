<?php

namespace Shipard\iotBox\devices;

class OneWire extends Device
{
	var string $oneWireFolder = '/sys/bus/w1/devices';
	var int $nextMeasure = 0;
	var int $measureInterval = 20;
	var array $values = [];

	public function loop()
	{
		$now = time();
		if ($now < $this->nextMeasure)
			return;

		$ls = $this->loadLocalSensors();
		$changes = 0;
	
		forEach (glob($this->oneWireFolder.'/*', GLOB_ONLYDIR) as $deviceFileName)
		{
			$deviceFileId = basename($deviceFileName);
			if (str_starts_with($deviceFileId, 'w1'))
				continue;
			if (!is_readable($deviceFileName.'/temperature'))
				continue;
			$deviceId	= $deviceFileId;
			$tempStr = trim(file_get_contents($deviceFileName.'/temperature'));
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
