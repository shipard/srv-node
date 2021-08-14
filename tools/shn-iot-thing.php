#!/usr/bin/env php
<?php

require_once __DIR__.'/../lib/node.php';


/**
 * Class IotThingApp
 *
 * shn-iot-thing value --type="" --topic="/shp/..." -payload="abcde1234"
 */
class IotThingApp extends \lib\Application
{
	var $cls = [
		'gate' => "\\lib\\iotThings\\Gate",
		'rfid-access-key-tester' => "\\lib\\iotThings\\RfidAccessKeyTester"
		];


	public function doValue ()
	{
		$type = $this->arg('type');
		if (!$type)
		{
			echo "missing type\n";
			return FALSE;
		}

		$topic = $this->arg('topic');
		if (!$topic)
		{
			echo "missing topic\n";
			return FALSE;
		}

		$payload = $this->arg('payload');
		if ($payload === FALSE)
		{
			echo "missing payload\n";
			return FALSE;
		}

		if (!isset($this->cls[$type]))
		{
			echo "ERROR: unknown type\n";
			return FALSE;
		}

		$classId = $this->cls[$type];
		/** @var \lib\iotThings\Core $o */
		$o = new $classId();
		if (!$o)
		{
			echo "ERROR: class not found! \n";
			return FALSE;
		}
		$o->app = $this;
		$o->setCmdParams($topic, $payload);
		$o->run();

		return TRUE;
	}

	public function doAction ()
	{
		$type = $this->arg('type');
		if (!$type)
		{
			echo "missing type\n";
			return FALSE;
		}

		$thing = $this->arg('thing');
		if (!$thing)
		{
			echo "missing thing\n";
			return FALSE;
		}

		$thingAction = $this->arg('thing-action');
		if ($thingAction === FALSE)
		{
			echo "missing thing-action\n";
			return FALSE;
		}

		$iotControl = $this->arg('iot-control');
		if ($iotControl === FALSE)
		{
			echo "missing iot-control\n";
			return FALSE;
		}

		if (!isset($this->cls[$type]))
		{
			echo "ERROR: unknown type\n";
			return FALSE;
		}

		$classId = $this->cls[$type];
		/** @var \lib\iotThings\Core $o */
		$o = new $classId();
		if (!$o)
		{
			echo "ERROR: class not found! \n";
			return FALSE;
		}
		$o->app = $this;
		$o->setActionParams($iotControl, $thing, $thingAction);
		$o->doAction();

		return TRUE;
	}

	public function run ()
	{
		switch ($this->command ())
		{
			case	'value':     				return $this->doValue();
			case	'action':     			return $this->doAction();
		}
		echo ("unknown or nothing param....\r\n");
		return FALSE;
	}
}

$myApp = new IotThingApp ($argv);
$myApp->run ();
