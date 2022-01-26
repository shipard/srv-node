<?php


namespace Shipard\host;


/**
 * Class CertsManager
 */
class CertsManager extends \Shipard\host\Core
{
	var $certsBasePath = '/var/lib/shipard-node/certs/';
	var $data = NULL;

	function downloadCerts()
	{
		$this->check();

		$url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-get-node-server-certs/'.$this->app->serverCfg['serverId'];
		$cfg = $this->app->apiCall($url);

		print_r($cfg);

		$this->installCertificates($cfg['certificates']);

		return TRUE;
	}

	function check()
	{
		if (!is_dir($this->certsBasePath))
		{
			mkdir($this->certsBasePath, 0755);
		}
	}

	function installCertificates($certs)
	{
		if (!count($certs))
			return;

		$needRestart = 0;	
		foreach ($certs as $certName => $cert)
		{
			$certPath = $this->certsBasePath.$certName.'/';
			if (!is_dir($certPath))
			{
				mkdir($certPath, 0755);
			}

			foreach ($cert['files'] as $fileName => $fileContent)
			{
				$oldFileCheckSum = sha1_file($certPath.$fileName);
				$newFileCheckSum = sha1($fileContent);

				if ($oldFileCheckSum !== $newFileCheckSum)
					$needRestart++;
				
				file_put_contents($certPath.$fileName, $fileContent);
			}
		}

		if ($needRestart)
		{
			$restartServices = [];
			if (is_dir('/etc/nginx'))	
				$restartServices[] = 'nginx';

			if (is_dir('/etc/mosquitto'))
				$restartServices[] = 'mosquitto';

			$this->restartHostServices($restartServices, 'reload');
		}
	}

	public function run()
	{
		$this->check();
	}
}


