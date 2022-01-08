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
			return;
		if (!isset ($this->content['video']['firstHour']['date']) || $this->content['video']['firstHour']['date'] === '9999-99-99')
			return;

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

		// archive stats
		$statsFileName = $this->app->camsDir.'/archive/video/archive-stats.json';
		$currentStatsString = '---';
		if (is_readable($statsFileName))
			$currentStatsString = file_get_contents($statsFileName);

		$statsString = json_encode($this->content['video']['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		file_put_contents($statsFileName, $statsString);

		//if (sha1($currentStatsString) !== sha1($statsString))
		{
			// -- send statsd info
			$statsdData = '';
			$statsdData .= 'shn_video.filescount:'.$this->content['video']['stats']['filesCnt'].'|g'."\n";
			$statsdData .= 'shn_video.filessize:'.intval($this->content['video']['stats']['filesSize'] / 1073741824).'|g'."\n";
			$statsdData .= 'shn_video.archivedhours:'.$this->content['video']['stats']['hours'].'|g'."\n";

			$topic = 'shp/sensors/va/video-archive-files-size';
			$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['filesSize']));
			$topic = 'shp/sensors/va/video-archive-files-count';
			$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['filesCnt']));
			$topic = 'shp/sensors/va/video-archive-len-hours';
			$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['hours']));

			foreach ($this->content['video']['stats']['cams'] as $camNdx => $cam)
			{
				$topic = 'shp/sensors/va/cams/'.$cam['camId'].'/files-size';
				$this->app->sendMqttMessage($topic, strval($this->content['video']['stats']['cams'][$camNdx]['filesSize']));
			}
		}
	}

	public function scan ()
	{
		$this->createArchiveVideo();
	}
}


