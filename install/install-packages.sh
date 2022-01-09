#!/bin/sh

apt --assume-yes --quiet update
apt --assume-yes --quiet upgrade
apt install --assume-yes --quiet ca-certificates apt-transport-https software-properties-common

#
# detect OS
#
. /etc/os-release


#
# PHP 8
#
if [ "$NAME" = "Ubuntu" ]; then
    add-apt-repository --yes ppa:ondrej/php
    apt --assume-yes --quiet update
    apt --assume-yes --quiet upgrade
else
    sudo wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
    apt --assume-yes --quiet update
fi

apt install --assume-yes --quiet php8.1-cli php8.1-curl php8.1-intl php8.1-zip php8.1-bcmath php8.1-mbstring php8.1-snmp php8.1-inotify

#####

apt install --assume-yes --quiet net-tools fping mosquitto-clients

### redis
apt install --assume-yes --quiet redis php8.1-redis


