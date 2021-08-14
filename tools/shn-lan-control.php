#!/usr/bin/env php
<?php


while (1)
{
	if (is_readable('/var/lib/shipard-node/tmp/lanControlChangedDevices'))
	{
		unlink('/var/lib/shipard-node/tmp/lanControlChangedDevices');
		exec('/usr/lib/shipard-node/tools/shipard-node.php lan-control-get');
		exec('/usr/lib/shipard-node/tools/shipard-node.php lan-control-requests');
	}

	sleep (30);
}

