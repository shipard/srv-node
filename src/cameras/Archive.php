<?php


namespace Shipard\cameras;


/**
 * Class Archive
 * @package lib\cameras
 */
class Archive
{
	/** @var  \lib\Application */
	var $app;
	var $content = [];
	var $toDelete = [];

	var $logFileName;

	public function __construct ($app)
	{
		$this->app = $app;
		$today = new \DateTime();
		$this->logFileName = '/var/lib/shipard-node/tmp/cams-log-'.$today->format('Y-m-d');
	}

	function log($msg)
	{
		$now = new \DateTime();
		$data = $now->format ('Y-m-d_H:i:s').' '.$msg."\n";
		file_put_contents($this->logFileName, $data, FILE_APPEND);
	}

	public function dirIsEmpty ($dir)
	{
		$filesInDirectory = scandir($dir);
		$filesCount = count($filesInDirectory);
		if ($filesCount <= 2)
			return TRUE;
		return FALSE;
	}

	public function scanVideo ()
	{
		$this->log ('begin scan video');
		$this->content['video']['firstHour'] = ['date' => '9999-99-99', 'hour' => 99];
		$this->content['video']['lastHour'] = ['date' => '0000-00-00', 'hour' => -1];
		$this->content['video']['stats'] = ['filesCnt' => 0, 'filesSize' => 0, 'hours' => 0];
		$this->content['video']['days'] = [];

		$folder = $this->app->camsDir.'/archive/video';
		forEach (glob($folder . '/*', GLOB_ONLYDIR) as $dayDir)
		{
			$this->log ('day_dir: '.$dayDir);
			$dayFilesCount = 0;
			forEach (glob($dayDir . '/*', GLOB_ONLYDIR) as $hourDir)
			{
				//$this->log ('hour_dir: '.$dayDir);
				$hourFilesCount = 0;
				forEach (glob($hourDir . '/*', GLOB_ONLYDIR) as $camDir)
				{
					//$this->log ('cam_dir: '.$camDir);
					$camFilesCount = 0;
					forEach (glob($camDir . '/*.mp4') as $videoFile)
					{
						$dirParts = explode('/', $videoFile);
						$dirCP = count($dirParts);
						$vfn = substr($dirParts[$dirCP - 1], 0, -4);

						$idCam = $dirParts[$dirCP - 2];
						$idHour = intval($dirParts[$dirCP - 3]);
						$idDate = $dirParts[$dirCP - 4];

						$thumbFileName = substr($videoFile, 0, -4) . '.jpg';
						if (!is_file($thumbFileName))
						{
							$cmd = "ffmpeg -ss 00:00:00.500 -i {$videoFile} -vframes 1 $thumbFileName >/dev/null 2>/dev/null";
							$this->log ('createThumbnail: '.$videoFile);
							passthru($cmd);
						}

						$this->content['video']['archive'][$idDate][$idHour][$idCam][] = $vfn;

						if (!isset($this->content['video']['days'][$idDate][$idHour]))
							$this->content['video']['days'][$idDate][$idHour] = 1;
						else
							$this->content['video']['days'][$idDate][$idHour]++;

						if (
							$idDate < $this->content['video']['firstHour']['date'] ||
							($this->content['video']['firstHour']['date'] === $idDate && $idHour < $this->content['video']['firstHour']['hour'])
						) {
							$this->content['video']['firstHour']['date'] = $idDate;
							$this->content['video']['firstHour']['hour'] = $idHour;
						}

						if (
							$idDate > $this->content['video']['lastHour']['date'] ||
							($this->content['video']['lastHour']['date'] === $idDate && $idHour > $this->content['video']['lastHour']['hour'])
						) {
							$this->content['video']['lastHour']['date'] = $idDate;
							$this->content['video']['lastHour']['hour'] = $idHour;
						}

						$vfs = filesize($videoFile);
						$this->content['video']['stats']['filesCnt']++;
						$this->content['video']['stats']['filesSize'] += $vfs;

						if (!isset($this->content['video']['stats']['cams'][$idCam]))
						{
							$this->content['video']['stats']['cams'][$idCam]['filesSize'] = 0;
							$this->content['video']['stats']['cams'][$idCam]['filesCnt'] = 0;
							$camId = $this->app->nodeCfg['cfg']['cameras'][$idCam]['id'] ?? 'cam-'.$idCam;
							$this->content['video']['stats']['cams'][$idCam]['camId'] = $camId;
						}
						$this->content['video']['stats']['cams'][$idCam]['filesSize'] += $vfs;
						$this->content['video']['stats']['cams'][$idCam]['filesCnt']++;

						if (!isset($this->content['video']['stats']['cams-all'][$idCam][$idDate][$idHour]))
							$this->content['video']['stats']['cams-all'][$idCam][$idDate][$idHour] = ['filesSize' => 0, 'filesCnt' => 0];
						$this->content['video']['stats']['cams-all'][$idCam][$idDate][$idHour]['filesSize'] += $vfs;
						$this->content['video']['stats']['cams-all'][$idCam][$idDate][$idHour]['filesCnt']++;

						$dayFilesCount++;
						$hourFilesCount++;
						$camFilesCount++;
					}
					if (!$camFilesCount)
						rmdir($camDir);
				}
				if (!$hourFilesCount)
					rmdir($hourDir);
			}
			if (!$dayFilesCount)
				rmdir($dayDir); // delete blank folder
		}
		$this->log ('make video stats');
		$this->makeStats();
		$this->log ('end scan video');
	}

