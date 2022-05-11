#!/usr/bin/env node

var mqtt = require('mqtt')
		, exec = require('child_process').exec
		, fs = require('fs')
		, https = require('https')
		, crypto = require('crypto')
		, log4js = require('log4js')
		, logger = log4js.getLogger()
		, dgram = require('dgram')
		, net = require('net');

let mqtt_url = 'mqtt://localhost:1883';
let auth = (mqtt_url.auth || ':').split(':');
let configuration = {};
let serverConfiguration = {};
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
serverConfiguration = JSON.parse(fs.readFileSync('/etc/shipard-node/server.json').toString());
serverDeviceId = fs.readFileSync('/etc/e10-device-id.cfg').toString();

for(var key in configuration['listenTopics'])
{
	let topic = configuration['listenTopics'][key];
	topics.push(topic);
}


let iotEngineInfo = {};
loadInfo();


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

		if (configuration['eventsOn'] !== undefined && topic in configuration['eventsOn'])
			doEventOn(topic, message.toString());

		if (topic.startsWith('shp/setups/'))
		{
			doSetup(topic, message.toString());
			return;
		}	

		const topicCfg = configuration['topics'][topic];
		if (topicCfg !== undefined)
		{
			if (topicCfg['type'] === 'device')
				doDevice(topic, message.toString());

			if (configuration['onSensors'] !== undefined && topic in configuration['onSensors'])
				onSensors(topic, message.toString());
		}
		else{
			if (configuration['eventsOn'] !== undefined && !(topic in configuration['eventsOn']))
				console.log ("UNREGISTERED TOPIC `"+topic+"`: "+message.toString());
		}
	});

	eventLoop();
});

function puts(error, stdout, stderr)
{
	//console.log(stdout);
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

		fs.writeFileSync(fileName, ibDataStr, function (err) {
			if (err)
				console.log(err);
		});

		let iotBoxInfoDataStr = JSON.stringify(payloadData);
		fileName = '/var/lib/shipard-node/tmp/lan-device-'+payloadData['device']+'-iotBoxInfo.json';
		fs.writeFileSync(fileName, iotBoxInfoDataStr, function (err) {
			if (err)
				console.log(err);
		});
		let cmd = 'shipard-node iot-box-info --file='+fileName;
		//console.log(cmd);
		exec(cmd, puts);
	}
}

function doSetup(topic, payload)
{
	let setupId = topic;
	let operation = '';
	if (configuration['topics'][setupId] === undefined)
	{
		//console.log ("invalid setup 1: `"+setupId+'`');
		let parts = topic.split('/');
		operation = parts.pop();
		setupId = parts.join('/');

		if (configuration['topics'][setupId] === undefined)
		{
			//console.log ("invalid setup 2: `"+setupId+'`');
			return;
		}

		if (operation !== 'set' && operation !== 'get')
		{
			console.log ("invalid setup operation");
			return;
		}
	}

	if (operation === '')
	{
		return;
	}
	
	//console.log ("SETUP: "+setupId+", operation: `"+operation+"`");

	let payloadData = JSON.parse(payload);
	if (operation === 'set')
	{
		doSetupSet(setupId, payloadData);
		
		return;
	}
	else if (operation === 'get')
	{
		doSetupGet(setupId);
	}
}

function doSetupSet(setupId, payloadData)
{
	const setupCfg = configuration['topics'][setupId];
	if (setupCfg === undefined)
	{
		console.log("Unknown setupId "+setupId);
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

		setScene(setupId, sceneId);
	}
}

function setScene(setupId, sceneId)
{
	let setupInfo = getInfo(setupId);
	setupInfo['scene'] = sceneId;
	setInfo(setupId, setupInfo);
	
	doSetupGet(setupId);
	
	//console.log("SET SCENE "+sceneId);
	const sceneCfg = configuration['topics'][sceneId];
	runDoEvents(sceneCfg['do']);

	const setupCfg = configuration['topics'][setupId];

	sendToServer({'serverId': serverConfiguration.serverId, 'type': 'set-scene', 'setup': setupCfg.ndx, 'scene': sceneCfg.ndx});
}

