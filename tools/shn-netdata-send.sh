send_to_shipard(){
	alarmFileId=`date +"%Y-%m-%d_%H-%M-%S"`
	alarmFileName="/var/lib/shipard-node/tmp/netdata-alarm-${alarmFileId}-${event_id}.dat"

	echo "host: ${host}" >> ${alarmFileName}
	echo "url_host: ${url_host} " >> ${alarmFileName}
	echo "unique_id: ${unique_id} " >> ${alarmFileName}
	echo "alarm_id: ${alarm_id} " >> ${alarmFileName}
	echo "event_id: ${event_id} " >> ${alarmFileName}
	echo "when: ${when} " >> ${alarmFileName}
	echo "name: ${name} " >> ${alarmFileName}
	echo "url_name: ${url_name} " >> ${alarmFileName}
	echo "chart: ${chart} " >> ${alarmFileName}
	echo "url_chart: ${url_chart} " >> ${alarmFileName}
	echo "family: ${family} " >> ${alarmFileName}
	echo "url_family: ${url_family} " >> ${alarmFileName}
	echo "status: ${status} " >> ${alarmFileName}
	echo "old_status: ${old_status} " >> ${alarmFileName}
	echo "value: ${value} " >> ${alarmFileName}
	echo "old_value: ${old_value} " >> ${alarmFileName}
	echo "src: ${src} " >> ${alarmFileName}
	echo "duration: ${duration} " >> ${alarmFileName}
	echo "duration_txt: ${duration_txt} " >> ${alarmFileName}
	echo "non_clear_duration: ${non_clear_duration} " >> ${alarmFileName}
	echo "non_clear_duration_txt: ${non_clear_duration_txt} " >> ${alarmFileName}
	echo "units: ${units} " >> ${alarmFileName}
	echo "info: ${info} " >> ${alarmFileName}
	echo "value_string: ${value_string} " >> ${alarmFileName}
	echo "old_value_string: ${old_value_string} " >> ${alarmFileName}
	echo "image: ${image} " >> ${alarmFileName}
	echo "color: ${color} " >> ${alarmFileName}
	echo "goto_url: ${goto_url} " >> ${alarmFileName}
	echo "calc_expression: ${calc_expression} " >> ${alarmFileName}
	echo "calc_param_values: ${calc_param_values} " >> ${alarmFileName}
	echo "total_warnings: ${total_warnings} " >> ${alarmFileName}
	echo "total_critical: ${total_critical} " >> ${alarmFileName}
	echo "alarm: ${alarm} " >> ${alarmFileName}
	echo "status_message: ${status_message} " >> ${alarmFileName}
	echo "severity: ${severity} " >> ${alarmFileName}
	echo "raised_for: ${raised_for} " >> ${alarmFileName}

	# cat ${alarmFileName}

	shipard-node netdata-alarm --file=${alarmFileName}
}

