<?php

namespace Shipard\lan;


define("REGEXP_SNMP_TRIM", "/(hex|counter(32|64)|gauge|gauge(32|64)|float|ipaddress|string|integer):/i");

// MAC->IP: snmpwalk -v 2c -c public 10.23.19.2 1.3.6.1.2.1.4.22.1.2


/**
 * Class LanSearchSNMP
 */
class LanSearchSNMP extends Lan
{
	var $host;
	var $device;
	var $deviceKind;
	var $comm;

	var $sysDesc = '';
	var $interfaces = [];
	var $drives = [];
	var $storages = [];
	var $software = [];
	var $counters = [];
	var $systemInfo = [];
	var $data = [];

	var $failed = FALSE;

	CONST lastFilesDir = '/var/lib/shipard-node/lans';

	public function clear ()
	{
		$this->sysDesc = '';

		$this->counters = [];
		$this->interfaces = [];
		$this->storages = [];
		$this->software = [];
		$this->data = [];
		$this->systemInfo = [];
		$this->failed = FALSE;
	}

	function format_snmp_string($string, $snmp_oid_included = FALSE) {
		//global $banned_snmp_strings;

		$string = preg_replace(REGEXP_SNMP_TRIM, "", trim($string));

		if (substr($string, 0, 7) == "No Such") {
			return "";
		}

		if ($snmp_oid_included) {
			/* strip off all leading junk (the oid and stuff) */
			$string_array = explode("=", $string);
			if (sizeof($string_array) == 1) {
				/* trim excess first */
				$string = trim($string);
			}else if ((substr($string, 0, 1) == ".") || (strpos($string, "::") !== false)) {
				/* drop the OID from the array */
				array_shift($string_array);
				$string = trim(implode("=", $string_array));
			}else {
				$string = trim(implode("=", $string_array));
			}
		}

		/* return the easiest value */
		if ($string == "") {
			return $string;
		}

		/* now check for the second most obvious */
		if (is_numeric($string)) {
			return trim($string);
		}

		/* remove ALL quotes, and other special delimiters */
		$string = str_replace("\"", "", $string);
		$string = str_replace("'", "", $string);
		$string = str_replace(">", "", $string);
		$string = str_replace("<", "", $string);
		$string = str_replace("\\", "", $string);
		$string = str_replace("\n", " ", $string);
		$string = str_replace("\r", " ", $string);

		/* account for invalid MIB files */
		if (substr_count($string, "Wrong Type")) {
			$string = strrev($string);
			if ($position = strpos($string, ":")) {
				$string = trim(strrev(substr($string, 0, $position)));
			}else{
				$string = trim(strrev($string));
			}
		}

		/* Remove invalid chars */
		$k = strlen($string);
		for ($i=0; $i < $k; $i++) {
			if ((ord($string[$i]) <= 31) || (ord($string[$i]) >= 127)) {
				$string[$i] = " ";
			}
		}
		$string = trim($string);

		if ((substr_count($string, "Hex-STRING:")) ||
				(substr_count($string, "Hex-")) ||
				(substr_count($string, "Hex:"))) {
			/* strip of the 'Hex-STRING:' */
			$string = preg_replace("/Hex-STRING: ?/i", "", $string);
			$string = preg_replace("/Hex: ?/i", "", $string);
			$string = preg_replace("/Hex- ?/i", "", $string);

			$string_array = explode(" ", $string);

			/* loop through each string character and make ascii */
			$string = "";
			$hexval = "";
			$ishex  = false;
			for ($i=0;($i<sizeof($string_array));$i++) {
				if (strlen($string_array[$i])) {
					$string .= chr(hexdec($string_array[$i]));

					$hexval .= str_pad($string_array[$i], 2, "0", STR_PAD_LEFT);

					if (($i+1) < count($string_array)) {
						$hexval .= ":";
					}

					if ((hexdec($string_array[$i]) <= 31) || (hexdec($string_array[$i]) >= 127)) {
						if ((($i+1) == sizeof($string_array)) && ($string_array[$i] == 0)) {
							/* do nothing */
						}else{
							$ishex = true;
						}
					}
				}
			}

			if ($ishex) $string = $hexval;
		}elseif (preg_match("/(hex:\?)?([a-fA-F0-9]{1,2}(:|\s)){5}/i", $string)) {
			$octet = "";

			/* strip off the 'hex:' */
			$string = preg_replace("/hex: ?/i", "", $string);

			/* split the hex on the delimiter */
			$octets = preg_split("/\s|:/", $string);

			/* loop through each octet and format it accordingly */
			for ($i=0;($i<count($octets));$i++) {
				$octet .= str_pad($octets[$i], 2, "0", STR_PAD_LEFT);

				if (($i+1) < count($octets)) {
					$octet .= ":";
				}
			}

			/* copy the final result and make it upper case */
			$string = strtoupper($octet);
		}elseif (preg_match("/Timeticks:\s\((\d+)\)\s/", $string, $matches)) {
			$string = $matches[1];
		}

		/*foreach($banned_snmp_strings as $item) {
			if(strstr($string, $item) != "") {
				$string = "";
				break;
			}
		}*/

		return $string;
	}