function doSetupGet(setupId)
{
	const setupInfo = getInfo(setupId);
	mqttClient.publish (setupId, JSON.stringify(setupInfo), { /*qos: 0, retain: false*/ }, (error) => {
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

	fs.writeFileSync(fileName, sensorInfoDataStr, function (err) {
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
		fs.writeFileSync(uploadFileName, uploadString, function (err) {
			if (err)
				console.log(err);
		});

		return;
	}
}

async function doEventOn(topic, payload)
{
	let event = configuration['eventsOn'][topic];
	
	if (event['scene'] !== undefined)
	{
		//console.log ("!!! SCENE !!!");
		const setupInfo = getInfo(event['setup']);
		//console.log("setupInfo: ", setupInfo);

		if (setupInfo['scene'] === undefined)
		{
			//console.log("Unknown place on setup "+event['setup']);
			return;
		}
	
		if (setupInfo['scene'] !== event['scene'])
		{
			//console.log("not valid on this scene");
			return;
		}
	}
	
	let payloadData = {};
	try {
		payloadData = JSON.parse(payload);
	}	catch (e) {  
		payloadData = {'_payload': payload};
		console.log('invalid json');  
	}
	if (payloadData['action_group'] !== undefined)
		return;

	checkStopLoop (topic, payloadData);
	//console.log ("INCOMING: "+topic+" ---> "+payload);
	for(let onItemId in event['on'])
	{
		let onItem = event['on'][onItemId];

		if (onItem['type'] === 0 || onItem['type'] === 4)
		{
			if (onItem['dataItem'] !== undefined && onItem['dataItem'] in payloadData)
			{
				if (payloadData[onItem['dataItem']] == onItem['dataValue'])
				{
					runDoEvents(onItem['do'], topic, payloadData);
				}
			}
		}
		else
		{
			runDoEvents(onItem['do'], topic, payloadData);
		}
	}
}

function doDevice(topic, payload)
{
	if (configuration['eventsOn'] === undefined || configuration['eventsOn'][topic] === undefined)
		return;
	let event = configuration['eventsOn'][topic];
	let payloadData = JSON.parse(payload);
	if (payloadData['action_group'] !== undefined)
		return;
	setInfo(topic, payloadData);
}

function runDoEvents(doEvents, srcTopic, srcPayload)
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
		else if (doItemId === 'sendSetupRequest')
		{
			//console.log('sendSetupRequest: ', doItem);
			for(let setupId in doItem)
			{
				let doActionItem = doItem[setupId];
				//console.log('sendSetupRequestAction: ', setupId, doActionItem);
				doSetupActions(setupId, doActionItem['actions'], srcTopic, srcPayload);
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

function doSetupActions(setupId, actions, srcTopic, srcPayload)
{
	const setupCfg = configuration['topics'][setupId];
	//console.log("SETUP: ", setupId, setupCfg);
	for(let actionId in actions)
	{
		let actionItem = actions[actionId];
		//console.log('  --: ', actionItem, srcPayload);

		let requestData = {'setup': setupCfg.ndx, 'request': actionItem.request, 'srcPayload': srcPayload, 'srcTopic': srcTopic};
		if (srcTopic !== undefined && configuration['topics'][srcTopic] !== undefined)
		{
			requestData['srcTopicInfo'] = configuration['topics'][srcTopic];
		}
		doSetupRequest(setupId, setupCfg, requestData);
	}
}

function doSetupRequest(setupId, setupCfg, requestData)
{
	//console.log(requestData);
	const data = JSON.stringify(requestData);
	const apiUrl = serverConfiguration.dsUrl + 'api/objects/call/iot-mac-setup-request';

	const options = {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'Content-Length': data.length,
			'e10-api-key': serverConfiguration.apiKey,
			'e10-device-id': serverDeviceId
		}
	};

	const req = https.request(apiUrl, options, res => {
		//console.log('statusCode: ', res.statusCode);
		//console.log('headers: ', res.headers);

		let data = '';

		res.on('data', d => {
			data += d;
		});

		res.on('end', () => {
			//console.log('Response from ', setupId);
			//console.log(data);
			const parsedData = JSON.parse(data);
			if (parsedData['callActions'] !== undefined)
			{
				for(let actionId in parsedData['callActions'])
				{
					const actionItem = parsedData['callActions'][actionId];
					//console.log ("   -> PUBLISH: ", actionItem['topic'], JSON.stringify(actionItem['payload']));
					mqttClient.publish (actionItem['topic'], JSON.stringify(actionItem['payload']), { qos: 0, retain: false }, (error) => {
						if (error) {
							console.error(error)
						}
					});		
				}
			}		
		});
	});
	
	req.on('error', error => {
		console.error(error);
	})
	
	req.write(data);
	req.end();
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

function sendToServer(dataStruct)
{
	data = JSON.stringify(dataStruct);
	const options = {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'Content-Length': data.length,
			'e10-api-key': serverConfiguration.apiKey,
			'e10-device-id': serverDeviceId
		}
	};

	const apiUrl = serverConfiguration.dsUrl + 'api/objects/call/iot-mac-set-state';
	const req = https.request(apiUrl, options, res => {
		//console.log('statusCode: ', res.statusCode);
		//console.log('headers: ', res.headers);

		let data = '';

		res.on('data', d => {
			data += d;
		});

		res.on('end', () => {
			//console.log(data);
		});
	});
	
	req.on('error', error => {
		console.error(error);
	})
	
	req.write(data);
	req.end();
}

function saveInfo()
{
	const fileName = '/var/lib/shipard-node/tmp/mqtt-engine-info.json';
	
	const data = JSON.stringify(iotEngineInfo);
	//console.log(data);
	fs.writeFileSync(fileName, data, function (err) {
		if (err)
			console.log(err);
	});
}

function loadInfo()
{
	const fileName = '/var/lib/shipard-node/tmp/mqtt-engine-info.json';
	try {
		const fileContent = fs.readFileSync(fileName).toString();
		iotEngineInfo = JSON.parse(fileContent);
	}	
	catch (err) {
		//console.error(err)
		iotEngineInfo = {};
	}
}

process.on('SIGINT', () => {
	saveInfo();
  //console.log("### SIGINT ###");
	process.exit();
});

process.on('SIGTERM', () => {
	saveInfo();
  //console.log("### SIGTERM ###");
	process.exit();
});
