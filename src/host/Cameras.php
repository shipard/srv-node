<?php


namespace Shipard\host;


/**
 * Class Cameras
 */
class Cameras extends \Shipard\host\Core
{
	function vehicleDetectEnabled()
	{
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
		{
			if (isset($cam['cfg']['enableVehicleDetect']) && $cam['cfg']['enableVehicleDetect'])
				return 1;
		}

		return 0;
	}

	public function resetScripts ()
	{
		$this->checkDirs();

		array_map ('unlink', glob ('/etc/shipard-node/cameras/scripts/*'));

		$this->createScriptsDaemons();
		$this->createScriptsStartStop();
		$this->checkServices();
	}

	public function checkServices()
	{
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
		{
			$camType = $cam['type'] ?? 0;
			$this->checkHostService ('shn-cam-picture-'.$cam['ndx'], '/etc/shipard-node/scripts/cameras');
			if ($camType === 0)
				$this->checkHostService ('shn-cam-video-'.$cam['ndx'], '/etc/shipard-node/scripts/cameras');
		}

		if ($this->vehicleDetectEnabled())
			$this->checkHostService ('shn-cam-vd', '/etc/shipard-node/scripts/cameras');
	}

	public function createScriptsDaemons()
	{
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
		{
			$this->createScriptsForOneCamera($cam);
		}

		if ($this->vehicleDetectEnabled())
			$this->createScriptsForCameraVD();
	}

	function createScriptsForOneCamera($cam)
	{
		$camType = $cam['type'] ?? 0;

		if ($camType == 0)
		{
			$captureDir = $this->app->videoDir.$cam['ndx'];
			if (!is_dir($captureDir))
				mkdir($captureDir, 0755, TRUE);

			$captureVideoCmd = '';

			$captureVideoCmd .= 'cd ' . $captureDir . "\n";
			$captureVideoCmd .= 'ffmpeg -rtsp_transport tcp -i "' . $cam['cfg']['streamURL'] . '" -c copy -f segment -segment_time 900 -segment_atclocktime 1 -reset_timestamps 1 -strftime 1 "%Y-%m-%d_%H-%M-%S.mp4" > /dev/null 2>&1 &' . "\n";
			$captureVideoCmd .= "echo $! > /var/run/shn-cam-video-{$cam['ndx']}.pid\n";
			$captureVideoCmd .= "exit 0\n";

			// -- capture
			$cfn = '/etc/shipard-node/scripts/cameras/shn-cam-video-' . $cam['ndx'];

			$captureVideoShellScript = "#!/bin/sh\n\n" . $captureVideoCmd . "\n\n";
			file_put_contents($cfn . '.sh', $captureVideoShellScript);
			chmod($cfn . '.sh', 0755);

		// -- capture systemd
			$captureVideoSystemd =
			"[Unit]
Description=shipard-node video capture cam {$cam['ndx']} - ver. 0.1
After=network.target auditd.service

[Service]
PIDFile=/var/run/shn-cam-video-{$cam['ndx']}.pid
ExecStart=/etc/shipard-node/scripts/cameras/shn-cam-video-{$cam['ndx']}.sh
Type=forking
Restart=always
RestartSec=30
StartLimitIntervalSec=300
StartLimitBurst=3

[Install]
WantedBy=multi-user.target

			";
			file_put_contents($cfn . '.service', $captureVideoSystemd);
		}

		// -- pictures
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cam-picture-' . $cam['ndx'];

		$watchdogVideoCmd = '';
		$watchdogVideoCmd .= "#!/bin/sh\n\n";
		$watchdogVideoCmd .= "/usr/lib/shipard-node/tools/shn-cam-picture.php {$cam['ndx']} &\n";
		$watchdogVideoCmd .= "echo $! > /var/run/shn-cam-picture-{$cam['ndx']}.pid\n";
		$watchdogVideoCmd .= "exit 0\n";
		file_put_contents($cfn . '.sh', $watchdogVideoCmd);
		chmod($cfn . '.sh', 0755);

		$watchdogVideoSystemd =
			"[Unit]
Description=shipard-node camera picture {$cam['ndx']} - ver. 0.2
After=network.target auditd.service

[Service]
PIDFile=/var/run/shn-cam-picture-{$cam['ndx']}.pid
ExecStart=/etc/shipard-node/scripts/cameras/shn-cam-picture-{$cam['ndx']}.sh
Type=forking
Restart=on-failure

[Install]
WantedBy=multi-user.target

			";
		file_put_contents($cfn . '.service', $watchdogVideoSystemd);
	}

	function createScriptsForCameraVD()
	{
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cam-vd';

		$watchdogVideoCmd = '';
		$watchdogVideoCmd .= "#!/bin/sh\n\n";
		$watchdogVideoCmd .= "/usr/lib/shipard-node/tools/shn-cam-vd.php &\n";
		$watchdogVideoCmd .= "echo $! > /var/run/shn-cam-vd.pid\n";
		$watchdogVideoCmd .= "exit 0\n";
		file_put_contents($cfn . '.sh', $watchdogVideoCmd);
		chmod($cfn . '.sh', 0755);

		$watchdogVideoSystemd =
			"[Unit]
Description=shipard-node camera vehicle detect - ver. 0.2
After=network.target auditd.service

[Service]
PIDFile=/var/run/shn-cam-vd.pid
ExecStart=/etc/shipard-node/scripts/cameras/shn-cam-vd.sh
Type=forking
Restart=on-failure

[Install]
WantedBy=multi-user.target

			";
		file_put_contents($cfn . '.service', $watchdogVideoSystemd);
	}

	public function createScriptsStartStop()
	{
		$script = "#!/bin/sh\n\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl start shn-cam-picture-{$cam['ndx']}\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl start shn-cam-video-{$cam['ndx']}\n";
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cams-all-start.sh';
		file_put_contents($cfn, $script);
		chmod($cfn, 0755);

		$script = "#!/bin/sh\n\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl stop shn-cam-picture-{$cam['ndx']}\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl stop shn-cam-video-{$cam['ndx']}\n";
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cams-all-stop.sh';
		file_put_contents($cfn, $script);
		chmod($cfn, 0755);

		$script = "#!/bin/sh\n\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl restart shn-cam-video-{$cam['ndx']}\n";
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cams-video-restart.sh';
		file_put_contents($cfn, $script);
		chmod($cfn, 0755);

		$script = "#!/bin/sh\n\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl start shn-cam-picture-{$cam['ndx']}\n";
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cams-picture-start.sh';
		file_put_contents($cfn, $script);
		chmod($cfn, 0755);

		$script = "#!/bin/sh\n\n";
		foreach ($this->app->nodeCfg['cfg']['cameras'] as $cam)
			$script .= "systemctl stop shn-cam-video-{$cam['ndx']}\n";
		$cfn = '/etc/shipard-node/scripts/cameras/shn-cams-video-stop.sh';
		file_put_contents($cfn, $script);
		chmod($cfn, 0755);
	}
}

