<?php


namespace Shipard\host;


/**
 * Class Subsystems
 */
class Subsystems extends \Shipard\host\Core
{
	public function check()
	{
		$needRestart = 0;//$this->checkCerts();

		$this->checkExpect();
		$this->checkFtpDaemon();
		$this->checkFfmpeg();
		$this->checkImageMagick();
		$this->checkNginx();
		$this->checkPhpInotify();
		$this->checkNodeJS();
		$this->checkMqttServer();
		$this->checkMqttEngine();
		$this->checkLanControl();
		$this->checkShipardNodeBoard();

		// -- LC
		if (isset($this->app->nodeCfg['cfg']['enableLC']) && $this->app->nodeCfg['cfg']['enableLC'])
		{
			$this->checkCronSymlink('shn-lc');
		}

		if ($needRestart)
		{
			if ($this->needNginx())
				$this->restartHostService('nginx', 'reload');

			if ($this->needMqttServer())
				$this->restartHostService('mosquitto');
		}
	}

	function checkCerts()
	{
		$needRestart = FALSE;

		// -- certs
		if ($this->copyFileWithCheck ('chain.pem', '/usr/lib/shipard-node/etc/crt', '/etc/ssl/crt/shipard.pro')) $needRestart = TRUE;
		if ($this->copyFileWithCheck ('fullchain.pem', '/usr/lib/shipard-node/etc/crt', '/etc/ssl/crt/shipard.pro')) $needRestart = TRUE;
		if ($this->copyFileWithCheck ('privkey.pem', '/usr/lib/shipard-node/etc/crt', '/etc/ssl/crt/shipard.pro')) $needRestart = TRUE;
		if ($this->copyFileWithCheck ('cert.pem', '/usr/lib/shipard-node/etc/crt', '/etc/ssl/crt/shipard.pro')) $needRestart = TRUE;

		return $needRestart;
	}

	function checkExpect()
	{
		if (!$this->needLanControl())
			return;

		exec ('expect -v > /var/lib/shipard-node/hostInfo/expect.txt 2>/dev/null');
		$expectVersion = file_get_contents('/var/lib/shipard-node/hostInfo/expect.txt');

		if ($expectVersion == '')
		{
			$installCmd = "apt-get --assume-yes --quiet install expect";
			passthru($installCmd);
		}
	}

	function checkFtpDaemon()
	{
		if (!$this->needFtpDaemon())
			return;

		if (!is_file('/etc/proftpd/proftpd.conf') && !is_file('/etc/vsftpd.conf'))
		{
			$installCmd = "apt-get --assume-yes --quiet install proftpd";
			passthru($installCmd);
		}

		if (is_file('/etc/proftpd/proftpd.conf'))
		{
			$shnCfgFileName = '/etc/proftpd/conf.d/shn-cameras-pictures.conf';
			if (!is_file($shnCfgFileName))
			{
				symlink('/usr/lib/shipard-node/etc/proftpd/shn-cameras-pictures.conf', $shnCfgFileName);
				$cmd = "service proftpd restart";
				shell_exec($cmd);
			}
		}	
	}

	function needFtpDaemon()
	{
		if ($this->needCamerasSupport())
			return TRUE;

		return FALSE;
	}

	function checkFfmpeg()
	{
		if (!$this->needFfmpeg())
			return;

		exec ('ffmpeg -version > /var/lib/shipard-node/hostInfo/ffmpeg.txt 2>/dev/null');
		$ffmpegVersion = file_get_contents('/var/lib/shipard-node/hostInfo/ffmpeg.txt');

		if ($ffmpegVersion == '')
		{
			$installCmd = "apt-get --assume-yes --quiet install ffmpeg";
			passthru($installCmd);
		}
	}

	function needFfmpeg ()
	{
		if ($this->needCamerasSupport())
			return TRUE;

		return FALSE;
	}

	function checkImageMagick()
	{
		if (!$this->needImageMagick())
			return;

		exec ('convert -version > /var/lib/shipard-node/hostInfo/convert.txt 2>/dev/null');
		$installedVersion = file_get_contents('/var/lib/shipard-node/hostInfo/convert.txt');

		if ($installedVersion == '')
		{
			$installCmd = "apt-get --assume-yes --quiet install imagemagick";
			passthru($installCmd);
		}
	}

	function needImageMagick()
	{
		if ($this->needCamerasSupport())
			return TRUE;

		return FALSE;
	}

