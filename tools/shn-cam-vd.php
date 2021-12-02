#!/usr/bin/env php
<?php

require_once __DIR__.'/../src/node.php';


/**
 * Class WatchVDApp
 * version 0.3
 */
class WatchVDApp extends \Shipard\Application
{
	var $vdDir;

	public function watch()
	{
		$mqttHost = isset($this->nodeCfg['cfg']['mqttServerHost']) ? $this->nodeCfg['cfg']['mqttServerHost'] : '';

		$fd = inotify_init();
		$watch_descriptor = inotify_add_watch($fd, $this->vdDir, IN_CLOSE_WRITE|IN_MOVED_TO);

		while (1)
		{
			$events = inotify_read($fd);

			foreach ($events as $event)
			{
				$imgFileName = $event['name']; // 34_20190808095532670_4Z52506_VEHICLE_DETECTION.jpg

				$parts = explode('_', $imgFileName);
				if (count($parts)  !== 5)
					continue;

				$cameraCfg = (isset($this->nodeCfg['cfg']['cameras'][$parts[0]])) ? $this->nodeCfg['cfg']['cameras'][$parts[0]] : NULL;

				$topic = isset($cameraCfg['cfg']['vdTopic']) ? $cameraCfg['cfg']['vdTopic'] : '';//'shp/access/gate/vd/'.$gateId.'/'.$eventData['cam'];
				if ($topic === '')
				{
					continue;
				}


				if (!$cameraCfg)
				{

					continue;
				}

				$eventData = [
					'action' => 'vd',
					'lp' => $parts[2],
					'cam' => intval($parts[0]),
					'img' => $imgFileName,
				];

				$cmd = 'mosquitto_pub -h '.$mqttHost.' -t "'.$topic.'" -m "'.str_replace("\"", "\\\"", json_encode($eventData)).'"';

				if ($mqttHost !== '')
				{
					error_log("MQTT-VD-CMD: ".$cmd);
					exec($cmd);
				}
			}
		}

		inotify_rm_watch($fd, $watch_descriptor);
	}

	public function run ()
	{
		$this->vdDir = $this->picturesDir.'vehicle-detect';
		$this->watch();
	}
}

$myApp = new WatchVDApp ($argv);
$myApp->run ();

