#!/usr/bin/env node

var mqtt = require('mqtt')
		, exec = require('child_process').exec
		, fs = require('fs')
		, crypto = require('crypto')
		, log4js = require('log4js')
		, logger = log4js.getLogger()
		, dgram = require('dgram')
		, net = require('net');


let mqtt_url = 'mqtt://localhost:1883';
let auth = (mqtt_url.auth || ':').split(':');
let configuration = {};
let topics = [];

let ibInfoTopic = 'shp/iot-boxes-info';
topics.push(ibInfoTopic);

let sensorsTopic = 'shp/sensors/';
topics.push(sensorsTopic + '#');


// -- Load config
let configFile = '/etc/shipard-node/mqtt-engine.json';
configuration = JSON.parse(fs.readFileSync(configFile).toString());

for(var key in configuration['engineCfg']['valuesTopics'])
{
	let topic = key;
	topics.push(key);
}

// -- Create MQTT Client
let options = {
	port: mqtt_url.port,
	host: mqtt_url.hostname,
	username: auth[0],
	password: auth[1]
};

let c = mqtt.connect(options);
c.on('connect', function() {
	c.subscribe(topics);
	c.on('message', function(topic, message) {
		topic = topic.toString().replace(/"/g, "\\\"");

		let payload = message.toString().replace(/"/g, "\\\"");

		if (topic === ibInfoTopic) {
			doIbInfo(topic, message);
			return;
		}

		if (topic.startsWith(sensorsTopic))
			doSensor(topic, payload);

		doValue(topic, payload);
	});
});

function puts(error, stdout, stderr)
{
	//console.log(stdout);
}

function doIbInfo(topic, message)
{
	let payload = message.toString().replace(/\\\\"/g, "\\\"");
	let payloadData = JSON.parse(payload);

	if (payloadData == null)
	{
		console.log("IOT-BOX-INFO parse data ERROR!");
		return;
	}

	if (payloadData['type'] === 'system')
	{
		let ibInfoData = {
			'type': 'e10-nl-snmp',
			'data': {
				'type': payloadData['type'],
				'device': payloadData['device'],
				'datetime': new Date().toISOString().replace(/T/, ' ').replace(/\..+/, ''),
				'checksum': '',
				'items': payloadData['items']
			}
		};
		let ibDataStr = JSON.stringify(ibInfoData);

		let now = new Date().getTime();
		let fileName = '/var/lib/shipard-node/upload/lan/iot-box-'+payloadData['device']+'-'+payloadData['type']+'-'+now+'.json';

		fs.writeFile(fileName, ibDataStr, function (err) {
			if (err)
				console.log(err);
		});

		let iotBoxInfoDataStr = JSON.stringify(payloadData);
		fileName = '/var/lib/shipard-node/tmp/lan-device-'+payloadData['device']+'-iotBoxInfo.json';
		fs.writeFile(fileName, iotBoxInfoDataStr, function (err) {
			if (err)
				console.log(err);
		});
		let cmd = 'shipard-node iot-box-info --file='+fileName;
		//console.log(cmd);
		exec(cmd, puts);
	}
}

function doSensor(topic, payload)
{
	let safePayload = payload.replace(/[^\x19-\x7F]/g,"").replace(/\u0000/g, '\\0');
	//console.log("SENSOR: "+topic+': '+safePayload);

	let topicIds = topic.substr(sensorsTopic.length);
	topicIds = topicIds.replace('/', '.');

	let now = new Date().getTime();

	let sensorInfoData = {
		'topic': topic,
		'value': safePayload,
		'time': now,
	};
	let sensorInfoDataStr = JSON.stringify(sensorInfoData);

	let hash = crypto.createHash('md5').update(topic).digest("hex");

	let fileName = '/var/lib/shipard-node/upload/sensors/sensors-'+now+'-'+'-'+hash+'.json';

	fs.writeFile(fileName, sensorInfoDataStr, function (err) {
		if (err)
			console.log(err);
	});

	/* statd:
	let value = parseFloat(safePayload);
	let data = '';
	if (value < 0)
		data += 'sensors.'+topicIds + ': 0|g\n';
	data += 'sensors.'+topicIds + ': '+value+'|g\n';

	let client = dgram.createSocket('udp4');
	client.send(data, 8125, '127.0.0.1', () => {
		client.close();
	});
	*/
}

function doValue(topic, payload)
{
	// -- search thing
	for(let thingId in configuration['things'])
	{
		let thingCfg = configuration['things'][thingId];
		for (let valueTopic in thingCfg['items']['values'])
		{
			//console.log("search thing topic: "+valueTopic);
			if (valueTopic === topic)
			{
				let safePayload = payload.replace(/[^\x19-\x7F]/g,"").replace(/\u0000/g, '\\0');

				let cmd = '/usr/lib/shipard-node/tools/shn-iot-thing.php value';
				cmd += ' --type="' + thingCfg['coreType'] + '"';
				cmd += ' --topic="'+valueTopic+'"';
				cmd += ' --payload="'+safePayload+'"';
				//console.log("RUN CMD: " +cmd);
				exec(cmd, puts);

				return;
			}
		}
	}
}