	function hex2date ($hexstring)
	{
		if ($hexstring == '')
			return '0000-00-00 00:00:00';


		if (substr_count($hexstring, ","))
		{ //2015-9-22,15:7:22.0
			$dateStr = substr (str_replace(',', ' ', $hexstring), 0, -2);
			$date = new \DateTime($dateStr);
			if ($date)
				return $date->format('Y-m-d H:i:s');

			return '0000-00-00 00:00:00';
		}

		$date = '';
		$parts = explode(':', $hexstring); // 07:DF:09:16:0F:07:24:00
		if (count($parts) !== 8)
			return '0000-00-00 00:00:00';

		$date .= hexdec ($parts[0].$parts[1]).'-';
		$date .= sprintf('%02d-', hexdec ($parts[2]));
		$date .= sprintf('%02d ', hexdec ($parts[3]));
		$date .= sprintf('%02d:', hexdec ($parts[4]));
		$date .= sprintf('%02d:', hexdec ($parts[5]));
		$date .= sprintf('%02d', hexdec ($parts[6]));

		return $date;
	}

	protected function error()
	{
		$this->failed = TRUE;
	}

	function getSystemInfo ()
	{
		$this->failed = FALSE;
		$this->systemInfo = @snmprealwalk($this->host, $this->comm, '1.3.6.1.2.1.1');

		$this->sysDesc = isset($this->systemInfo['.1.3.6.1.2.1.1.1.0']) ? $this->systemInfo['.1.3.6.1.2.1.1.1.0'] : '';

		if (isset($this->sysDesc[0]) && $this->sysDesc[0] === '"')
			$this->sysDesc = substr($this->sysDesc, 1, -1);

		$this->systemInfo['details'] = [];


		if (substr($this->sysDesc, 0, 8) === 'RouterOS')
		{
			$this->getValue($this->systemInfo['details'], 'version-os', ['1.3.6.1.4.1.14988.1.1.4.4.0']);
		}

		if ($this->deviceKind === 11) // NAS
		{
			$this->getValue($this->systemInfo['details'], 'version-os',	['1.3.6.1.4.1.6574.1.5.3.0']);
			$this->getValue($this->systemInfo['details'], 'device-type',	['1.3.6.1.4.1.6574.1.5.1.0']);
			$this->getValue($this->systemInfo['details'], 'device-sn', 	['1.3.6.1.4.1.6574.1.5.2.0']);

			if (isset($this->systemInfo['details']['version-os']))
			{
				$agentInfo = [
					'osType' => 'nas',
					'osValues' => [
						'_saOS' => 'nas',
						'_saInfo' => 'os',

						'osName' => $this->systemInfo['details']['version-os'],
						'version-os' => $this->systemInfo['details']['version-os'],
						'device-sn' => $this->systemInfo['details']['device-sn'],
						'device-type' => $this->systemInfo['details']['device-type'],
					]
				];
				$this->systemInfo['details']['agentInfo'] = $agentInfo;
			}
		}

		// -- additional info
		if ($this->device)
		{
			$eng = new \Shipard\lanControl\LanControlHost($this->app);
			$lcd = $eng->createDevice($this->device);
			if ($lcd)
			{
				if ($this->app->debug)
					echo ' - get additional info...'."\n";

				$lcd->getDeviceInfo($this->systemInfo['details']);
			}

			if (isset($this->systemInfo['details']['agentInfo']))
			{
				$this->sendUserAgentInfo($this->systemInfo['details']['agentInfo']);
				unset($this->systemInfo['details']['agentInfo']);
			}
		}

		return TRUE;
	}

