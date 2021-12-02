<?php

namespace Shipard;

/**
 * Class Application
 */
class Application
{
	var $arguments = NULL;
	var $serverCfg = FALSE;
	var $nodeCfg = FALSE;
	var $mqttEngineCfg = FALSE;
	var $lanControlDevicesCfg = FALSE;
	var $versionInfo = FALSE;

	var $debug = 0;

	var $camsDir = '';
	var $picturesDir = '';
	var $videoDir = '';
	var $vehicleDetectDir = '';
	var $rootDir = '';
	var $redis = NULL;

	public function __construct ($argv)
	{
		if ($argv)
			$this->arguments = parseArgs($argv);

		$this->serverCfg = $this->loadCfgFile('/etc/shipard-node/server.json');
		if (!$this->serverCfg)
			$this->err ("File /etc/shipard-node/server.json not found or has syntax error!");

		$this->nodeCfg = $this->loadCfgFile('/etc/shipard-node/config.json');
		$this->mqttEngineCfg = $this->loadCfgFile('/etc/shipard-node/mqtt-engine.json');

		$this->rootDir = '/var/lib/shipard-node/';
		$this->camsDir = $this->rootDir.'cameras/';
		$this->picturesDir = $this->camsDir.'pictures/';
		$this->vehicleDetectDir = $this->camsDir.'pictures/vehicle-detect/';
		$this->videoDir = $this->camsDir.'video/';

		if ($this->arg('debug'))
		{
			$this->debug = intval($this->arg('debug'));
			echo "-- debug mode: {$this->debug}\n";
		}

		// -- version info
		$this->versionInfo = $this->loadCfgFile('/usr/lib/shipard-node/shipard-node.info');
		if (!$this->versionInfo)
			$this->versionInfo = ['channel' => 'none', 'version' => '0.0', 'fileName' => '', 'checkSum' => ''];
	}

	public function arg ($name)
	{
		if (isset ($this->arguments [$name]))
			return strval($this->arguments [$name]);

		return FALSE;
	}

	public function apiCall ($url)
	{
		$opts = array(
				'http'=>array(
						'timeout' => 30, 'method'=>"GET",
						'header'=>
								"e10-api-key: " . $this->serverCfg['apiKey'] . "\r\n".
								"e10-device-id: " . $this->machineDeviceId (). "\r\n".
								"Connection: close\r\n"
				)
		);
		$context = stream_context_create($opts);

		$resultCode = file_get_contents ($url, FALSE, $context);

		$resultData = json_decode ($resultCode, TRUE);
		return $resultData;
	}

	public function apiSend ($url, $requestData)
	{
		$requestDataStr = json_encode($requestData);

		$opts = array(
			'http'=>array(
				'timeout' => 30,
				'method'=>"GET",
				'header'=>
					"e10-api-key: " . $this->serverCfg['apiKey'] . "\r\n".
					"e10-device-id: " . $this->machineDeviceId (). "\r\n".
					"Content-type: text/json"."\r\n".
					"Content-Length: " . strlen($requestDataStr). "\r\n".
					"Connection: close\r\n",
				'content' => $requestDataStr,
			)
		);
		$context = stream_context_create($opts);


		$resultCode = file_get_contents ($url, FALSE, $context);
		$resultData = json_decode ($resultCode, TRUE);

		return $resultData;
	}

	function http_post ($url, $data)
	{
		$data_len = strlen ($data);
		$context = stream_context_create (
			[
				'http'=> [
					'method'=>'POST',
					'header'=>"Content-type: text/plain\r\nConnection: close\r\nContent-Length: $data_len\r\n",
					'content'=>$data,
					'timeout' => 30
				]
			]
		);

		$result = @file_get_contents ($url, FALSE, $context);
		$responseHeaders = isset($http_response_header) ? $http_response_header : [];
		return ['content'=> $result, 'headers'=> $responseHeaders];
	}

