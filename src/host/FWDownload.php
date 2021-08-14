<?php


namespace Shipard\host;


/**
 * Class FWDownload
 */
class FWDownload extends \Shipard\host\Core
{
	var $baseUrl = 'https://download.shipard.org/shipard-iot/fw/';

	var $parts = [
		'ib' => [
			'title' => 'iot-boxes', 'fileMask' => '*.bin',
		],
		'nextion' => [
			'title' => 'nextion displays', 'fileMask' => '*.tft',
		]
	];

	var $channelsList = NULL;
	var $localDir = '/var/www/iot-boxes/fw';

	var $downloadError = FALSE;

	function download ($partId)
	{
		if (!$this->downloadChannelsList($partId))
			return FALSE;
		if (!$this->downloadChannels($partId))
			return FALSE;

		// -- set owner
		passthru ('chown -R '.$this->app->wwwUser().':'.$this->app->wwwGroup().' '.$this->localDir.'/'.$partId);

		return TRUE;
	}

	function downloadChannelsList($partId)
	{
		$this->channelsList = $this->downloadCfgFile($this->baseUrl.$partId.'/channels.json');
		if ($this->channelsList === NULL)
			return $this->app->err("download channels for part `$partId` failed!");

		return TRUE;
	}

	function downloadChannels($partId)
	{
		foreach ($this->channelsList as $channelId)
		{
			if ($this->app->debug)
				echo $partId.'/'.$channelId;

			if (!$this->downloadChannel($partId, $channelId))
				return FALSE;

			if ($this->app->debug)
				echo "\n";
		}

		file_put_contents($this->localDir . '/' . $partId . '/channels.json', json_encode($this->channelsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		return TRUE;
	}

	function downloadChannel($partId, $channelId)
	{
		$projects = $this->downloadCfgFile($this->baseUrl.$partId.'/'.$channelId.'/projects.json');
		if (!$projects)
		{
			echo "download projects failed!\n";
			return FALSE;
		}

		foreach ($projects as $projectId => $projectInfo)
		{
			if ($this->app->debug)
				echo "### $projectId \n";

			$remoteVersion = $this->downloadCfgFile($this->baseUrl.$partId.'/'.$channelId .'/'.$projectId.'/version.json');
			if ($remoteVersion === NULL)
				return $this->app->err('project version error!');

			if ($this->app->debug)
				echo " " . $remoteVersion['version'] . '; ';

			$localVersion = NULL;
			$localDir = $this->localDir . '/' . $partId . '/' . $channelId . '/'.$projectId.'/';
			if (is_dir($localDir) && is_readable($localDir . 'version.json'))
			{
				$localVersion = $this->app->loadCfgFile($localDir . 'version.json');
			}

			if ($localVersion && $localVersion['version'] === $remoteVersion['version'])
			{
				if ($this->app->debug)
					echo "version exist\n";
				return TRUE;
			}

			echo "this is new version\n";

			$files = $this->downloadCfgFile($this->baseUrl . $partId . '/' . $channelId . '/' . $projectId.'/'.$remoteVersion['version'] . '/files.json');
			if (!$files)
				return FALSE;

			foreach ($files['files'] as $fileInfo)
			{
				$fileUrl = $this->baseUrl.$partId.'/'.$channelId.'/'.$projectId.'/'. $remoteVersion['version'].'/'.$fileInfo['fileName'];
				$fileDir = $localDir.'/'.'/'.$remoteVersion['version'].'/';
				if ($this->app->debug)
					echo " * " . $fileInfo['fileName'];
				$this->downloadFile($fileUrl, $fileDir, $fileInfo['fileName'], $fileInfo['sha1']);
				if ($this->app->debug)
					echo "\n";
			}

			file_put_contents($fileDir . 'files.json', json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			file_put_contents($fileDir . 'version.json', json_encode($remoteVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			file_put_contents($localDir.'/version.json', json_encode($remoteVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		file_put_contents($this->localDir . '/' . $partId . '/' . $channelId.'/projects.json', json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		return TRUE;
	}

	function downloadFile($url, $destDir, $destBaseFileName, $checkSum)
	{
		if (!is_dir($destDir))
			mkdir($destDir, 0766, TRUE);

		if (!copy($url, $destDir.$destBaseFileName))
		{
			$this->downloadError = TRUE;
			return FALSE;
		}

		$fileCheckSum = sha1_file($destDir.$destBaseFileName);
		if ($fileCheckSum !== $checkSum)
		{
			echo " !!! CHECKSUM ERROR !!!";
			return FALSE;
		}

		if ($this->app->debug)
			echo " OK";

		return TRUE;
	}

	function downloadCfgFile($url)
	{
		$dataStr = file_get_contents($url);
		if (!$dataStr)
			return NULL;

		$data = json_decode($dataStr, TRUE);
		if (!$data)
			return NULL;

		return $data;
	}

	function run()
	{
		if (is_readable('/var/www/iot-boxes/fw/ib/stable/version.json'))
		{ // old style - remove
			exec ('rm -rf '.'/var/www/iot-boxes/fw/ib');
		}

		// -- iot-boxes
		$this->download('ib');

		// -- nextion displays
		$this->download('nextion');
	}

	function fwListPart ($partId)
	{
		$partDir = $this->localDir.'/'.$partId;
		$channels = $this->app->loadCfgFile($partDir.'/'.'channels.json');
		if (!$channels)
			return $this->app->err('File `$partDir/channels.json` not exist');

		echo '### '.$partId." ###\n";

		foreach ($channels as $channelId)
		{
			echo ' * '.$channelId."\n";

			$projects = $this->app->loadCfgFile($partDir.'/'.$channelId.'/projects.json');
			foreach ($projects as $projectId => $projectCfg)
			{
				echo '   - '.$projectId.'; '.$projectCfg['version']."\n";

				$filesDir = $partDir.'/'.$channelId.'/'.$projectId.'/'.$projectCfg['version'].'/';
				//echo $filesDir.$this->parts[$partId]['fileMask']."\n";
				forEach (glob($filesDir.$this->parts[$partId]['fileMask']) as $fileName)
				{
					$baseName = basename($fileName);
					$fileSize = filesize($fileName);
					$urlFileName = 'fw/'.$partId.'/'.$channelId.'/'.$projectId.'/'.$projectCfg['version'].'/'.$baseName;
					echo '       '.sprintf('% 8d', $fileSize).' '.$urlFileName."\n";
				}
			}
		}
		return TRUE;
	}

	public function fwListAll()
	{
		foreach ($this->parts as $partId => $partCfg)
		{
			$this->fwListPart($partId);
		}
	}
}
