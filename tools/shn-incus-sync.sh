#!/bin/sh

/usr/lib/shipard-node/tools/shn-incus-sync.php &
echo $! > /run/shn-incus-sync.pid
exit 0
