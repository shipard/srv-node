<?php

namespace Shipard\email;


/**
 * Class ShipardEmailDelivery
 */
class ShipardEmailDelivery extends \Shipard\Utility
{
	var ?array $emailConfig = NULL;
	var string $incomingEmailsDir = '/var/lib/shipard-node/email/';
	var string $dstAddressID = '';
	var $dstAddressSubID = '';
	var $dstAddressDomain = '';
	var ?array $dstDomainCfg = NULL;
	var string $hostingURL = '';
	var string $hostingAPIKey = '';
	var string $uploadURL = '';
	var array $reportsEmailIds = ['dmarc'];

	public function init()
	{
		$this->emailConfig = $this->app->loadCfgFile('/etc/shipard-node/shipard-email-delivery.json');
		if (!$this->emailConfig)
			return $this->app->err("invalid config file `/etc/shipard-node/shipard-email-delivery.json`");

		if (!$this->checkDirs())
			return $this->app->err("some directories is not valid...");

		return TRUE;	
	}

	public function checkDirs()
	{
		if (!is_dir($this->incomingEmailsDir))
		{
			mkdir($this->incomingEmailsDir, 0770, true);
		}
		if (!is_dir($this->incomingEmailsDir.'/queue'))
		{
			mkdir($this->incomingEmailsDir.'/queue', 0770, true);
		}
		if (!is_dir($this->incomingEmailsDir.'/done'))
		{
			mkdir($this->incomingEmailsDir.'/done', 0770, true);
		}
		if (!is_dir($this->incomingEmailsDir.'/reports'))
		{
			mkdir($this->incomingEmailsDir.'/reports', 0770, true);
		}

		return TRUE;
	}

	public function doIncomingEmail($rcptEmailAddress)
	{
		$now = new \DateTime();
		$nowStr = $now->format('Y-m-d_H-i-s');
		$bn = $nowStr.'-'.sha1 (mt_rand(12345, 987654321) . time() . '-' . mt_rand(1111111111, 9999999999)) . '.eml';
	
		$destFileName = $this->incomingEmailsDir . $bn;
		$fileReader = fopen ('php://stdin', 'r');
		$fileWriter = fopen ($destFileName, "w+");
	
		while (true)
		{
			$buffer = fread ($fileReader, 1024);
			if (!$buffer || strlen ($buffer) == 0)
			{
				fclose ($fileReader);
				fclose ($fileWriter);
				break;
			}
			fwrite ($fileWriter, $buffer);
		}
	
		$emailInfo = ['rcptEmailAddress' => $rcptEmailAddress, 'dateIncoming' => $now->format('Y-m-d H-i-s'), 'emlFileName' => $destFileName, 'log' => []];

		file_put_contents ($destFileName.'.json', json_encode($emailInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

		$this->send($destFileName.'.json');
	}

	protected function send(string $msgCfgFileName)
	{
		$msgCfg = $this->app->loadCfgFile($msgCfgFileName);
		if (!$msgCfg)
		{
			return $this->app->err("invalid email msgCfgFile `$msgCfgFileName`");
		}

		$this->openLog($msgCfg);		

		if (!$this->prepareAddress($msgCfg))
			return $this->app->err("invalid recepient address at msgCfgFile `$msgCfgFileName`");

		if (in_array($this->dstAddressID, $this->reportsEmailIds))
		{
			$this->moveTo($msgCfg, 'reports');
			return true;
		}

		if (!$this->prepareHostingInfo($msgCfg))
			return false;

		if (!$this->detectUploadURL($msgCfg))
		{

			return false;
		}

		if (!$this->delivery($msgCfg))
		{

			return false;
		}

		return true;
	}

	protected function prepareAddress(array &$msgCfg)
	{
		$rcptEmailAddress = $msgCfg['rcptEmailAddress'];
		$addrParts = explode ('@', $rcptEmailAddress);
		$dstAddressDomain = $addrParts[1];
		$mainAddrParts = explode ('+', $addrParts[0]);
		$dstAddressID = $mainAddrParts[0];
		$dstAddressSubID = '';
		if (isset($mainAddrParts[1]))
			$dstAddressSubID = $mainAddrParts[1];

		if ($dstAddressSubID === '')
		{
			$mainAddrParts = explode ('--', $addrParts[0]);
			if (isset($mainAddrParts[1]))
			{
				$dstAddressID = $mainAddrParts[0];
				$dstAddressSubID = $mainAddrParts[1];
			}
		}

		$this->dstAddressID = $dstAddressID;
		$this->dstAddressSubID = $dstAddressSubID;
		$this->dstAddressDomain = $dstAddressDomain;

		$msgCfg['log'][0]['dstAddress'] = ['dstAddressID' => $this->dstAddressID, 'dstAddressSubID' => $this->dstAddressSubID, 'dstAddressDomain' => $this->dstAddressDomain];

		return TRUE;
	}

	protected function prepareHostingInfo(array &$msgCfg)
	{
		if (!isset($this->emailConfig['domains'][$this->dstAddressDomain]))
			return $this->app->err("Domain `{$this->dstAddressDomain}` is not enabled for delivery");

		$this->dstDomainCfg = $this->emailConfig['domains'][$this->dstAddressDomain];
		if (isset($this->dstDomainCfg['hostingURL']))
			$this->hostingURL = $this->dstDomainCfg['hostingURL'];
		elseif (isset($this->emailConfig['hostingURL']))
			$this->hostingURL = $this->emailConfig['hostingURL'];

		if ($this->hostingURL === '')
			return $this->app->err("unknown hostingURL");

		if (isset($this->dstDomainCfg['hostingAPIKey']))
			$this->hostingAPIKey = $this->dstDomainCfg['hostingAPIKey'];
		elseif (isset($this->emailConfig['hostingAPIKey']))
			$this->hostingAPIKey = $this->emailConfig['hostingAPIKey'];

		if ($this->hostingAPIKey === '')
			return $this->app->err("unknown hostingAPIKey");
			
		return true;	
	}

	protected function delivery(array &$msgCfg)
	{
		$uploadUrl = $this->uploadURL.'/upload/e10pro.wkf.messages/e10pro.wkf.messages/0/email.eml';
		if ($this->dstAddressSubID !== '')
			$uploadUrl .= '?subAddress='.$this->dstAddressSubID;

		$msgCfg['log'][0]['dstAddress']['uploadURL'] = $uploadUrl;
		
		$fp = fopen ($msgCfg['emlFileName'], 'r');
	
		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_HEADER, 0);
		curl_setopt ($ch, CURLOPT_VERBOSE, 0);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_INFILE, $fp);
		curl_setopt ($ch, CURLOPT_INFILESIZE, filesize($msgCfg['emlFileName']));
	
		curl_setopt ($ch, CURLOPT_UPLOAD, true);
		$result = curl_exec ($ch);
	
		if ($result === FALSE)
		{
			$errMsg = curl_error($ch);
			$this->moveTo($msgCfg, 'queue', 'ERROR: upload failed: `'.$errMsg.'`');
			return false;
		}

		curl_close ($ch);

		$this->moveTo($msgCfg, 'done', 'SUCCESS: upload done with result `'.json_encode($result).'`');

		return true;
	}