	function checkNginx()
	{
		if (!$this->needNginx())
			return;

		$this->checkPhpFpm();

		$needRestart = FALSE;

		// -- install
		if (!is_file('/etc/nginx/nginx.conf'))
		{
			$installCmd = "apt-get --assume-yes --quiet install nginx";
			passthru($installCmd);
		}

		// -- /etc/ssl/crt
		if (!is_dir('/etc/ssl/crt'))
			mkdir('/etc/ssl/crt', 0775, TRUE);

		// -- dhparam
		if (!is_file('/etc/ssl/crt/dhparam.pem'))
		{
			$installCmd = 'openssl dhparam -out /etc/ssl/crt/dhparam.pem 4096';
			passthru($installCmd);
			$needRestart = TRUE;
		}

		// -- host cfgFile
		$hostConfigFileName = '/etc/nginx/sites-available/'.'shipard-node.conf';
		$hostConfigEnableFileName = '/etc/nginx/sites-enabled/'.'shipard-node.conf';
		$currentHostConfig = is_file($hostConfigFileName) ? file_get_contents($hostConfigFileName) : '';
		$newHostConfig = $this->nginxHostConfig();

		if (!$currentHostConfig || $currentHostConfig == '' || md5($currentHostConfig) !== md5($newHostConfig))
		{
			file_put_contents($hostConfigFileName, $newHostConfig);
			if (!is_file($hostConfigEnableFileName))
				symlink($hostConfigFileName, $hostConfigEnableFileName);

			$needRestart = TRUE;
		}

		// -- default nginx host cfg
		if (is_readable('/etc/nginx/sites-enabled/default'))
		{
			unlink('/etc/nginx/sites-enabled/default');
			$needRestart = TRUE;
		}

		// -- restart service
		if ($needRestart)
		{
			$this->restartHostService('nginx', 'reload');
		}

		if ($this->needCamerasSupport())
		{
			if (!is_dir('/var/www/shipard-node/cameras'))
				mkdir('/var/www/shipard-node/cameras', 0755, TRUE);

			if (!is_readable('/var/www/shipard-node/cameras/pictures'))
				symlink($this->app->picturesDir, '/var/www/shipard-node/cameras/pictures');
			if (!is_readable('/var/www/shipard-node/cameras/video-archive'))
				symlink($this->app->camsDir.'archive/video', '/var/www/shipard-node/cameras/video-archive');

			if (!is_readable('/var/www/shipard-node/imgcache'))
				symlink('/var/lib/shipard-node/imgcache', '/var/www/shipard-node/imgcache');
		}
	}

	function needNginx()
	{
		if ((isset($this->app->nodeCfg['cfg']['enableLC']) && $this->app->nodeCfg['cfg']['enableLC']) ||
				(isset($this->app->nodeCfg['cfg']['enableCams']) && $this->app->nodeCfg['cfg']['enableCams']))
			return TRUE;

		return FALSE;
	}

	function checkNodeJS()
	{
		if (!$this->needNodeJS())
			return;

		exec ('node -v > /var/lib/shipard-node/hostInfo/nodejs.txt 2>/dev/null');
		$nodeJSVersion = file_get_contents('/var/lib/shipard-node/hostInfo/nodejs.txt');

		if ($nodeJSVersion == '')
		{
			$installCmd = "apt-get --assume-yes --quiet install nodejs npm";
			passthru($installCmd);
		}

		if (!is_dir('/var/lib/shipard-node/npm/node_modules'))
			mkdir('/var/lib/shipard-node/npm/node_modules', 0755, TRUE);

		if (isset($this->app->nodeCfg['cfg']['enableLC']) && $this->app->nodeCfg['cfg']['enableLC'])
		{
			if (!is_readable('/usr/lib/shipard-node/mqtt/node_modules'))
				symlink('/var/lib/shipard-node/npm/node_modules', '/usr/lib/shipard-node/mqtt/node_modules');

			if (!is_dir('/var/lib/shipard-node/npm/node_modules/.bin'))
			{
				$cwd = getcwd();
				chdir('/usr/lib/shipard-node/mqtt/');

				exec('npm install');
				exec('npm install');

				chdir($cwd);
			}
		}

		if (isset($this->app->nodeCfg['cfg']['enableCams']) && $this->app->nodeCfg['cfg']['enableCams'])
		{
			if (!is_readable('/usr/lib/shipard-node/onvif/node_modules'))
				symlink('/var/lib/shipard-node/npm/node_modules', '/usr/lib/shipard-node/onvif/node_modules');

			if (!is_dir('/var/lib/shipard-node/npm/node_modules/.bin'))
			{
				$cwd = getcwd();
				chdir('/usr/lib/shipard-node/onvif/');

				exec('npm install');
				exec('npm install');

				chdir($cwd);
			}
		}
	}

