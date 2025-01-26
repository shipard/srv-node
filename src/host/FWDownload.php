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
		/*
		'nextion' => [
			'title' => 'nextion displays', 'fileMask' => '*.tft',
		]
		*/
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
		$remoteVersion = $this->downloadCfgFile($this->baseUrl.$partId.'/'.$channelId .'/'.'/version.json');
		if ($remoteVersion === NULL)
			return $this->app->err('project version error!');

		if ($this->app->debug)
			echo " " . $remoteVersion['version'] . '; ';

		$localVersion = NULL;
		$localDir = $this->localDir . '/' . $partId . '/' . $channelId . '/';
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

		//echo "this is new version\n";

		$files = $this->downloadCfgFile($this->baseUrl . $partId . '/' . $channelId . '/'.$remoteVersion['version'] . '/_files.json');
		if (!$files)
			return FALSE;

		foreach ($files['files'] as $fileInfo)
		{
			$fileUrl = $this->baseUrl.$partId.'/'.$channelId.'/'. $remoteVersion['version'].'/'.$fileInfo['fileName'];
			$fileDir = $localDir.'/'.'/'.$remoteVersion['version'].'/';
			if ($this->app->debug)
				echo " * " . $fileInfo['fileName'];
			$this->downloadFile($fileUrl, $fileDir, $fileInfo['fileName'], $fileInfo['sha1']);
			if ($this->app->debug)
				echo "\n";
		}

		file_put_contents($fileDir . '_files.json', json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		file_put_contents($fileDir . 'version.json', json_encode($remoteVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		file_put_contents($localDir.'/version.json', json_encode($remoteVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
		//$this->download('nextion');

		// -- shp-3rd-fw
		$this->download3rd();
	}

	function fwListPartLoad ($partId)
	{
		$list = [];

		$partDir = $this->localDir.'/'.$partId;
		$channels = $this->app->loadCfgFile($partDir.'/'.'channels.json');
		if (!$channels)
			return NULL;

		if (intval($this->app->serverCfg['useLocalIBFW'] ?? 0) && $partId === 'ib')
			$channels[] = 'local';

		foreach ($channels as $channelId)
		{
			$versionCfg = $this->app->loadCfgFile($partDir.'/'.$channelId.'/'.'version.json');
			$version = $versionCfg['version'];

			$filesDir = $partDir.'/'.$channelId.'/'.$version.'/';
			$filesCfg = $this->app->loadCfgFile($filesDir.'_files.json');
			foreach ($filesCfg['files'] as $f)
			{
				$fileName = $filesDir.$f['fileName'];
				$baseName = $f['fileName'];
				$fileSize = filesize($fileName);
				$urlFileName = 'fw/'.$partId.'/'.$channelId.'/'.$version.'/'.$baseName;
				if ($partId === 'ib')
				{
					$list[$channelId][$f['fwId']] = [
						'fileSize' => $fileSize,
						'fileUrl' => 'http://'.$this->app->nodeCfg['cfg']['mqttServerIPV4'].'/'.$urlFileName,
					];
				}
			}
		}
		return $list;
	}

	function fwListPart ($partId)
	{
		$partDir = $this->localDir.'/'.$partId;
		$channels = $this->app->loadCfgFile($partDir.'/'.'channels.json');
		if (!$channels)
			return $this->app->err('File `$partDir/channels.json` not exist');

		if (intval($this->app->serverCfg['useLocalIBFW'] ?? 0) && $partId === 'ib')
			$channels[] = 'local';

		//echo "# channels [`$partDir`/channels.json]: ".json_encode($channels)."\n";

		foreach ($channels as $channelId)
		{
			$versionCfg = $this->app->loadCfgFile($partDir.'/'.$channelId.'/'.'version.json');
			$version = $versionCfg['version'];

			echo ' # '.$channelId.': '.$version.'; '.$versionCfg['timestamp']."\n";

			$filesDir = $partDir.'/'.$channelId.'/'.$version.'/';
			$filesCfg = $this->app->loadCfgFile($filesDir.'_files.json');

			//echo $filesDir.$this->parts[$partId]['fileMask']."\n";
			forEach (glob($filesDir.$this->parts[$partId]['fileMask']) as $fileName)
			{
				$baseName = basename($fileName);
				$fileSize = filesize($fileName);
				$urlFileName = 'fw/'.$partId.'/'.$channelId.'/'.$version.'/'.$baseName;
				echo '       '.sprintf('% 8d', $fileSize).' '.$urlFileName."\n";
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

	public function fwUpgradeIotBoxes()
	{
		if (!is_readable('/etc/shipard-node/iot-boxes.json'))
		{
			return;
		}

		$channelId = $this->app->serverCfg['ibfwChannel'] ?? 'stable';

		$fwList = $this->fwListPartLoad ('ib');

		//print_r($fwList);
		$iotBoxes = $this->app->loadCfgFile('/etc/shipard-node/iot-boxes.json');
		foreach ($iotBoxes as $iotBoxNdx => $iotBoxCfg)
		{
			$iotBoxId = $iotBoxCfg['cfg']['deviceId'];
			$fwId = $iotBoxCfg['cfg']['fwId'] ?? NULL;
			//echo "FWID: $fwId \n";
			if (!$fwId)
			{

				continue;
			}

			if (!isset($fwList[$channelId][$fwId]))
			{

				continue;
			}

			$fwItem = $fwList[$channelId][$fwId];

			$cmd = 'mosquitto_pub -t shp/iot-boxes/'.$iotBoxId.'/cmd:fwUpgrade -m "'.$fwItem['fileSize'].' '.$fwItem['fileUrl'].'"';
			echo $cmd."\n";
		}
	}

	public function download3rd($debug = 0)
	{
		$urlBegin = 'https://download.shipard.org/shp-3rd-fw/';
		$newFileListStr = @file_get_contents($urlBegin.'files.json');
		if (!$newFileListStr)
		{
			$this->app->err('ERROR: download `shp-3rd-fw` files failed.');
			return;
		}
		$newFileList = json_decode($newFileListStr, TRUE);
		if (!$newFileList || !count($newFileList))
		{
			$this->app->err('ERROR: `shp-3rd-fw` files list is invalid.');
			return;
		}

		$destDir = '/var/lib/shipard-node/fw/shp-3rd-fw/';
		if (!is_dir($destDir))
			mkdir($destDir, 0755, TRUE);

		$currentFileList = [];
		$currentFileListStr = @file_get_contents($destDir.'files.json');
		if ($currentFileListStr !== FALSE)
		{
			$currentFileList = json_decode($currentFileListStr, TRUE);
			if (!$currentFileList || !is_array($currentFileList) || !count($currentFileList))
				$currentFileList = [];

			$checkSumCurrent = hash('SHA256', $currentFileListStr);
			$checkSumNew = hash('SHA256', $newFileListStr);
			if ($checkSumCurrent === $checkSumNew)
			{
				if ($debug)
					echo "FW `shp-3rd-fw` is up to date\n";
				return;
			}
		}

		$tftpHomeDir = $this->app->tftpHomeDir();

    foreach ($newFileList['fw'] as $folderId => $folderInfo)
    {
			if ($debug)
      	echo "### ".$folderId." ###\n";

      foreach ($folderInfo as $subFolderId => $subFolderInfo)
      {
				if ($debug)
        	echo " * ".$subFolderId." / ".$subFolderInfo['version']."\n";
        if (isset($subFolderInfo['files']))
        {
          foreach ($subFolderInfo['files'] as $fileInfo)
          {
            $baseFileName = $fileInfo['fn'];

						$currentFileCheckSum = '';
						$fileFolder = $destDir.$folderId.'/'.$subFolderId.'/';
						$fullFileName = $fileFolder.$baseFileName;
						if (is_readable($fullFileName))
							$currentFileCheckSum = hash_file('SHA256', $fullFileName);

						if ($debug)
							echo "   - ".sprintf("%-35s ", $baseFileName);

						$doDownload = 1;
						if ($currentFileCheckSum === $fileInfo['sha256'])
						{
							if ($debug)
								echo ('UP TO DATE');
							$doDownload = 1;
						}

						if ($doDownload)
						{
							$fileUrl = $urlBegin.'fw/'.$folderId.'/'.$subFolderId.'/'.$baseFileName;
							$fileData = file_get_contents($fileUrl);
							if (!$fileData)
							{
								if ($debug)
									echo (" !!! DOWNLOAD FAILED!!!\n");
								continue;
							}

							if (!is_dir($fileFolder))
								mkdir($fileFolder, 0755, TRUE);

							file_put_contents($fullFileName, $fileData);

							$newFileCheckSum = hash_file('SHA256', $fullFileName);
							if ($newFileCheckSum !== $fileInfo['sha256'])
							{
								if ($debug)
									echo (" !!! INVALID CHECKSUM!!!\n");
								unlink($fullFileName);
								continue;
							}
						}

						if ($tftpHomeDir)
						{
							if (!is_readable($tftpHomeDir.'/'.$baseFileName))
							{
								copy($fullFileName, $tftpHomeDir.'/'.$baseFileName);
							}
						}

						if ($debug)
							echo (" OK - ".$newFileCheckSum."\n");
          }
        }
      }
    }

		file_put_contents($destDir.'files.json', $newFileListStr);
	}
}
