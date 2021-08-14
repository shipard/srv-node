#!/usr/bin/env node

const onvif = require('node-onvif')
		, process = require('process')
		, fs = require('fs');


// -- Load config
let configFile = '/etc/shipard-node/config.json';
configuration = JSON.parse(fs.readFileSync(configFile).toString());




var args = process.argv.splice(2);

let cmd = args[0];
if (cmd === undefined)
{
	console.error('ERROR: cmd not found');
	process.exit(1);
}

let camNdx = args[1];
if (camNdx === undefined)
{
	console.error('ERROR: camNdx not found');
	process.exit(1);
}


let camCfg = configuration.cfg.cameras[camNdx];
if (camCfg === undefined)
{
	console.error('ERROR: cam `'+camNdx+'` not found');
	process.exit(1);
}

let camLogin = camCfg['cfg']['camLogin'];
let camPasswd = camCfg['cfg']['camPasswd'];
let camIPAddress = camCfg['ip'];



// Create an OnvifDevice object
let device = new onvif.OnvifDevice({
	xaddr: 'http://'+camIPAddress+':80/onvif/device_service',
	user : camLogin,
	pass : camPasswd
});



// Initialize the OnvifDevice object
device.init().then((info) => {

	if (cmd === 'saveCameraInfo')
	{
		saveCameraInfo(info);
	}
	else
		console.log(JSON.stringify(info, null, '  '));

}).catch((error) => {
	console.error(error);
	process.exit(1);
});


function saveCameraInfo(info)
{
	let cameraInfoData = {
		'type': 'e10-nl-snmp',
		'data': {
			'type': 'system',
			'device': camNdx,
			'datetime': new Date().toISOString().replace(/T/, ' ').replace(/\..+/, ''),
			'checksum': '',
			'items': {
				'device-mnf': info['Manufacturer'],
				'device-type': info['Model'],
				'version-fw': info['FirmwareVersion'],
				'device-sn': info['SerialNumber'],
				'device-hwid': info['HardwareId'],
			}
		}
	};
	let cameraInfoDataStr = JSON.stringify(cameraInfoData);

	let now = new Date().getTime();
	let fileName = '/var/lib/shipard-node/upload/lan/camera-'+camNdx+'-'+'systemInfo'+'-'+now+'.json';

	fs.writeFile(fileName, cameraInfoDataStr, function (err) {
		if (err)
			console.log(err);
	});

	let agentInfo = {
		'osType': 'ipcams',
		'osValues': {
			'_saOS': 'ipcams',
			'_saInfo': 'os',
			'osName': info['Manufacturer']+'-'+info['Model'],
			'version-os': info['FirmwareVersion'],
			'device-sn': info['SerialNumber'],
			'device-type': info['Model']
		}
	};
	let agentInfoDataStr = JSON.stringify(agentInfo);

	fileName = '/var/lib/shipard-node/tmp/lan-device-'+camNdx+'-agentInfo.json';
	fs.writeFile(fileName, agentInfoDataStr, function (err) {
		if (err)
			console.log(err);
	});
}

