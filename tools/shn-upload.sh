#!/bin/sh

/usr/lib/shipard-node/tools/shn-upload.php &
echo $! > /var/run/shn-upload.pid
exit 0
