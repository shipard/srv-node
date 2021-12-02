#!/usr/bin/env node

var mqtt = require('mqtt')
		, exec = require('child_process').exec
		, fs = require('fs')
		, redis = require("redis")
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

let topicZigbee = 'zigbee2mqtt/bridge/devices';
topics.push(topicZigbee);

// -- Load config
let configFile = '/etc/shipard-node/mqtt-engine.json';
configuration = JSON.parse(fs.readFileSync(configFile).toString());

for(var key in configuration['listenTopics'])
{
	let topic = configuration['listenTopics'][key];
	topics.push(topic);
}


let iotEngineInfo = {};

//const redisClient = redis.createClient();



// -- Create MQTT Client
let options = {
	port: mqtt_url.port,
	host: mqtt_url.hostname,
	username: auth[0],
	password: auth[1]
};

let mqttClient = mqtt.connect(options);

mqttClient.on('connect', function() {
	mqttClient.subscribe(topics);
	mqttClient.on('message', function(topic, message) {
		topic = topic.toString().replace(/"/g, "\\\"");

		let payload = message.toString().replace(/"/g, "\\\"");

		if (topic === ibInfoTopic) {
			doIbInfo(topic, message);
			return;
		}

		if (topic === topicZigbee)
		{
			doZigbee(topic, message.toString());
			return;
		}	

		if (topic.startsWith(sensorsTopic))
		{
			doSensor(topic, payload);
			return;
		}	

		if (topic in configuration['eventsOn'])
			doEventOn(topic, message.toString());

		if (topic.startsWith('shp/places/'))
		{
			doPlace(topic, message.toString());
			return;
		}	

		const topicCfg = configuration['topics'][topic];
		if (topicCfg !== undefined)
		{
			if (topicCfg['type'] === 'device')
				doDevice(topic, message.toString());

			if (topic in configuration['onSensors'])
				onSensors(topic, message.toString());
		}
		else{
			console.log ("UNREGISTERED TOPIC `"+topic+"`: "+message.toString());
		}
	});

	eventLoop();
});

function puts(error, stdout, stderr)
{
	console.log(stdout);
}


let loops = {};
let stopTopics = {};

function eventLoop()
{
	//console.log("loop");
	
	for(let loopId in loops)
	{
		let loop = loops[loopId];
	//	console.log("LOOP: "+loopId);
		runEventLoopItem(loop);
	}
	
	setTimeout (function () {eventLoop()}, 250);	
}

function setInfo (topic, data)
{
	iotEngineInfo[topic] = data;
}

