<?php

namespace Shipard\iotBox;


class Loop extends \Shipard\Utility
{
	var array $devices = [];
	var int $sleepInterval = 200_000;
	var int $maxCount = 216_000;

	public function init()
	{
		$this->devices['oneWire'] = new \Shipard\iotBox\devices\OneWire($this->app);
		$this->devices['board'] = new \Shipard\iotBox\devices\Board($this->app);
		return TRUE;
	}

	public function loop()
	{
		$index = 0;
		while (1)
		{
			foreach ($this->devices as $deviceId => $device)	
			{
				$device->loop();
			}
			usleep(100_000);
			$index++;
			if ($index > $this->maxCount)
				break;
		}
	}
}