	function needNodeJS()
	{
		if ((isset($this->app->nodeCfg['cfg']['enableLC']) && $this->app->nodeCfg['cfg']['enableLC']) ||
				(isset($this->app->nodeCfg['cfg']['enableCams']) && $this->app->nodeCfg['cfg']['enableCams']))
			return TRUE;

		return FALSE;
	}

	function checkMqttServer()
	{
		if (!is_readable('/usr/bin/mosquitto_sub'))
		{ // TODO: remove in next version
			$installCmd = "apt-get --assume-yes --quiet install mosquitto-clients";
			passthru($installCmd);
		}

		if (!$this->needMqttServer())
			return;

		if (!is_file('/etc/mosquitto/mosquitto.conf'))
		{
			$installCmd = "apt-get --assume-yes --quiet install mosquitto mosquitto-clients";
			passthru($installCmd);
		}
	}

	function needMqttServer()
	{
		if (isset($this->app->nodeCfg['cfg']['enableLC']) && $this->app->nodeCfg['cfg']['enableLC'])
			return TRUE;

		return FALSE;
	}

	function checkMqttEngine()
	{
		// -- service
		if ($this->needMqttServer())
		{
			$this->checkHostService('shn-mqtt-engine', '/usr/lib/shipard-node/etc/systemd');
			$this->checkHostService('shn-mqtt-log', '/usr/lib/shipard-node/etc/systemd');

			$this->checkCronSymlink('shn-mqtt');
		}
	}

	function nginxHostConfig()
	{
		$c = '';
		$c .= "# primary shipard-node server; config; ver 0.5\n";

		if (isset($this->app->nodeCfg['cfg']['httpProxies']))
		{
			foreach ($this->app->nodeCfg['cfg']['httpProxies'] as $hp)
			{
				$c .= "upstream backend-{$hp['id']} {\n";
				$c .= "\tserver {$hp['destIP']}:{$hp['destPort']};\n";
				$c .= "\tkeepalive 64;\n";
				$c .= "}\n\n";
			}
		}

		$serverName = $this->app->nodeCfg['cfg']['fqdn'];
		$httpsPort = (isset($this->app->nodeCfg['cfg']['httpsPort']) && intval($this->app->nodeCfg['cfg']['httpsPort'])) ? $this->app->nodeCfg['cfg']['httpsPort'] : 443;

		$c .= "server {\n";
		$c .= "\tlisten $httpsPort ssl http2;\n";
		$c .= "\tserver_name $serverName;\n";
		$c .= "\troot /var/www/shipard-node;\n";
		$c .= "\tindex index.html index.php;\n";

		$c .= "\tssl_certificate /var/lib/shipard-node/certs/all.shipard.pro/chain.pem;\n";
		$c .= "\tssl_certificate_key /var/lib/shipard-node/certs/all.shipard.pro/privkey.pem;\n";

		$c .= "\tssl_dhparam /etc/ssl/crt/dhparam.pem;\n";

		$c .= "\tssl_stapling on;\n";
		$c .= "\tssl_stapling_verify on;\n";

		$c .= "\tinclude /usr/lib/shipard-node/etc/nginx/shn-host.conf;\n";
		$c .= "\tinclude /usr/lib/shipard-node/etc/nginx/shn-https.conf;\n";

		if (isset($this->app->nodeCfg['cfg']['httpProxies']))
		{
			$c .= "\n";
			$c .= "\t".'location ~ /netdata/(?<behost>.*)/(?<ndpath>.*) {'."\n";
			$c .= "\t\t".'proxy_set_header X-Forwarded-Host $host;'."\n";
      $c .= "\t\t".'proxy_set_header X-Forwarded-Server $host;'."\n";
      $c .= "\t\t".'proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;'."\n";
      $c .= "\t\t".'proxy_http_version 1.1;'."\n";
      $c .= "\t\t".'proxy_pass_request_headers on;'."\n";
      $c .= "\t\t".'proxy_set_header Connection "keep-alive";'."\n";
      $c .= "\t\t".'proxy_store off;'."\n";
      $c .= "\t\t".'proxy_pass http://backend-$behost/$ndpath$is_args$args;'."\n";
			$c .= "\t\t".'gzip on;'."\n";
      $c .= "\t\t".'gzip_proxied any;'."\n";
      $c .= "\t\t".'gzip_types *;'."\n";
    	$c .= "\t".'}'."\n\n";

			$c .= "\t".'location ~ /netdata/(?<behost>.*) {'."\n";
			$c .= "\t\t".'return 301 /netdata/$behost/;'."\n";
	    $c .= "\t".'}'."\n";
		}

		$c .= "}\n\n";

		return $c;
	}

