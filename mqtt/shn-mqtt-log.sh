#!/bin/sh

FNDATE=`date +%Y-%m-%d`

mosquitto_sub -t "shp/#" -F '@Y-@m-@d @H:@M:@S %t: %p' >> /var/lib/shipard-node/tmp/mqtt-log-$FNDATE &
echo $! > /var/run/shn-mqtt-log.pid
exit 0
