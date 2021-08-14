<?php


namespace Shipard\host;


/**
 * Class Core
 */
class NetDataAlarm
{
	/** @var  \lib\Application */
	var $app;

	var $alarmData = [];

	public function __construct($app)
	{
		$this->app = $app;
	}

	public function loadFromFile($fileName)
	{
		$data = file_get_contents($fileName);
		if (!$data)
			return;

		$rows = preg_split("/\\r\\n|\\r|\\n/", $data);
		foreach ($rows as $row)
		{
			$parts = explode(':', $row);
			if (count($parts) < 1)
				continue;

			$key = trim($parts[0]);
			if ($key === '')
				continue;

			array_shift($parts);
			$value = implode(':', $parts);
			$this->alarmData[$key] = trim($value);
		}
	}

	public function send()
	{
		$alertSubject = '';
		$alertSubject .= $this->alarmData['host'];
		$alertSubject .= ' '.$this->alarmData['status_message'];
		$alertSubject .= ': '.$this->alarmData['info'];
		$deviceNdx = intval($this->app->serverCfg['serverId']);

		$changeState = 0;
		if ($this->alarmData['status']=== 'CRITICAL')
			$changeState = 1;
		elseif ($this->alarmData['status']=== 'CLEAR')
			$changeState = 2;
		else
			$changeState = 1;

		$eventData = [
			'type' => 'mac-lan-netdata-alarm',
			'device' => $deviceNdx,
			'srcDevice' => intval($this->app->serverCfg['serverId']),
			'time' => $this->alarmData['when'],
			'state' => $changeState,
			'alarmData' => $this->alarmData,
		];

		// -- alert send
		$alert = [
			'alertType' => 'mac-lan',
			'alertKind' => 'mac-lan-netdata-alarm',
			'alertSubject' => $alertSubject,
			'alertId' => 'mac-lan-netdata-'.$deviceNdx.'-'.$this->alarmData['alarm_id'],
			'payload' => $eventData,
		];
		$this->app->sendAlert($alert);
	}
}
