#!/bin/sh

/usr/lib/shipard-node/src/iotBox/devices/services/oli-mod-rfid1356/oli-mod-rfid1356.py &
echo $! > /run/shn-dev-oli-mod-rfid1356.pid
exit 0
