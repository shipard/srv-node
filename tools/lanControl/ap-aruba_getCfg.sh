#!/usr/bin/expect -f
set hostname [lindex $argv 0]
set username [lindex $argv 1]
set password [lindex $argv 2]

log_file -a results.log

set timeout 60
spawn ssh -l $username $hostname -F /var/lib/shipard-node/lc/ssh/config_switch-edgecore -oStrictHostKeyChecking=no

expect "*password:"
send -- "$password\r"
expect "*#"
send -- "show running-config\r"
expect "*#"
send -- "quit\r"