function getInfo (topic)
{
	if (iotEngineInfo[topic] === undefined)
		iotEngineInfo[topic] = {};
	
	return iotEngineInfo[topic];
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

function doPlace(topic, payload)
{
	let placeId = topic;
	let operation = '';
	if (configuration['topics'][placeId] === undefined)
	{
		let parts = topic.split('/');
		operation = parts.pop();
		placeId = parts.join('/');

		if (configuration['topics'][placeId] === undefined)
		{
			console.log ("invalid place");
			return;
		}

		if (operation !== 'set' && operation !== 'get')
		{
			console.log ("invalid place operation");
			return;
		}
	}

	if (operation === '')
	{
		return;
	}
	
	console.log ("PLACE: "+placeId+", operation: `"+operation+"`");

	let payloadData = JSON.parse(payload);
	if (operation === 'set')
	{
		doPlaceSet(placeId, payloadData);
		return;
	}
	else if (operation === 'get')
	{
		doPlaceGet(placeId);
	}
}

function doPlaceSet(placeId, payloadData)
{
	const placeCfg = configuration['topics'][placeId];
	if (placeCfg === undefined)
	{
		console.log("Unknown placeId "+placeId);
		return;
	}

	if (payloadData['scene'] !== undefined)
	{
		const sceneId = payloadData['scene'];
		if (configuration['topics'][sceneId] === undefined)
		{
			console.log("unknown scene "+sceneId);
			return;
		}

		setScene(placeId, sceneId);
	}
}

function setScene(placeId, sceneId)
{
	let placeInfo = getInfo(placeId);
	placeInfo['scene'] = sceneId;
	setInfo(placeId, placeInfo);
	
	doPlaceGet(placeId);
	
	//console.log("SET SCENE "+sceneId);
	const sceneCfg = configuration['topics'][sceneId];
	runDoEvents(sceneCfg['do']);
}

function doPlaceGet(placeId)
{
	const placeInfo = getInfo(placeId);
	mqttClient.publish (placeId, JSON.stringify(placeInfo), { /*qos: 0, retain: false*/ }, (error) => {
		if (error) {
			console.error(error)
		}
	});
}

function onSensors(topic, payload)
{
	let payloadData = JSON.parse(payload);
	const onSensorsCfg = configuration['onSensors'][topic];
	if (onSensorsCfg === undefined || onSensorsCfg['dataItems'] === undefined)
		return;

	for(const key of onSensorsCfg['dataItems'])
	{
		if (payloadData[key] === undefined)
			continue;
		const sendTopic = 'shp/sensors/' + topic + '/' + key;
		const value = payloadData[key].toString();

		mqttClient.publish (sendTopic, value, { /*qos: 0, retain: false*/ }, (error) => {
			if (error) {
				console.error(error)
			}
		});
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

function doZigbee (topic, payload)
{
	let now = new Date().getTime();

	// -- UPLOAD
	let uploadString = null;
	let uploadFileName = '';
	if (topic === 'zigbee2mqtt/bridge/devices')
	{
		let payloadData = JSON.parse(payload);
		if (payloadData == null)
		{
			console.log("ZIGBEE-LOG parse data ERROR!");
			return;
		}
	
		let data = {
			'type': 'zigbee-devices-list',
			'topic': topic,
			'data': payloadData,
			'time': now,
		};
		let hash = crypto.createHash('md5').update(topic).digest("hex");
		uploadFileName = '/var/lib/shipard-node/upload/iot/zigbee-log-'+now+'-'+'-'+hash+'.json';
		uploadString = JSON.stringify(data);
	}

	if (uploadFileName !== '')
	{
		fs.writeFile(uploadFileName, uploadString, function (err) {
			if (err)
				console.log(err);
		});

		return;
	}
}

async function doEventOn(topic, payload)
{
	let event = configuration['eventsOn'][topic];
	let payloadData = JSON.parse(payload);
	
	if (event['scene'] !== undefined)
	{
		console.log ("!!! SCENE!!!");
		//const placeId = payloadData['place'];
		const placeInfo = getInfo(event['place']);
		console.log("placeInfo: ");
		console.log(placeInfo);
		if (placeInfo['scene'] === undefined)
		{
			console.log("Invalid place section "+event['place']);
			return;
		}
	
		if (placeInfo['scene'] !== event['scene'])
		{
			console.log("not valid on this scene");
			return;
		}
	}
	
	if (payloadData['action_group'] !== undefined)
		return;

	checkStopLoop (topic, payloadData);
	//console.log ("INCOMING: "+topic+" ---> "+payload);
	for(let onItemId in event['on'])
	{
		let onItem = event['on'][onItemId];

		if (onItem['dataItem'] !== undefined && onItem['dataItem'] in payloadData)
		{
			if (payloadData[onItem['dataItem']] == onItem['dataValue'])
			{
				runDoEvents(onItem['do']);
			}
		}
	}
}

function doDevice(topic, payload)
{
	let event = configuration['eventsOn'][topic];
	let payloadData = JSON.parse(payload);
	if (payloadData['action_group'] !== undefined)
		return;
	setInfo(topic, payloadData);
}

function runDoEvents(doEvents)
{
	for(let doItemId in doEvents)
	{
		let doItem = doEvents[doItemId];
		if (doItemId === 'setProperties')
		{
			for(let doPropertyItemId in doItem)
			{
				let doPropertiesItem = doItem[doPropertyItemId];
				let payload = null;
				if (doPropertiesItem['data'] !== undefined)
				{
					let pp = doPropertiesItem['data'];
					payload = JSON.stringify(pp);
				}	
				else
				if (doPropertiesItem['payload'] !== undefined)
					payload = doPropertiesItem['payload'];

				if (!payload)	
				{

					continue;
				}

				//console.log (" --> "+doPropertyItemId+" --> " + payload);
				//console.log(doPropertiesItem);
				mqttClient.publish (doPropertyItemId, payload, { /*qos: 0, retain: false*/ }, (error) => {
					if (error) {
						console.error(error)
					}
				});
			}
		}
		else if (doItemId === 'startLoop')
		{
			if (loops[doItem['id']] === undefined)
			{
				//console.log("START LOOP: "+doItem['id']);
				//if (loopCounters[doItem['id']] !== undefined)
					delete loopCounters[doItem['id']];

				loops[doItem['id']] = doItem;
			}	
		}
	}	
}

loopCounters = {};
function runEventLoopItem(loop)
{
	for(let lpid in loop['properties'])
	{
		let prop = loop['properties'][lpid];

		//console.log("!!! "+prop['deviceTopic']);
		//console.log(iotEngineInfo[prop['deviceTopic']]);

		if (iotEngineInfo[prop['deviceTopic']] === undefined)
			continue;
		let deviceInfo = iotEngineInfo[prop['deviceTopic']];
		if (deviceInfo[prop['property']] === undefined)
			continue;

		if (loopCounters[loop['id']] === undefined)	
			loopCounters[loop['id']] = {};
		if (loopCounters[loop['id']][lpid] === undefined)
		{
			loopCounters[loop['id']][lpid] = deviceInfo[prop['property']];
			//console.log("INIT LOOP COUNTER "+loop['id']+" /" + lpid + " TO "+loopCounters[loop['id']][lpid]);
		}	
		let currentValue = loopCounters[loop['id']][lpid];
		let newValue = -1;
		
		if (loop['op'] === '+')
		{
			newValue = currentValue + 20;
			if (newValue > prop['value-max'])
				newValue = prop['value-max'];
		}
		else	
		{
			newValue = currentValue - 20;
			if (newValue < prop['value-min'])
				newValue = prop['value-min'];

			if (newValue === 0)	
				newValue = 1;
		}
//		deviceInfo[prop['property']] = newValue;

		loopCounters[loop['id']][lpid] = newValue;

		//if (currentValue !== newValue)
		{
			let sendData = {};
			sendData[prop['property']] = newValue;
			//console.log("SET NEW VALUE ON "+prop['setTopic']+" --> "+prop['property']+" --> FROM: "+currentValue+" TO: "+newValue);
			//console.log(JSON.stringify(sendData));

			mqttClient.publish (prop['setTopic'], JSON.stringify(sendData), { qos: 0, retain: false }, (error) => {
				if (error) {
					console.error(error)
				}
			});
		}
	}
	//let deviceInfo = 
}

function checkStopLoop (topic, payload)
{
	let removeItem = '';
	for(let loopId in loops)
	{
		let loop = loops[loopId];
		if (topic === loop['stopTopic'] && payload[loop['stopProperty']] !== undefined && payload[loop['stopProperty']] === loop['stopPropertyValue'])
		{
			removeItem = loopId;
			break;
		}
	}

	if (removeItem !== '')
	{
		delete loops[removeItem];
		//console.log("STOP LOOP: "+removeItem);
	}
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
