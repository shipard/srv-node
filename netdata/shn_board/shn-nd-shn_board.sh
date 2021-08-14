#!/usr/bin/env sh
### version 0.2

declare -i boardTemp=0

while :
do
  if [ -d "/etc/armbianmonitor" ]; then
    boardTemp=$(head -n 1 /etc/armbianmonitor/datasources/soctemp)
  else
    boardTemp=$(head -n 1 /sys/class/thermal/thermal_zone0/temp)
    if [ $boardTemp -lt 100 ]; then
      boardTemp=$((boardTemp * 1000))
    fi
  fi
    echo "shn_board.cputemp:$boardTemp|g"|nc -u -w1 localhost 8125
    sleep 9
done

exit 0
