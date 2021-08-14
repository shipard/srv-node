<?php

namespace Shipard\lanControl\devices;


/**
 * Class Passive
 */
class Passive extends \Shipard\lanControl\devices\LanControlDeviceCore
{
	function shellCommand($commandType)
	{
		$cmd = 'echo "-"';
		$cmd .= ' >'.$this->cmdResultFileName;
		$cmd .= ' 2>'.$this->cmdErrorsFileName;

		return $cmd;
	}
}

