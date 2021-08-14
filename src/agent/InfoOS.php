<?php

namespace lib;


class InfoOS
{
	/** @var  \lib\Application */
	var $app;

	var $fileName;

	public function __construct($app)
	{
		$this->app = $app;
		$this->init();
	}

	function init()
	{
		if (!is_dir($this->app->dirBase))
			mkdir($this->app->dirBase);
		if (!is_dir($this->app->dirFiles))
			mkdir($this->app->dirFiles);

		$now = new \DateTime();

		$this->fileName = $this->app->dirFiles.'/installedSw-'.$now->format('Ymd\THms').'.txt';

		$introText = ";;;shipard-agent: ".$this->app->versionInfo['version']."\n";
		$introText .= ";;;os: linux\n";
		$introText .= ";;;deviceUid: ".$this->app->agentCfg['deviceUid']."\n";
		$introText .= ";;;date: ".$now->format('Ymd\THms')."\n";
		$introText .= "\n\n";

		file_put_contents($this->fileName, $introText);
	}

	function addFileIntro($textBefore)
	{
		$txt = $textBefore."\n\n\n";
		file_put_contents($this->fileName, $txt, FILE_APPEND);
	}

	function addFileSeparator()
	{
		$txt = "\n\n\n";
		file_put_contents($this->fileName, $txt, FILE_APPEND);
	}

	function infoOS()
	{
		$this->addFileIntro(';;;shipard-agent-system-info');
		$cmd = "hostnamectl >> ".$this->fileName;
		exec($cmd);
		$this->addCFGIniLikeFile('/etc/os-release', 'os_release', 'os-release');
		$this->addCFGIniLikeFile('/etc/armbian-release', 'armbian_release', 'armbian-release');
		$this->addCFGIniLikeFile('/etc/armbian-image-release', 'armbian_image_release', 'armbian-image-release');
		$this->addOneValueFile('/etc/debian_version', 'debian-version');
		$this->addOneValueFile('/etc/timezone', 'os-timezone');
		$this->addFileSeparator();
	}

	function infoSW()
	{
		// apt list --installed|grep -E '^(nginx|php-|nodejs)'
	}

	function addCFGIniLikeFile ($fileName, $keyPrefix, $infoValue)
	{
		if (!is_readable($fileName))
			return;

		$srcData = file_get_contents($fileName);
		$rows = preg_split("/\\r\\n|\\r|\\n/", $srcData);
		$data = '';
		foreach ($rows as $row)
		{
			$parts = explode('=', $row);

			if (count($parts) < 2)
				continue;

			if ($data === '')
				$data .= "info-file-type-".$keyPrefix." : ".$infoValue."\n";

			$key = $keyPrefix.'-'.trim($parts[0]);
			array_shift($parts);
			$value = trim(trim(implode('', $parts)), "'\"");
			$data .= strtolower($key)." : ".$value."\n";
		}

		if ($data !== '')
			file_put_contents($this->fileName, $data, FILE_APPEND);
	}

	function addOneValueFile ($fileName, $key)
	{
		if (!is_readable($fileName))
			return;

		$srcData = file_get_contents($fileName);
		if (!$srcData || $srcData === '')
			return;
		$rows = preg_split("/\\r\\n|\\r|\\n/", $srcData);
		if (!isset($rows[0]) || $rows[0] === '')
			return;
		$data = $key.' : '.trim($rows[0])."\n";
		file_put_contents($this->fileName, $data, FILE_APPEND);
	}

	function send()
	{
		$data = file_get_contents($this->fileName);
		$url = $this->app->agentCfg['dsUrl'].'/upload/mac.lan.lans';
		$result = $this->app->http_post($url, $data);

		file_put_contents($this->fileName.'.result.json', json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

		//echo json_encode($result)."\n\n";
	}

	function run()
	{
		$this->infoOS();
		$this->infoSW();

		$this->send();
	}
}

