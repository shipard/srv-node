#!/usr/bin/expect -f
set hostname [lindex $argv 0]
set username [lindex $argv 1]
set filename [lindex $argv 2]

set timeout 60
spawn ssh -l $username $hostname -F /var/lib/shipard-node/lc/ssh/config_switch-edgecore -oStrictHostKeyChecking=no -i /var/lib/shipard-node/lc/ssh/shn_ssh_key_dsa
expect "*#"
send -- "terminal length 0\r"
set handle [ open $filename r ]
	while { ! [eof $handle] } {
		gets $handle buf
		expect "*#"
		send "$buf\r"
	}
expect "*#"
send -- "copy running-config startup-config\r"
expect "*:"
send -- "\r"
expect "*#"
send -- "quit\r"