	protected function detectUploadURL(array &$msgCfg)
	{
		$url =  $this->hostingURL . 'api/call/e10pro.hosting.server.getUploadUrl?address='.$this->dstAddressID;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'e10-api-key: ' . $this->hostingAPIKey,
			'e10-device-id: ' . $this->app->machineDeviceId(),
			'connection: close',
		]);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, TRUE);

		if (($resultCode = curl_exec($ch)) === false)
		{
			$errMsg = curl_error($ch);
			curl_close($ch);
			$this->app->err("invalid API response: `$errMsg`");
			return false;
		}
		curl_close($ch);

		$resultData = json_decode ($resultCode, true);
		if (!$resultData)
		{
			$this->addLogMsg($msgCfg, 'result: '.$resultCode);
			$this->moveTo($msgCfg, 'queue', 'ERROR: invalid upload URL API response');
			return $this->app->err("invalid upload URL API response: `$resultCode`");
		}

		if (!isset ($resultData ['data']['url']))
		{
			$this->addLogMsg($msgCfg, 'result: '.$resultCode);
			$this->moveTo($msgCfg, 'queue', 'ERROR: uploadUrl not found');
			$this->app->err("uploadUrl not found; result is `$resultCode`");
			return false;
		}

		$this->uploadURL = $resultData ['data']['url'];

		return true;
	}

	protected function openLog(array &$msgCfg)
	{
		$now = new \DateTime();
		if (count(($msgCfg['log'])))
			$msgCfg['log'] = array_merge([], $msgCfg['log']);
		else	
			$msgCfg['log'][0] = [];
		
		$msgCfg['log'][0]['dateTime'] = $now->format('Y-m-d H:m:s');
	}

	protected function addLogMsg(array &$msgCfg, string $msg)
	{
		$now = new \DateTime();
		$msgCfg['log'][0]['msgs'][] = ['dateTime' => $now->format('Y-m-d H:m:s'), 'msg' => $msg];
	}

	protected function moveTo(array &$msgCfg, string $to, string $msg = '')
	{
		$srcFileNameEML = $msgCfg['emlFileName'];
		$srcFileNameCFG = $msgCfg['emlFileName'].'.json';
		$dstFileNameEML = $this->incomingEmailsDir.'/'.$to.'/'.basename($msgCfg['emlFileName']);
		$dstFileNameCFG = $this->incomingEmailsDir.'/'.$to.'/'.basename($msgCfg['emlFileName']).'.json';
		rename($srcFileNameEML, $dstFileNameEML);
		rename($srcFileNameCFG, $dstFileNameCFG);
		
		$msgCfg['emlFileName'] = $dstFileNameEML;

		if ($msg !== '')
			$this->addLogMsg($msgCfg, $msg);
		
		file_put_contents ($dstFileNameCFG, json_encode($msgCfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}
}
