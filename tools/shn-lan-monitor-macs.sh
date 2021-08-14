#!/bin/sh

/usr/lib/shipard-node/tools/shn-lan-monitor-macs.php &
echo $! > /var/run/shn-lan-monitor-macs.pid
exit 0
