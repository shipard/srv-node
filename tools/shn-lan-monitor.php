#!/usr/bin/env php
<?php

sleep(45);

while (1)
{
	$timeStart = time();
	$timeBreak = $timeStart + 60;
	exec ('/usr/lib/shipard-node/tools/shipard-node.php lan-monitor');
	while (time() < $timeBreak)
		sleep (1);
}

