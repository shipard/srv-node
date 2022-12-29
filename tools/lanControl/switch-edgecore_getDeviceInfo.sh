#!/usr/bin/expect -f
# version 0.1
set hostname [lindex $argv 0]
set username [lindex $argv 1]
set sshKey [lindex $argv 2]

set timeout 60
spawn ssh -l $username $hostname -F /var/lib/shipard-node/lc/ssh/config_switch-edgecore -oStrictHostKeyChecking=no -i /var/lib/shipard-node/lc/ssh/$sshKey
expect "*#"
send -- "terminal length 0\r"
expect "*#"
send -- "show version\r"
expect "*#"
send -- "show system\r"
expect "*#"
send -- "quit\r"
