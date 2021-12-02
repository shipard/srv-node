#!/bin/sh

/usr/lib/shipard-node/tools/shn-iot-box.php &
echo $! > /run/shn-iot-box.pid
exit 0
