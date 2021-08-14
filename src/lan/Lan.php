<?php

namespace Shipard\lan;


/**
 * Class Lan
 */
class Lan
{
	/** @var  \Shipard\Application */
	var $app;

	/** @var  \Shipard\NodeStorage */
	var $storage;

	CONST icIP = 'e10-nl-ip';
	CONST uiUnknownIp = 'e10-nl-unkip', uiSNMP = 'e10-nl-snmp';
	CONST rtCount = 3;

	var $lansCfg = NULL;

	var $content = [];
	var $toDelete = [];

	public function __construct($app)
	{
		$this->app = $app;

		$this->storage = new \Shipard\NodeStorage ($this->app);

		if (isset($this->app->nodeCfg['cfg']['lan']))
			$this->lansCfg = $this->app->nodeCfg['cfg']['lan'];
	}

	protected function addUnknownIp (array $ip)
	{
		$this->checkIpInfo ($ip);
		$this->storage->addItem(self::icIP, $ip['ip'], $ip);
	}

	protected function addIpMonitoringInfo (array $ip)
	{
		$existedIp = $this->storage->getItem(self::icIP, $ip['ip']);
		if (count($existedIp))
		{
			$newIp = ['r' => $ip['r']];
			for ($i = self::rtCount - 1; $i > 0; $i--)
			{
				$keyFrom = 'rt'.($i - 1);
				$keyTo = 'rt'.$i;

				$newIp[$keyTo.'t'] = $existedIp[$keyFrom.'t'];
				$newIp[$keyTo.'v'] = $existedIp[$keyFrom.'v'];
			}

			$newIp['rt0t'] = $ip['time'];
			$newIp['rt0v'] = $ip['rt'];

			$this->storage->addItem(self::icIP, $ip['ip'], $newIp);
			return;
		}

		$this->checkIpInfo ($ip);
		$this->storage->addItem(self::icIP, $ip['ip'], $ip);
	}

	public function checkIpInfo (&$ip)
	{
		for ($i = 0; $i < self::rtCount; $i++)
		{
			$key = 'rt'.$i;
			if (!isset ($ip[$key.'t']))
				$ip[$key.'t'] = 0;
			if (!isset ($ip[$key.'v']))
				$ip[$key.'v'] = 0;
		}
	}

	public function ipv4rangeInfo ($range)
	{
		$parts = explode('/', $range);
		$ip_address = $parts[0];
		$ip_nmask = long2ip(-1 << (32 - (int)$parts[1]));
		$ip_count = 1 << (32 - (int)$parts[1]);

		$hosts = [];

		//convert ip addresses to long form
		$ip_address_long = ip2long($ip_address);
		$ip_nmask_long = ip2long($ip_nmask);

		//calculate network address
		$ip_net = $ip_address_long & $ip_nmask_long;

		//calculate first usable address
		$ip_host_first = ((~$ip_nmask_long) & $ip_address_long);
		$ip_first = ($ip_address_long ^ $ip_host_first) + 1;

		//calculate last usable address
		$ip_broadcast_invert = ~$ip_nmask_long;
		////$ip_last = ($ip_address_long | $ip_broadcast_invert) - 1;
		$ip_last = $ip_first + $ip_count - 2;

		//calculate broadcast address
		$ip_broadcast = $ip_address_long | $ip_broadcast_invert;

		for ($ip = $ip_first; $ip <= $ip_last; $ip++)
		{
			array_push($hosts, long2ip($ip));
		}

		$block_info = [
				'network' => $ip_net,
				'first_host' => $ip_first,
				'last_host' => $ip_last,
				'broadcast' => $ip_broadcast,
				'hosts' => $hosts
		];

		return $block_info;
	}

	public function ipv4CIDRtoMask ($cidr)
	{
		$cidr = explode('/', $cidr);
		return array($cidr[0], long2ip(-1 << (32 - (int)$cidr[1])));
	}

	protected function parseFping ($string, $rangeId = 0)
	{
		$time = time()+date('Z');
		$result = [];
		$rows = explode ("\n", $string);
		foreach ($rows as $row)
		{
			$parts = explode (' ', $row);
			if (count($parts) !== 3)
				continue;
			$newItem = ['ip' => $parts[0], 'r' => $rangeId, 'rt' => substr($parts[1], 1), 'time' => $time];
			if (isset($this->app->nodeCfg['cfg']['lan']['ip'][$parts[0]]))
			{
				$newItem['d'] = $this->app->nodeCfg['cfg']['lan']['ip'][$parts[0]]['d'];
				$newItem['r'] = $this->app->nodeCfg['cfg']['lan']['ip'][$parts[0]]['r'];
			}
			$result[] = $newItem;
		}

		return $result;
	}

	public function now()
	{
		$now = new \DateTime();
		return $now->format('Y-m-d H:i:s');
	}

	protected function parseArp ($string)
	{
		$time = time();
		$result = [];
		$rows = explode ("\n", $string);
		array_pop($rows);
		foreach ($rows as $row)
		{
			$parts = preg_split("/[\s+]+/", $row);
			if (count($parts) !== 5)
				continue;
			$ip = trim ($parts[0]);
			$mac = trim ($parts[2]);
			$newItem = ['ip' => $ip, 'mac' => $mac, 'd' => 0];
			$result[] = $newItem;
		}

		return $result;
	}

	public function saveUploadInfo ($type, $data)
	{
		$dir = '/var/lib/shipard-node/upload/lan';
		if (!is_dir($dir))
		{
			mkdir($dir, 0770, TRUE);
		}

		$settingsFileName = $dir.'/.settings';
		if (!is_file($settingsFileName))
		{
			$settingsData = ['dsUrl' => $this->app->serverCfg['dsUrl'], 'table' => 'mac.lan.lans'];
			file_put_contents($settingsFileName, json_encode($settingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		$uploadString = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$uploadFileName = time().'-'.$type.'-'.mt_rand(1000000,9999999).'-'.md5($uploadString).'.json';
		file_put_contents($dir.'/'.$uploadFileName, $uploadString);
	}
}