	function sendUserAgentInfo($agentInfo)
	{
		$now = new \DateTime();
		$introText = ";;;shipard-agent: node ".$this->app->versionInfo['version']."\n";
		$introText .= ";;;os: ".$agentInfo['osType']."\n";
		$introText .= ";;;deviceNdx: ".$this->device."\n";
		$introText .= ";;;date: ".$now->format('Ymd\THms')."\n";
		$introText .= "\n\n";
		$introText .= ';;;shipard-agent-system-info';
		$introText .= "\n\n";

		foreach ($agentInfo['osValues'] as $key => $value)
		{
			$introText .= trim($key).' : '.trim($value)."\n";
		}
		$introText .= "\n\n";

		$url = $this->app->serverCfg['dsUrl'].'/upload/mac.lan.lans';
		$result = $this->app->http_post($url, $introText);
	}

	function getDrives ()
	{
		if ($this->deviceKind !== 11) // NAS
			return;

		$this->failed = FALSE;
		$this->getPart($this->drives, '.1.3.6.1.4.1.6574.2.1.1.2', 'name');
		$this->getPart($this->drives, '.1.3.6.1.4.1.6574.2.1.1.3', 'type');
		$this->getPart($this->drives, '.1.3.6.1.4.1.6574.2.1.1.4', 'bus');
		$this->getPart($this->drives, '.1.3.6.1.4.1.6574.2.1.1.5', 'status');
	}

	function getInterfaces ()
	{
		$this->failed = FALSE;
		$this->getPart($this->interfaces, '.1.3.6.1.2.1.2.2.1.1', 'Index');
		$this->getPart($this->interfaces, '.1.3.6.1.2.1.2.2.1.8', 'Status');
		$this->getPart($this->interfaces, '.1.3.6.1.2.1.2.2.1.2', 'Port_name');
		$this->getPart($this->interfaces, '.1.3.6.1.2.1.31.1.1.1.18', 'Descr');
		$this->getPart($this->interfaces, '.1.3.6.1.2.1.2.2.1.3', 'Type');
	}

	function getStorages ()
	{
		$this->failed = FALSE;
		$this->getPart($this->storages, '.1.3.6.1.2.1.25.2.3.1.1', 'Index');
		$this->getPart($this->storages, '.1.3.6.1.2.1.25.2.3.1.3', 'name');
		$this->getPart($this->storages, '.1.3.6.1.2.1.25.2.3.1.4', 'blockSize');
		$this->getPart($this->storages, '.1.3.6.1.2.1.25.2.3.1.5', 'sizeBlocks');
		$this->getPart($this->storages, '.1.3.6.1.2.1.25.2.3.1.6', 'usedBlocks');
	}

	function getSoftware ()
	{
		$this->failed = FALSE;
		$this->getPart($this->software, '.1.3.6.1.2.1.25.6.3.1.1', 'Index');
		$this->getPart($this->software, '.1.3.6.1.2.1.25.6.3.1.2', 'name');
		$this->getPart($this->software, '.1.3.6.1.2.1.25.6.3.1.4', 'type');
		$this->getPart($this->software, '.1.3.6.1.2.1.25.6.3.1.5', 'date');
	}

