#!/usr/bin/env php
<?php

require_once __DIR__.'/../src/node.php';


/**
 * Class IotBoxApp
 */
class IotBoxApp extends \Shipard\Application
{	
	public function run ()
	{
		$loop = new \Shipard\iotBox\Loop($this);
		if (!$loop->init())
			return;
		$loop->loop();
	}
}

$myApp = new IotBoxApp ($argv);
$myApp->run ();
