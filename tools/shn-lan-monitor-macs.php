#!/usr/bin/env php
<?php


while (1)
{
	$timeStart = time();
	$timeBreak = $timeStart + 180;
	exec ('/usr/lib/shipard-node/tools/shipard-node.php lan-monitoring-macs');
	while (time() < $timeBreak)
		sleep (1);
}