	public function sendAlert ($alert)
	{
		$requestDataStr = json_encode($alert);
		$url = $this->serverCfg['dsUrl'].'/api/objects/alert';

		$opts = [
			'http' => [
				'timeout' => 30,
				'method' => "GET",
				'header' =>
					"e10-api-key: " . $this->serverCfg['apiKey'] . "\r\n".
					"e10-device-id: " . $this->machineDeviceId (). "\r\n".
					"Content-type: text/json"."\r\n".
					"Content-Length: " . strlen($requestDataStr). "\r\n".
					"Connection: close\r\n",
				'content' => $requestDataStr,
			]
		];
		$context = stream_context_create($opts);


		$resultCode = file_get_contents ($url, FALSE, $context);
		$resultData = json_decode ($resultCode, TRUE);

		if (!$resultData || !isset($resultData['status']) || !$resultData['status'])
		{
			if (!is_dir('/var/lib/shipard-node/upload/alerts'))
				mkdir('/var/lib/shipard-node/upload/alerts', 0775, TRUE);
			$alertFileName = time().'-'.$alert['alertId'].'-'.mt_rand(1000000,9999999).'-'.md5($requestDataStr).'.json';
			file_put_contents('/var/lib/shipard-node/upload/alerts/'.$alertFileName, $requestDataStr);
		}

		return $resultData;
	}

	public function redis(): \Redis
	{
		if (!$this->redis)
		{
			$this->redis = new \Redis ();
			$this->redis->connect('127.0.0.1');
		}

		return $this->redis;
	}

	public function cfgItem ($cfg, $key, $defaultValue = NULL)
	{
		if (isset ($cfg [$key]))
			return $cfg [$key];

		$parts = explode ('.', $key);
		if (!count ($parts))
			return $defaultValue;

		$value = NULL;
		$top = $cfg;
		forEach ($parts as $p)
		{
			if (isset ($top [$p]))
			{
				$value = &$top [$p];
				$top = &$top [$p];
				continue;
			}
			return $defaultValue;
		}

		return $value;
	}

	public function command ($idx = 0)
	{
		if (isset ($this->arguments [$idx]))
			return $this->arguments [$idx];

		return "";
	}

	public function err ($msg)
	{
		if ($msg === FALSE)
			return TRUE;

		if (is_array($msg))
		{
			if (count($msg) !== 0)
			{
				forEach ($msg as $m)
					echo ("! " . $m['text']."\n");
				return FALSE;
			}
			return TRUE;
		}

		echo ("ERROR: ".$msg."\n");
		return FALSE;
	}

	public function lanControlDeviceCfg($deviceNdx)
	{
		if ($this->lanControlDevicesCfg === FALSE)
			$this->lanControlDevicesCfg = $this->loadCfgFile('/etc/shipard-node/lanControlDevices.json');

		if (!$this->lanControlDevicesCfg)
			return NULL;

		if ($deviceNdx === NULL)
			return $this->lanControlDevicesCfg;

		if (!isset($this->lanControlDevicesCfg[$deviceNdx]))
			return NULL;

		return $this->lanControlDevicesCfg[$deviceNdx];
	}

	public function loadCfgFile ($fileName)
	{
		if (is_file ($fileName))
		{
			$cfgString = file_get_contents ($fileName);
			if (!$cfgString)
				return NULL;
			$cfg = json_decode ($cfgString, true);
			if (!$cfg)
				return NULL;
			return $cfg;
		}
		return NULL;
	}

	public function machineDeviceId ()
	{
		if (!is_file('/etc/e10-device-id.cfg'))
		{
			$deviceId = md5(json_encode(posix_uname()).mt_rand (1000000, 999999999).'-'.time().'-'.mt_rand (1000000, 999999999));
			file_put_contents('/etc/e10-device-id.cfg', $deviceId);
		}
		else
		{
			$deviceId = file_get_contents('/etc/e10-device-id.cfg');
		}

		return $deviceId;
	}

	static function wwwGroup ()
	{
		if (PHP_OS === 'Darwin')
			return '_www';
		return 'www-data';
	}

	static function wwwUser ()
	{
		if (PHP_OS === 'Darwin')
			return '_www';
		return 'www-data';
	}

	public function superuser ()
	{
		return (0 == posix_getuid());
	}

	public function run ()
	{
		echo "nothing to do...\r\n";
	}

	function format_snmp_string($string, $snmp_oid_included = FALSE) {
		$REGEXP_SNMP_TRIM =  "/(hex|counter(32|64)|gauge|gauge(32|64)|float|ipaddress|string|integer):/i";
		$string = preg_replace($REGEXP_SNMP_TRIM, "", trim($string));

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
}

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