	function getPrinter ()
	{
		if ($this->deviceKind !== 3)
			return;

		$this->failed = FALSE;

		$sysDesc = isset($this->systemInfo['.1.3.6.1.2.1.1.1.0']) ? $this->systemInfo['.1.3.6.1.2.1.1.1.0'] : '';
		if (isset($sysDesc[0]) && $sysDesc[0] === '"')
			$sysDesc = substr($sysDesc, 1, -1);

		$this->getValue($this->counters, 'p-pp-all', ['1.3.6.1.2.1.43.10.2.1.4.1.1']);

		if (substr($sysDesc, 0, 6) === 'KONICA' || substr($sysDesc, 0, 7) === 'Develop' || substr($sysDesc, 0, 2) === 'C ')
		{
			$this->getValue($this->counters, 'p-pp-clr', ['1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.2.2']);
			$this->getValue($this->counters, 'p-pp-bw',  ['1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.1.2']);
			$this->getValue($this->counters, 'p-pp-2c',  ['1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.4.2']);

			$this->getValue($this->counters, 'p-cp-clr', ['1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.2.1']);
			$this->getValue($this->counters, 'p-cp-bw',  ['1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.1.1']);
			$this->getValue($this->counters, 'p-cp-2c',  ['1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.4.1']);

			$this->getValue($this->counters, 'p-sp-all', ['1.3.6.1.4.1.18334.1.1.1.5.7.2.1.5.0']);
		}
		else
		if (substr($sysDesc, 0, 2) === 'HP')
		{
			$this->getValue($this->counters, 'p-pp-clr', ['1.3.6.1.4.1.11.2.3.9.4.2.1.4.1.2.7']);
			$this->getValue($this->counters, 'p-pp-bw',  ['1.3.6.1.4.1.11.2.3.9.4.2.1.4.1.2.6']);
		}
		else
		if (substr($sysDesc, 0, 5) === 'SHARP')
		{
			$this->getValue($this->counters, 'p-pp-clr', ['1.3.6.1.4.1.2385.1.1.19.2.1.3.1.4.63']);
			$this->getValue($this->counters, 'p-pp-bw',  ['1.3.6.1.4.1.2385.1.1.19.2.1.3.1.4.61']);
			$this->getValue($this->counters, 'p-cp-clr', ['1.3.6.1.4.1.2385.1.1.19.2.1.3.5.4.63']);
			$this->getValue($this->counters, 'p-cp-bw',  ['1.3.6.1.4.1.2385.1.1.19.2.1.3.4.4.61']);
		}

		/*
			printed pages:
	      - Color (Xerox): 1.3.6.1.4.1.253.8.53.13.2.1.6.1.20.33
			  - Mono (Xerox): 1.3.6.1.4.1.253.8.53.13.2.1.6.1.20.34
				- Color (Lexmark): 1.3.6.1.4.1.641.2.1.5.3
				- Mono (Lexmark): 1.3.6.1.4.1.641.2.1.5.2
			CANON:
				- 101 (Total) - 1.3.6.1.4.1.1602.1.11.1.3.1.4.101
				- 112 Total (Black and White/Large) - 1.3.6.1.4.1.1602.1.11.1.3.1.4.112
				- 113 Total (BW/Small) - 1.3.6.1.4.1.1602.1.11.1.3.1.4.113
				- 122 Total (Full Color + Single / large) 1.3.6.1.4.1.1602.1.11.1.3.1.4.122
				- 123 Total Full Color + Single/Small) 1.3.6.1.4.1.1602.1.11.1.3.1.4.123
				- 501 Scan - 1.3.6.1.4.1.1602.1.11.1.3.1.4.501
			HP:
				- 1.3.6.1.4.1.11.2.3.9.4.2.1.1.16.2.1.5 - total scan
	    konica/minolta
	      - 1.3.6.1.4.1.18334.1.1.1.5.7.2.1.1.0 – Total
				- 1.3.6.1.4.1.18334.1.1.1.5.7.2.1.3.0 – Total Duplex
				- 1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.3.1 - copy single color???
				- 1.3.6.1.4.1.18334.1.1.1.5.7.2.2.1.5.3.2 - print single color???

				- 1.3.6.1.2.1.43.5.1.1.17.1 - serial number
				- 1.3.6.1.2.1.1.3.0 - uptime
		*/
	}

