#!/bin/bash

apt --assume-yes --quiet update
apt --assume-yes --quiet upgrade
apt install --assume-yes --quiet ca-certificates apt-transport-https software-properties-common

#
# convert semVer number to int
#
function versionToInt() {
  local IFS=.
  parts=($1)
  let val=1000000*parts[0]+1000*parts[1]+parts[2]
  echo $val
}

#
# detect OS
#
. /etc/os-release

#
# PHP 8
#
if [ "$NAME" = "Ubuntu" ]; then
    currentVersion=$(versionToInt $VERSION_ID)
    min81Version=$(versionToInt 22.04.0)

    add-apt-repository --yes ppa:ondrej/php
    apt --assume-yes --quiet update
    apt --assume-yes --quiet upgrade
else
    sudo wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
    apt --assume-yes --quiet update
fi

apt install --assume-yes --quiet php8.2-cli php8.2-curl php8.2-intl php8.2-zip php8.2-bcmath php8.2-mbstring php8.2-snmp php8.2-inotify

#####

apt install --assume-yes --quiet net-tools fping mosquitto-clients

### redis
apt install --assume-yes --quiet redis php8.2-redis


