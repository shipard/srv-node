<?php


namespace Shipard\incus;


/**
 * class IncusSync
 */
class IncusSync extends \Shipard\host\Core
{
	var $syncCfg = NULL;
	var $now;

	var $syncId = '';
	var $logFileName = '';

	public function init()
	{
		$this->syncCfg = $this->app->loadCfgFile('/etc/shipard-node/incusSync.json');
		if (!$this->syncCfg)
			return $this->app->err("parsing file `/etc/shipard-node/incusSync.json` failed");

		$this->now = new \DateTime();

		$this->syncId = $this->now->format('Y-m-d_H:i:s');

		$this->logFileName = '/var/lib/shipard-node/logs/incus/'.'sync-'.$this->syncId.'.log';

		return TRUE;
	}

	protected function sync()
	{
		if (!isset($this->syncCfg['remotes']))
			return;

		foreach ($this->syncCfg['remotes'] as $remote)
		{
			$this->syncInstances($remote);
		}
	}

	public function syncInstances($remote)
	{
		if (!isset($remote['instances']))
			return;

		foreach ($remote['instances'] as $instance)
		{
			$storage = 'default';
			if (isset($remote['storage']))
				$storage = $remote['storage'];
			if (isset($instance['storage']))
				$storage = $instance['storage'];

			$idFrom = $remote['id'].':'.$instance['id'];
			$idTo = $instance['id'];
			$params = '--stateless --refresh --refresh-exclude-older --quiet';
			$params .= ' --storage='.$storage;

			$cmd = 'incus copy '.$idFrom.' '.$idTo.' '.$params.' >> '.$this->logFileName.' 2>&1';

			//echo $cmd."\n";
			passthru($cmd);
		}
	}

	public function syncAll()
	{
		$this->sync();
	}
}