	function addSwitchVal(&$data, $valname, $value)
	{
		foreach ($data as $val)
			$data[$val["Index"]][$valname] = $value;
	}

	function getPart(&$data, $oid, $name)
	{
		if ($this->failed)
			return;
		$array = @snmprealwalk($this->host, $this->comm, $oid);
		if ($array === FALSE)
			return $this->error();
		if ($array === NULL)
			return;

		foreach($array as $key => $val)
		{
			$index = preg_replace('/.*\.([0-9]+)$/', "\\1", $key);
			$val = $this->format_snmp_string($val);
			$data[$index][$name] = $val;
		}
	}

	function getValue(&$data, $name, $oids)
	{
		foreach ($oids as $oid)
		{
			$array = @snmprealwalk($this->host, $this->comm, $oid);
			if ($array === FALSE || $array === NULL)
				continue;

			foreach ($array as $key => $val)
			{
				$index = preg_replace('/.*\.([0-9]+)$/', "\\1", $key);
				$val = $this->format_snmp_string($val);
				$data[$name] = $val;
				break;
			}
			break;
		}
	}

	protected function createData()
	{
		$this->createDataSoftware();
		$this->createDataDrives();
		$this->createDataStorages();
		$this->createDataSystemInfo();
		$this->createDataCounters();
	}

	protected function createDataBlock($blockType)
	{
		$this->data[$blockType] = [
				'type' => $blockType, 'device' => $this->device,
				'datetime' => $this->now(), 'checkSum' => '', 'items' => []
		];
	}

	protected function createDataBlockCheckSum($blockType)
	{
		$itemsStr = json_encode ($this->data[$blockType]['items']);
		$this->data[$blockType]['checkSum'] = md5($itemsStr);
	}

	protected function createDataCounters()
	{
		if (!count($this->counters))
			return;

		$bt = 'counters';
		$this->createDataBlock($bt);
		foreach ($this->counters as $key => $value)
		{
			$newItem = ['id' => $key, 'val' => $value];
			$this->data[$bt]['items'][] = $newItem;
		}
		$this->createDataBlockCheckSum($bt);
	}

	protected function createDataSoftware()
	{
		$bt = 'sw';
		$this->createDataBlock($bt);
		foreach ($this->software as $item)
		{
			$newItem = [
					'name' => isset($item['name']) ? $item['name'] : '---unknown---',
					'type' => isset($item['type']) ? $item['type'] : 999,
					'date' => isset($item['date']) ? $this->hex2date($item['date']) : $this->hex2date('')
			];
			$newItem ['package'] = 0;
			$newItem ['app'] = 0;
			$this->data[$bt]['items'][] = $newItem;
		}
		$this->createDataBlockCheckSum($bt);
	}

	protected function createDataSystemInfo()
	{
		$bt = 'system';
		$this->createDataBlock($bt);

		if (isset($this->systemInfo['.1.3.6.1.2.1.1.5.0']))
			$this->data[$bt]['items']['name'] = $this->systemInfo['.1.3.6.1.2.1.1.5.0'];
		if (isset($this->systemInfo['.1.3.6.1.2.1.1.1.0']))
			$this->data[$bt]['items']['desc'] = $this->systemInfo['.1.3.6.1.2.1.1.1.0'];

		foreach ($this->systemInfo['details'] as $k => $v)
		{
			$this->data[$bt]['items'][$k] = $v;
		}

		$this->createDataBlockCheckSum($bt);
	}

	protected function createDataDrives()
	{
		if ($this->deviceKind !== 11) //NAS
			return;

		$bt = 'drives';
		$this->createDataBlock($bt);
		foreach ($this->drives as $item)
		{
			$newItem = $item;
			$this->data[$bt]['items'][] = $newItem;
		}
		$this->createDataBlockCheckSum($bt);
	}