	function makeStats()
	{
		foreach ($this->content['video']['days'] as $dayId => $dayContent)
		{
			$this->content['video']['stats']['hours'] += count($dayContent);
		}
	}

	public function removeFirstHourVideo ()
	{
		$this->log ("   removeFirstHourVideo BEGIN");
		$this->scanVideo();
		$this->log ("   removeFirstHourVideo - firstHour=".json_encode($this->content['video']['firstHour']));
		if (!isset ($this->content['video']['firstHour']))
		{
			$this->log ("   removeFirstHourVideo END [#1 - no firstHour date]");
			return;
		}
		if (!isset ($this->content['video']['firstHour']['date']) || $this->content['video']['firstHour']['date'] === '9999-99-99')
		{
			$this->log ("   removeFirstHourVideo END [#2 - invalid firstHour date]");
			return;
		}
		$firstHourDate = $this->content['video']['firstHour']['date'];
		$firstHourDir = $this->app->camsDir.'/archive/video/'.$firstHourDate.'/'.sprintf('%02d', $this->content['video']['firstHour']['hour']);

		$cmd = 'rm -rf '.$firstHourDir;
		exec ($cmd);
		$this->log ("   EXEC: `$cmd`");

		$this->createArchiveVideo ();

		$this->log ("   removeFirstHourVideo END");
	}

	public function createArchiveVideo ()
	{
		$this->content = ['video' => []];

		$this->scanVideo();
		$this->saveArchiveVideo();
	}

