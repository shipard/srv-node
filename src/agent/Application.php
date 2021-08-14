<?php

namespace lib;

/**
 * Class Application
 */
class Application
{
	var $arguments = NULL;
	var $agentCfg = FALSE;
	var $versionInfo = FALSE;

	var $dirBase = '/var/lib/shipard-agent';
	var $dirFiles = '/var/lib/shipard-agent/files';

	var $debug = 0;

	public function __construct ($argv)
	{
		if ($argv)
			$this->arguments = parseArgs($argv);

		$this->agentCfg = $this->loadCfgFile('/etc/shipard-agent/config.json');
		if (!$this->agentCfg)
			$this->err ("File /etc/shipard-agent/config.json not found or has syntax error!");

		$this->nodeCfg = $this->loadCfgFile('/etc/shipard-node/config.json');
		$this->mqttEngineCfg = $this->loadCfgFile('/etc/shipard-node/mqtt-engine.json');

		$this->rootDir = '/var/lib/shipard-agent/';

		if ($this->arg('debug'))
		{
			$this->debug = intval($this->arg('debug'));
			echo "-- debug mode: {$this->debug}\n";
		}

		// -- version info
		$this->versionInfo = $this->loadCfgFile('/usr/lib/shipard-agent/shipard-agent.info');
		if (!$this->versionInfo)
			$this->versionInfo = ['channel' => 'none', 'version' => '0.0', 'fileName' => '', 'checkSum' => ''];
	}

	public function arg ($name)
	{
		if (isset ($this->arguments [$name]))
			return strval($this->arguments [$name]);

		return FALSE;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	public function err ($msg)
	{
		if ($msg === FALSE)
			return TRUE;

		if (is_array($msg))
		{
			if (count($msg) !== 0)
			{
				forEach ($msg as $m)
					echo ("! " . $m['text']."\n");
				return FALSE;
			}
			return TRUE;
		}

		echo ("ERROR: ".$msg."\n");
		return FALSE;
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return FALSE;
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return FALSE;
			return $cfg;
		}
		return FALSE;
	}

	function http_post ($url, $data)
	{
		$data_len = strlen ($data);
		$context = stream_context_create (
			[
				'http'=> [
					'method'=>'POST',
					'header'=>"Content-type: text/plain\r\nConnection: close\r\nContent-Length: $data_len\r\n",
					'content'=>$data,
					'timeout' => 30
				]
			]
		);

		$result = @file_get_contents ($url, FALSE, $context);
		$responseHeaders = $http_response_header;
		return ['content'=> $result, 'headers'=> $responseHeaders];
	}

	public function superuser ()
	{
		return (0 == posix_getuid());
	}

	public function run ()
	{
		echo "nothing to do...\r\n";
	}
}

function parseArgs($argv)
{
	// http://pwfisher.com/nucleus/index.php?itemid=45
	array_shift ($argv);
	$out = array();
	foreach ($argv as $arg){
		if (substr($arg,0,2) == '--'){
			$eqPos = strpos($arg,'=');
			if ($eqPos === false){
				$key = substr($arg,2);
				$out[$key] = isset($out[$key]) ? $out[$key] : true;
			} else {
				$key = substr($arg,2,$eqPos-2);
				$out[$key] = substr($arg,$eqPos+1);
			}
		} else if (substr($arg,0,1) == '-'){
			if (substr($arg,2,1) == '='){
				$key = substr($arg,1,1);
				$out[$key] = substr($arg,3);
			} else {
				$chars = str_split(substr($arg,1));
				foreach ($chars as $char){
					$key = $char;
					$out[$key] = isset($out[$key]) ? $out[$key] : true;
				}
			}
		} else {
			$out[] = $arg;
		}
	}
	return $out;
}