	protected function createDataStorages()
	{
		$bt = 'storages';
		$this->createDataBlock($bt);
		foreach ($this->storages as $item)
		{
			$newItem = ['name' => $item['name']];
			$newItem ['size'] = $item['blockSize'] * $item['sizeBlocks'];
			$newItem ['used'] = $item['blockSize'] * $item['usedBlocks'];
			$this->data[$bt]['items'][] = $newItem;
		}
		$this->createDataBlockCheckSum($bt);
	}

	public function saveDataToUpload ()
	{
		if (!is_dir(self::lastFilesDir))
			mkdir(self::lastFilesDir, 0770, TRUE);

		foreach ($this->data as $id => &$block)
		{
			if (!count($block['items']))
				continue;

			$fileName = 'snmp-'.$block['device'].'-'.$block['type'].'.json';
			$fullFileName = self::lastFilesDir.'/'.$fileName;

			$oldCheckSum = '---';
			$oldInf = $this->app->loadCfgFile($fullFileName);
			if ($oldInf)
				$oldCheckSum = $oldInf['checkSum'];

			$itemsStr = json_encode($block['items']);
			$newCheckSum = md5($itemsStr);
			$block['checkSum'] = $newCheckSum;

			$oldTime = NULL;
			if ($oldInf && isset($oldInf['lastReport']))
				$oldTime = new \DateTime($oldInf['lastReport']);
			else
				$oldTime = new \DateTime('2000-01-01 00:00:01');
			$now = new \DateTime();
			$interval = $now->diff($oldTime);
			$hours = abs(($interval->days * 24) + $interval->h);

			if ($oldCheckSum !== $newCheckSum || $hours > 24)
			{ // new version; save to upload
				if ($oldCheckSum !== $newCheckSum)
					$block['lastChange'] = $this->now();
				$block['lastReport'] = $this->now();
				$data = ['type' => Lan::uiSNMP, 'data' => $block];
				$this->saveUploadInfo (Lan::uiSNMP, $data);
			}
			file_put_contents($fullFileName, json_encode($block));
		}
	}

	public function search ()
	{
		if (!$this->lansCfg)
			return;

		foreach ($this->lansCfg['ip'] as $ip)
		{
			if ($ip['ip'] === '')
				continue;

			$existedIp = $this->storage->getItem(self::icIP, $ip['ip']);
			if (!count($existedIp))
				continue;

			if (!isset($existedIp['rt0v']) || $existedIp['rt0v'] == -1)
				continue;

			$this->setDevice ($ip['ip'], $ip['d'], $ip['dk']);
			$this->doOne();
			$this->saveDataToUpload();
			$this->clear();
		}
	}

	public function scanHost ($host)
	{
		$this->setDevice ($host, 0);
		$this->doOne();
		print_r ($this->data);
	}

	public function doOne ()
	{
		if ($this->app->debug)
			echo 'get snmp for '.$this->host."\n";

		//$this->getInterfaces ();
		$this->getSystemInfo();
		$this->getDrives();
		$this->getStorages ();
		$this->getSoftware ();
		$this->getPrinter ();
		$this->createData();

		if ($this->deviceKind == 10)
		{ // camera
			$fileName = '/var/lib/shipard-node/tmp/lan-device-'.$this->device.'-agentInfo.json';
			if (is_readable($fileName))
				unlink ($fileName);

			$cmd = '/usr/lib/shipard-node/onvif/shn-onvif-tools.js saveCameraInfo '.$this->device;
			exec($cmd);

			$agentInfo = $this->app->loadCfgFile($fileName);
			if ($agentInfo)
				$this->sendUserAgentInfo($agentInfo);
		}
	}

	public function setDevice ($host, $device, $deviceKind = FALSE)
	{
		snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
		snmp_set_quick_print(1);
		snmp_set_enum_print(1);

		$this->host = $host;
		$this->device = $device;
		$this->deviceKind = $deviceKind;
		$this->comm = 'public';
	}
}
