#!/bin/sh

/usr/lib/shipard-node/tools/shn-lan-monitor.php &
echo $! > /var/run/shn-lan-monitor.pid
exit 0