	public function saveArchiveVideo ()
	{
		$this->log ('saveArchiveVideo');
		$dataString = json_encode($this->content['video'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		file_put_contents($this->app->camsDir.'/archive/video/archive.json', $dataString);

		// last hour stats
		$lastHourDateId = $this->content['video']['lastHour']['date'];
		$lastHourHourId = $this->content['video']['lastHour']['hour'];

		$this->content['video']['stats']['cams-hour'] = [];
		$this->content['video']['stats']['cams-last-hour-total']['filesSize'] = 0;
		$this->content['video']['stats']['cams-last-hour-total']['filesCnt'] = 0;

		foreach ($this->content['video']['stats']['cams-all'] as $idCam => $camStats)
		{
			if (isset($camStats[$lastHourDateId][$lastHourHourId]))
			{
				$this->content['video']['stats']['cams-hour'][$idCam] = $camStats[$lastHourDateId][$lastHourHourId];
				$this->content['video']['stats']['cams-last-hour-total']['filesSize'] += $camStats[$lastHourDateId][$lastHourHourId]['filesSize'];
				$this->content['video']['stats']['cams-last-hour-total']['filesCnt'] += $camStats[$lastHourDateId][$lastHourHourId]['filesCnt'];
			}
		}

		unset($this->content['video']['stats']['cams-all']);

		// archive stats
		$statsFileName = $this->app->camsDir.'/archive/video/archive-stats.json';
		$currentStatsString = '---';
		if (is_readable($statsFileName))
			$currentStatsString = file_get_contents($statsFileName);

		$statsString = json_encode($this->content['video']['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		file_put_contents($statsFileName, $statsString);

		//if (sha1($currentStatsString) !== sha1($statsString))
		{
			// -- send to netdata/statd
			$netDataStrValue = '';
			$fsgb = round($this->content['video']['stats']['filesSize'] / 1073741824, 3);
			$netDataStrValue .= 'cameras.archive.diskUsage: '.$fsgb."|g|#units=GB,name=diskUsage\n";
			$netDataStrValue .= 'cameras.archive.filesCount: '.$this->content['video']['stats']['filesCnt']."|g|#name=filesCount\n";
			$netDataStrValue .= 'cameras.archive.len: '.$this->content['video']['stats']['hours']."|g|#units=h,name=archiveLen\n";
			$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			socket_sendto($sock, $netDataStrValue, strlen($netDataStrValue), 0, '127.0.0.1', 8125);
			socket_close($sock);

			// -- send to mqtt
			$topic = 'shp/sensors/va/video-archive-files-size';
			$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['filesSize']));
			$topic = 'shp/sensors/va/video-archive-files-count';
			$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['filesCnt']));
			$topic = 'shp/sensors/va/video-archive-len-hours';
			$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['hours']));

			$netDataStrValue = '';
			foreach ($this->content['video']['stats']['cams'] as $camNdx => $cam)
			{
				$topic = 'shp/sensors/va/cams/'.$cam['camId'].'/files-size';
				$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['cams'][$camNdx]['filesSize']));

				$topic = 'shp/sensors/va/cams/'.$cam['camId'].'/hourly-files-size';

				$camHourFilesSize = $this->content['video']['stats']['cams-hour'][$camNdx]['filesSize'] ?? 0;
				$camHourFilesCnt = $this->content['video']['stats']['cams-hour'][$camNdx]['filesCnt'] ?? 0;

				$this->app->sendMqttMessage($topic, strval($camHourFilesSize));

				$fsgb = round($this->content['video']['stats']['cams'][$camNdx]['filesSize'] / 1073741824, 3);
				$netDataStrValue .= 'cameras.diskUsage.'.$camNdx.': '.$fsgb.'|g|#units=GB,name='.$cam['camId'].",family=cameras.diskUsage\n";

				if (!$camHourFilesSize || !$camHourFilesCnt)
				{
					$alert = [
						'alertType' => 'mac-lan',
						'alertKind' => 'mac-lan-cam-video-error',
						'alertSubject' => 'Camera #'.$camNdx.' / '.$cam['camId'].' video has errors',
						'alertId' => 'mac-lan-cam-video-' . $camNdx,
						'payload' => $this->content['video']['stats']['cams-hour'][$camNdx] ?? ['error' => 'stats for camera #'.$camNdx.' not found'],
					];
					$this->app->sendAlert($alert);
				}
			}

			// -- send to netdata/statd
			$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			socket_sendto($sock, $netDataStrValue, strlen($netDataStrValue), 0, '127.0.0.1', 8125);
			socket_close($sock);
		}
	}

	public function scan ()
	{
		$this->createArchiveVideo();
	}
}
