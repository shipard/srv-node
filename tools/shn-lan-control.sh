#!/bin/sh

/usr/lib/shipard-node/tools/shn-lan-control.php &
echo $! > /var/run/shn-lan-control.pid
exit 0
