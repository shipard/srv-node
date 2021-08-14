#!/usr/bin/env php
<?php

require_once __DIR__.'/../src/node.php';


/**
 * Class EmailApp
 */
class EmailApp extends \Shipard\Application
{	
	public function run ()
	{
		$sed = new \Shipard\email\ShipardEmailDelivery($this);
		if (!$sed->init())
			return;
		$sed->doIncomingEmail($this->dstEmailAddress);
	}
}

$myApp = new EmailApp ($argv);
$myApp->dstEmailAddress = $argv[2];
$myApp->run ();
