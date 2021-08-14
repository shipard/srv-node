<?php

namespace Shipard\lanControl\devices;


/**
 * Class LanControlDeviceCore
 */
class LanControlDeviceCore
{
	/** @var  \lib\Application */
	var $app;

	var $deviceCfg = NULL;
	var $requestCfg = NULL;

	var $cmdResultFileName = NULL;
	var $cmdErrorsFileName = NULL;
	var $cmdScriptFileName = NULL;

	var $cmdResults = [];

	public function __construct($app)
	{
		$this->app = $app;
	}

	function initFileNames($commandType)
	{
		$t = strval(time());
		$tmpPath = '/var/lib/shipard-node/tmp/';

		$deviceNdx = ($this->requestCfg && isset($this->requestCfg['device'])) ? $this->requestCfg['device'] : $this->deviceCfg['ndx'];

		$this->cmdResultFileName = $tmpPath.'lcr-'.$commandType.'-res-'.$deviceNdx.'-'.$t.'.txt';
		$this->cmdErrorsFileName = $tmpPath.'lcr-'.$commandType.'-err-'.$deviceNdx.'-'.$t.'.txt';
		$this->cmdScriptFileName = $tmpPath.'lcr-'.$commandType.'-script-'.$deviceNdx.'-'.$t.'.txt';
	}

	function shellCommand($commandType)
	{
		return '';
	}

	public function setDeviceCfg($deviceCfg)
	{
		$this->deviceCfg = $deviceCfg;
	}

	public function runRequest($requestCfg)
	{
		$this->requestCfg = $requestCfg;

		// -- set state -
		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-set-lan-control-request-state/'.$this->app->serverCfg['serverId'];
		$resultState = ['device' => $this->requestCfg['device'], 'state' => 4, 'result' => 1, 'resultLog' => ''];
		$res = $this->app->apiSend($url, $resultState);

		// -- run
		$this->initFileNames($this->requestCfg['type']);
		if ($this->requestCfg['type'] === 'runScript')
		{
			file_put_contents($this->cmdScriptFileName, $this->requestCfg['script']);
		}

		$shellCmd = $this->shellCommand($this->requestCfg['type']);
		$this->runCommand($this->requestCfg['type'], $shellCmd);

		if ($this->requestCfg['type'] !== 'getRunningConfig')
		{
			$this->initFileNames('getRunningConfig');
			$shellCmd = $this->shellCommand('getRunningConfig');
			$this->runCommand('getRunningConfig', $shellCmd);
		}

		$errors = $this->cmdResults[$this->requestCfg['type']]['errors'];
		$runningConfig = $this->polishRunningConfig($this->cmdResults['getRunningConfig']['data']);

		// -- prepare request state for server
		$requestState = ['device' => $this->requestCfg['device'], 'resultLog' => $errors, 'runningConfig' => $runningConfig];
		if ($errors === '')
		{
			$requestState['state'] = 5; // done
			$requestState['result'] = 1; // success
		}
		else
		{
			$requestState['state'] = 3; // retry
			$requestState['result'] = 2; // error
		}

		//echo $requestState['runningConfig']."\n\n";

		// -- send request state to server
		$res = $this->app->apiSend($url, $requestState);

		return ($requestState['result'] === 1);
	}

	function runCommand($commandType, $shellCmd)
	{
		exec($shellCmd);

		$this->cmdResults[$commandType]['data'] = file_get_contents($this->cmdResultFileName);
		$this->cmdResults[$commandType]['errors'] = file_get_contents($this->cmdErrorsFileName);
	}

	function polishRunningConfig($runningConfig)
	{
		return $runningConfig;
	}

	function getDeviceInfo(&$info)
	{
		$this->initFileNames('getDeviceInfo');
		$shellCmd = $this->shellCommand('getDeviceInfo');
		if ($shellCmd === '')
			return;

		if ($this->app->debug)
			echo '   - CMD: '.$shellCmd."\n";

		$this->runCommand('getDeviceInfo', $shellCmd);

		$errors = $this->cmdResults['getDeviceInfo']['errors'];
		$dataStr = $this->cmdResults['getDeviceInfo']['data'];

		$this->doDeviceInfo($dataStr, $info);
	}

	function doDeviceInfo($dataStr, &$info)
	{
	}

	function parseDoublePointRows($str)
	{
		$data = [];

		$rows = preg_split("/\\r\\n|\\r|\\n/", $str);
		foreach ($rows as $row)
		{
			$parts = explode (':', $row);
			if (count($parts) < 2)
				continue;

			$key = trim(array_shift($parts));
			if ($key === '')
				continue;

			$value = trim(implode(':', $parts));
			$data[$key] = $value;
		}

		return $data;
	}
}


