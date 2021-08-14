<?php

namespace Shipard\lan;


/**
 * Class LanSearchARP
 */
class LanSearchARP extends Lan
{
	protected function searchLocal ()
	{
		$cmd = '/usr/sbin/arp -n';
		$result = shell_exec($cmd);
		$data = $this->parseArp($result);

		foreach ($data as $addr)
		{
			$this->storage->setField (Lan::icIP, $addr['ip'], 'mac', $addr['mac']);
			$this->storage->setField (Lan::icIP, $addr['ip'], 'ip', $addr['ip']);
		}
		//print_r($data);
	}

	public function search ()
	{
		$this->searchLocal();
	}
}
