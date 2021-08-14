#!/usr/bin/env php
<?php

require_once __DIR__.'/../lib/agent.php';


/**
 * Class AgentApp
 */
class AgentApp extends \lib\Application
{
	public function doIt ()
	{
		$i = new lib\InfoOS($this);
		$i->run();

		return TRUE;
	}

	public function hostUpgrade ()
	{
		$hm = new \lib\Manager($this);
		$hm->upgrade();
		return TRUE;
	}

	public function hostCheck ()
	{
		$hm = new \lib\Manager($this);
		$hm->check();
		return TRUE;
	}

	public function run ()
	{
		if (!$this->superuser())
			return $this->err ('Need to be root');

		switch ($this->command ())
		{
			case	'do-it':     				return $this->doIt();
			case	'host-upgrade':  		return $this->hostUpgrade();
			case	'host-check':   		return $this->hostCheck();

		}
		echo ("unknown or nothing param....\r\n");
		return FALSE;
	}
}

$myApp = new AgentApp($argv);
$myApp->run ();
