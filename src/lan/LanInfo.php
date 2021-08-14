<?php

namespace Shipard\lan;


/**
 * Class LanInfo
 */
class LanInfo extends Lan
{
	public function status ()
	{
		$data = [];
		$t = time();
		$ips = $this->storage->getItems(Lan::icIP);
		foreach ($ips as $ip)
		{
			if (isset($ip['ip']))
			{
				$item = ['ip' => $ip['ip'], 'mac' => isset($ip['mac']) ? $ip['mac'] : '', 't' => $t, 'rts' => []];
				if (isset($this->app->nodeCfg['cfg']['lan']['ip'][ $ip['ip']]))
					$item['d'] = $this->app->nodeCfg['cfg']['lan']['ip'][ $ip['ip']]['d'];
				if (isset($this->app->nodeCfg['cfg']['lan']['ip'][ $ip['ip']]))
					$item['r'] = $this->app->nodeCfg['cfg']['lan']['ip'][ $ip['ip']]['r'];
				//else
				//if (isset($ip['r']))
				//	$item['r'] = $ip['r'];
				for ($i = 0; $i < Lan::rtCount; $i++)
				{
					$key = 'rt'.$i;
					$t = isset($ip[$key.'t']) ? intval($ip[$key.'t']) : 0;
					$v = isset($ip[$key.'v']) ? floatval($ip[$key.'v']) : -1;
					$up = ($v <= 0.0 || !$t) ? 0 : 1;

					$title = '';
					if ($t)
					{
						$ts = new \DateTime('@'.$t);
						$title .= $ts->format ('H.i:s').' - ';
					}
					if ($v >= 0)
						$title .= $v.'ms';
					else
						$title .= '---';

					$item['rts'][] = [
						'v' => isset($ip[$key.'v']) ? $ip[$key.'v'] : 0,
						't' => isset($ip[$key.'t']) ? $ip[$key.'t'] : -1,
						'up' => $up,
						'title' => $title
					];
				}

				$data[] = $item;
			}
		}

		return $data;
	}
}
