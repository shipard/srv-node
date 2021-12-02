console.log("hello, world...");

const rgmLoading = 0;
const rgmRacks = 0;

let rgMode = rgmLoading;

async function serverGet(subUrl)
{
	let fullUrl = 'https://shipard-tgm1281.shipard.pro/rg';

	fullUrl += '/localSensors';
/*
	var options = {
		type: 'GET',
		url: fullUrl,
		success: successFunction,
		//data: JSON.stringify(data),
		dataType: 'json',
		//error: (errorFunction != undefined) ? errorFunction : callWebActionFailed
	};
	$.ajax(options);
	*/

	const response = await fetch(fullUrl, {
    method: 'GET', // *GET, POST, PUT, DELETE, etc.
    mode: 'cors', // no-cors, *cors, same-origin
    cache: 'no-cache', // *default, no-cache, reload, force-cache, only-if-cached
    credentials: 'same-origin', // include, *same-origin, omit
    headers: {
      'Content-Type': 'application/json'
    },
    redirect: 'follow', // manual, *follow, error
    referrerPolicy: 'no-referrer', // no-referrer, *no-referrer-when-downgrade, origin, origin-when-cross-origin, same-origin, strict-origin, strict-origin-when-cross-origin, unsafe-url
   // body: JSON.stringify(data) // body data type must match "Content-Type" header
  });
  return response.json();
}


function updateClocks()
{
	var d = new Date();
	var s = d.getSeconds();
	var m = d.getMinutes();
	var h = d.getHours();

	$('#rg-status-clock-time').text(d.toLocaleTimeString('cs-CZ'));
	$('#rg-status-clock-date').text(d.toLocaleDateString('cs-CZ'));
}



function updateLocalSensors()
{
	serverGet('').then(data => {
    console.log(data); // JSON data parsed by `data.json()` call
		setSensorsValues(data);
  });
}

function setSensorsValues(data)
{
	if (rgMode === rgmLoading)
	{
		$('#rg-area-content-starting').hide();
		$('#rg-area-content-rack').show();
		rgMode = rgmRacks;
	}

	$('#rg-area-content-rack').text(JSON.stringify(data));
}


// -- init
$(function () {
	setInterval(updateClocks,1000);
	updateLocalSensors();

	setInterval(updateLocalSensors,10000);
});