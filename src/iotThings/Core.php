<?php

namespace Shipard\iotThings;


/**
 * Class Core
 */
class Core
{
	var $app;
	var $curl = NULL;

	var $cmdTopic = '';
	var $cmdPayload = '';

	var $iotControl = '';
	var $thingId = '';
	var $thingAction = '';
	var $thingCfg = NULL;
	var $valueCfg = NULL;

	function setCmdParams($topic, $payload)
	{
		$this->cmdTopic = $topic;
		$this->cmdPayload = $payload;

		$this->detectThing();
	}

	function setActionParams($iotControl, $thingId, $thingAction)
	{
		$this->iotControl = $iotControl;
		$this->thingId = $thingId;
		$this->thingAction = $thingAction;

		$this->detectThing();
	}

	function detectThing()
	{
		if ($this->thingId !== '')
		{
			if (!isset($this->app->mqttEngineCfg['things'][$this->thingId]))
				return;
			$this->thingCfg = $this->app->mqttEngineCfg['things'][$this->thingId];
			return;
		}

		foreach ($this->app->mqttEngineCfg['things'] as $thingId => $thingCfg)
		{
			if (isset($thingCfg['items']['values'][$this->cmdTopic]))
			{
				$this->thingId = $thingId;
				$this->thingCfg = $thingCfg;
				$this->valueCfg = $thingCfg['items']['values'][$this->cmdTopic];
				return;
			}
		}
	}

	function apiCall ($url, $data)
	{
		if (!$this->curl)
		{
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
				'Connection: Keep-Alive',
				'Keep-Alive: 300',
				'e10-api-key: ' . $this->app->serverCfg['apiKey'],
				'e10-device-id: ' . $this->app->machineDeviceId (),
			]);
			curl_setopt ($this->curl, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt ($this->curl, CURLOPT_POST, true);
		}

		curl_setopt ($this->curl, CURLOPT_URL, $url);
		curl_setopt ($this->curl, CURLOPT_POSTFIELDS, json_encode($data));
		$resultCode = curl_exec ($this->curl);
		$resultData = json_decode ($resultCode, TRUE);

		return $resultData;
	}

	function sendMqttMessage($topic, $payload)
	{
		$mqttHost = '127.0.0.1';
		$cmd = 'mosquitto_pub -h '.$mqttHost.' -t "'.$topic.'" -m \''.$payload.'\'';
		//echo $cmd."\n";
		passthru($cmd);
	}

	public function run()
	{
	}

	function doAction()
	{
	}

	function doValue()
	{
	}
}
