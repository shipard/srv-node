#!/bin/sh

/usr/lib/shipard-node/src/iotBox/devices/services/ib-uart/ib-uart.py &
echo $! > /run/shn-ib-uart.pid
exit 0
