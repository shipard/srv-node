#!/bin/sh

/usr/lib/shipard-node/tools/shn-incus-sync.php &
echo $! > /var/lib/shipard-node/run/shn-incus-sync.pid
exit 0
