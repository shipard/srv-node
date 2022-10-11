#!/usr/bin/env node

var exec = require('child_process').exec
		, fs = require('fs')
		, https = require('https')
;
const http = require("http");
const url = require("url");


//console.log("test1");

const configFile = '/etc/shipard-node/config.json';
let configuration = JSON.parse(fs.readFileSync(configFile).toString());

//console.log(configuration.cfg.formatVersion);

const camPictReceiverHost = '10.199.9.2';
const camPictReceiverPort = 8021;
let cntr = 1;

const camPictReceiverRequestListener = function (req, res) {
	//console.log("REQUEST: ", req);

	const q = url.parse(req.url, true);
	const urlPath = q.path.split('/');

	if (req.method === 'POST' && urlPath.length === 3 && urlPath[1] === 'camPictUpload')
	{
		const camId = urlPath[2];
		const camDir = '/var/lib/shipard-node/cameras/pictures/' + camId;

		if(!fs.existsSync(camDir)) {
			fs.mkdirSync(camDir, { recursive: true });
		}

		req.pipe(fs.createWriteStream(camDir + '/' + 'img-' + cntr + '.jpg'));
		cntr++;
	}
	else
	{
		res.writeHead(404);
		res.end("ERR");
		return;
	}

	res.writeHead(200);
	res.end("OK");
	//console.log("done");
};


const camPictReceiverServer = http.createServer(camPictReceiverRequestListener);
camPictReceiverServer.listen(camPictReceiverPort, camPictReceiverHost, () => {
    console.log(`Server is running on http://${camPictReceiverHost}:${camPictReceiverPort}`);
});


console.log("test2");

