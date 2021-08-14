#!/bin/sh

/usr/lib/shipard-node/mqtt/shn-mqtt-engine.js &
echo $! > /var/run/shn-mqtt-engine.pid
exit 0
