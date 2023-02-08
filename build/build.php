#!/usr/bin/env php
<?php

require_once __DIR__.'/../src/node.php';


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


class ShpdBuildApp
{
	var $arguments;

	var $allTemplates = [];
	var $allLooks = [];

	public function __construct ()
	{
	}

	public function arg ($name)
	{
		if (isset ($this->arguments [$name]))
			return $this->arguments [$name];

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
		echo $msg . "\r\n";
		return FALSE;
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return $this->err ("read file failed: $fileName");
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return $this->err ("parsing file failed: $fileName");
			return $cfg;
		}
		return $this->err ("file not found: $fileName");
	}

	public function msg ($msg)
	{
		echo '* ' . $msg . "\r\n";
	}

	public function build ()
	{
		passthru('rm -rf build/packages && mkdir build/packages/');


		$live = $this->arg('live');

		$pkg = $this->loadCfgFile('package.json');
		if (!$pkg)
			return $this->err("File package.json not found.");

		$channel = ($live) ? 'live' : 'devel';
		$commit = shell_exec("git log --pretty=format:'%h' -n 1");

		$versionId = $pkg['version'].'-'.$commit;
		if ($live)
			$versionId .= '_'.base_convert(time(), 10, 36);
		$baseFileName = 'shipard-node-'.$channel.'-'.$versionId;
		$pkgFileName = 'build/packages/'.$baseFileName.'.tgz';

		if ($live)
			$cmd = "tar --exclude=.git --exclude=build --transform \"s/^/\\/usr\\/lib\\/shipard-node-{$versionId}\\//\" -czf $pkgFileName .";
		else
			$cmd = "git archive --format=tar.gz -o $pkgFileName --prefix=/usr/lib/shipard-node-$versionId/ ".$channel;
		passthru($cmd);

		$fileCheckSum = sha1_file($pkgFileName);
		echo "* $baseFileName: version $versionId, checksum $fileCheckSum \n";

		$versionInfo = ['channel' => $channel, 'version' => $versionId, 'fileName' => $baseFileName.'.tgz', 'installVer' => 1, 'checkSum' => $fileCheckSum];
		$verFileName = 'build/packages/shipard-node-'.$channel.'.info';
		file_put_contents($verFileName, json_encode($versionInfo));

		$pkgUrl = "https://download.shipard.org/shipard-node/server-app-2/{$baseFileName}.tgz";
		$installFileNameDebian = "build/packages/shipard-node-install-{$channel}-debian.cmd";
		$installCmd = "#!/bin/sh\n";
		$installCmd .= "echo \"* Download package {$pkgUrl}\"\n";
		$installCmd .= "wget {$pkgUrl}\n";
		$installCmd .= "echo \"Unpacking {$baseFileName}.tgz\"\n";
		$installCmd .= "tar -xzf {$baseFileName}.tgz -C /\n";
		$installCmd .= "[ -d \"/usr/lib/shipard-node\" ] && rm -rf /usr/lib/shipard-node\n";
		$installCmd .= "mv /usr/lib/shipard-node-{$versionId} /usr/lib/shipard-node\n";
		$installCmd .= "ln -s /usr/lib/shipard-node/tools/shipard-node.php /bin/shipard-node\n";
		$installCmd .= "/usr/lib/shipard-node/install/install-packages.sh\n";
		$installCmd .= "echo \"DONE\"\n";
		$installCmd .= "\nexit 0\n";
		file_put_contents($installFileNameDebian, $installCmd);

		$cmd = "scp $verFileName $pkgFileName $installFileNameDebian shipardPackages:/var/www/shpd-webs/download.shipard.org/shipard-node/server-app-2/";
		echo "* Copying to server...\n";
		//echo ($cmd);

		passthru($cmd);
		$cmd = "scp $verFileName $pkgFileName $installFileNameDebian shipardPackagesBackup:/var/www/shpd-webs/download.shipard.org/shipard-node/server-app-2/";
		passthru($cmd);

		echo "=== DONE ===\n";
	}

	public function run ($argv)
	{
		$this->arguments = parseArgs($argv);

		if (count ($this->arguments) == 0)
			return $this->help ();

		switch ($this->command ())
		{
			case	"build":		return $this->build ();
		}

		echo ("unknown command...\n");

		return FALSE;
	}

	function help ()
	{
		echo
			"usage: build command arguments\r\n\r\n" .
			"commands:\r\n" .
			"   build: build [--live|--<BRANCH>]\r\n" .
			"\r\n";

		return true;
	}
}


$app = new ShpdBuildApp ();
$app->run ($argv);