	function checkPhpFpm ()
	{
		if (!is_dir('/var/run/php/'))
		{
			$installCmd = "apt-get --assume-yes --quiet install php8.1-fpm";
			passthru($installCmd);
		}
	}

	function checkPhpInotify ()
	{
		if (!$this->needCamerasSupport())
			return;
		/*
		$phpVer = '';
		if (is_dir('/etc/php/8.0'))
			$phpVer = '8.0';

		$inotifyCfgFileName = '/etc/php/'.$phpVer.'/cli/conf.d/40-shn-inotify.ini';
		if (is_readable($inotifyCfgFileName))
			return;

		$installCmd = "apt-get --assume-yes --quiet install php-dev php-pear";
		passthru($installCmd);

		$installCmd = "pecl install inotify";
		passthru($installCmd);

		$inotifyCfg = '';
		$inotifyCfg .= "; shipard-node inotify 0.1\n";
		$inotifyCfg .= "; priority=50\n";
		$inotifyCfg .= "extension=inotify.so\n";

		file_put_contents($inotifyCfgFileName, $inotifyCfg);
		*/
	}

	function needLanControl()
	{
		if (isset($this->app->nodeCfg['cfg']['enableLC']) && $this->app->nodeCfg['cfg']['enableLC'])
			return TRUE;

		return FALSE;
	}

	function checkLanControl()
	{
		if (!$this->needLanControl())
			return;

		// -- tftp
		$tftpdConfigFileName = '/etc/default/tftpd-hpa';
		if (!is_file($tftpdConfigFileName))
		{
			$installCmd = "apt-get --assume-yes --quiet install tftpd-hpa";
			passthru($installCmd);
		}

		// -- lc dir
		$lcPath = '/var/lib/shipard-node/lc';
		if (!is_dir($lcPath))
			mkdir($lcPath, 0770, TRUE);

		// -- ssh key dir
		$lcSshPath = $lcPath.'/ssh';
		if (!is_dir($lcSshPath))
		{
			mkdir($lcSshPath, 0770, TRUE);
		}

		// ssh key
		$lcSshFN = $lcSshPath.'/shn_ssh_key';
		if (!is_readable($lcSshFN))
		{
			$cmd = 'ssh-keygen -t rsa -f '.$lcSshFN.' -q -N ""';
			passthru($cmd);
		}

		$lcSshFN = $lcSshPath.'/shn_ssh_key_dsa';
		if (!is_readable($lcSshFN))
		{
			$cmd = 'ssh-keygen -b 1024 -t dsa -f '.$lcSshFN.' -q -N ""';
			passthru($cmd);
		}

		$tftpdDir = $this->tftpHomeDir();
		if ($tftpdDir)
		{
			$this->copyFileWithCheck('shn_ssh_key.pub', $lcSshPath, $tftpdDir);
			$this->copyFileWithCheck('shn_ssh_key_dsa.pub', $lcSshPath, $tftpdDir);
		}

		// -- edge-core ssh config
		$this->copyFileWithCheck ('config_switch-edgecore', '/usr/lib/shipard-node/etc/sshDevicesConfigs', $lcSshPath);


		// -- lanControl requests dir
		if (!is_dir('/var/lib/shipard-node/lc/requests'))
			mkdir('/var/lib/shipard-node/lc/requests', 0770, TRUE);

		// -- lanControl service
		$this->checkHostService('shn-lan-control', '/usr/lib/shipard-node/etc/systemd');
		// -- lanMonitoringMacs service
		$this->checkHostService('shn-lan-monitor-macs', '/usr/lib/shipard-node/etc/systemd');
	}

	function checkShipardNodeBoard()
	{
	}

	function tftpHomeDir()
	{
		if (is_dir('/var/lib/tftpboot'))
			return '/var/lib/tftpboot';
		if (is_dir('/srv/tftp'))
			return '/srv/tftp';

		return FALSE;
	}

	function copyFileWithCheck ($baseFileName, $srcDir, $dstDir)
	{
		if (!is_dir($dstDir))
			mkdir($dstDir, 0775, TRUE);

		$dstFileName = $dstDir.'/'.$baseFileName;
		$srcFileName = $srcDir.'/'.$baseFileName;

		if (!is_file($dstFileName) || md5_file($dstFileName) !== md5_file($srcFileName))
		{
			copy($srcFileName, $dstFileName);
			return TRUE;
		}

		return FALSE;
	}
}

