<?php

namespace Shipard\iotThings;


/**
 * Class Gate
 */
class Gate extends \Shipard\iotThings\Core
{
	var $valueType = '';

	public function run()
	{
		$this->valueType = $this->valueCfg['type'];
		$this->doValue();
	}

	function doAction()
	{
		$requestData = ['type' => 'control', 'gate' => $this->thingCfg['thingNdx'], 'iotControl' => $this->iotControl];
		$url = $this->app->serverCfg['dsUrl'] . 'api/objects/call/mac-access-gate-check';
		$test = $this->apiCall ($url, $requestData);
		if (!$test || !isset($test['success']) || !$test['success'])
		{
			return;
		}

		if ($this->thingAction === 'open-once')
			$this->doActionOpenOnce('both');
		elseif ($this->thingAction === 'open-once-in')
			$this->doActionOpenOnce('in');
		elseif ($this->thingAction === 'open-once-out')
			$this->doActionOpenOnce('out');
	}

	function doActionOpenOnce($dir)
	{
		$openItem = $this->searchOpenItem($dir);
		if (!$openItem)
		{
			//echo "FAIL\n";
			echo "openItem not found\n";
			return;
		}

		$payload = "P10000";
		if (isset($this->thingCfg['typeId']) && $this->thingCfg['typeId'] === 'gate')
			$payload = "P15000";

		$this->sendMqttMessage($openItem['topic'], $payload);

		$this->doItems($dir, 'signal-success', 'FD10000:400:200:200:200');
		$this->doItems($dir, 'signal-status', 'larson-scanner:900:00A000:0');
		sleep(10);
		$this->doItems($dir, 'signal-status', 'scan:4000:101010:0');
	}

	function doValue()
	{
		if ($this->valueType === 'gate-accessCode-in' || $this->valueType === 'gate-accessCode-out' || $this->valueType === 'gate-vehicleDetect-in' || $this->valueType === 'gate-vehicleDetect-out')
		{
			$this->doItems($this->valueCfg['dir'], 'signal-status', 'scan:4000:003030:0');

			// -- check
			if ($this->cmdPayload[0] === '{')
			{
				$payloadData = json_decode($this->cmdPayload, TRUE);
				if ($payloadData)
				{
					$requestData = [];
					if (isset($payloadData['action']))
					{
						switch ($payloadData['action'])
						{
							case 'call':
										$requestData = ['type' => 'call', 'gate' => $this->thingCfg['thingNdx'], 'value' => $payloadData['number']];
										break;
							case 'vd':
										$requestData = ['type' => 'vd', 'gate' => $this->thingCfg['thingNdx'], 'cam' => $payloadData['cam'], 'value' => $payloadData['lp']];
										break;
						}
					}
				}
			}
			else
			{
				$requestData = ['type' => 'rfid', 'gate' => $this->thingCfg['thingNdx'], 'value' => $this->cmdPayload];
			}

			$url = $this->app->serverCfg['dsUrl'] . 'api/objects/call/mac-access-gate-check';
			$test = $this->apiCall ($url, $requestData);
			if (!$test || !isset($test['success']) || !$test['success'])
			{
				//echo "FAIL\n";
				$this->doItems($this->valueCfg['dir'], 'signal-status', 'blink:400:FF0000:0');
				$this->doItems($this->valueCfg['dir'], 'signal-fail', 'FD3000:100:100');
				sleep(3);
				$this->doItems($this->valueCfg['dir'], 'signal-status', 'scan:4000:101010:0');
				return;
			}

			$openItem = $this->searchOpenItem($this->valueCfg['dir']);
			if (!$openItem)
			{
				//echo "FAIL\n";
				//echo "openItem not found\n";
				//return;
			}

			$payload = "P10000";
			if (isset($this->thingCfg['typeId']) && $this->thingCfg['typeId'] === 'gate')
				$payload = "P15000";

			$this->sendMqttMessage($openItem['topic'], $payload);

			$this->doItems($this->valueCfg['dir'], 'signal-success', 'FD10000:400:200:200:200');
			$this->doItems($this->valueCfg['dir'], 'signal-status', 'larson-scanner:900:00A000:0');
			sleep(10);
			$this->doItems($this->valueCfg['dir'], 'signal-status', 'scan:4000:101010:0');
			//$this->doItems($this->valueCfg['dir'], 'gate-status-leds', 'FD10000:400:200:200:200');

			return;
		}

		if ($this->valueType === 'gate-motion-detect-in' || $this->valueType === 'gate-motion-detect-out')
		{
			if ($this->cmdPayload === '1')
				$this->doItems($this->valueCfg['dir'], 'signal-status', 'dual-scan:2500:A0A0A0:0:0');
			else
				$this->doItems($this->valueCfg['dir'], 'signal-status', 'scan:4000:101010:0:0');
		}
	}

	function searchOpenItem($dir)
	{
		foreach ($this->thingCfg['items']['open'] as $item)
		{
			if ($item['dir'] !== $dir && $item['dir'] !== 'both')
				continue;

			return $item;
		}

		return NULL;
	}

	function doItems($dir, $role, $payload)
	{
		if (!isset($this->thingCfg['items'][$role]))
			return;

		foreach ($this->thingCfg['items'][$role] as $item)
		{
			if ($item['dir'] !== $dir && $item['dir'] !== 'both')
				continue;
			$this->sendMqttMessage($item['topic'], $payload);
		}
	}
}
