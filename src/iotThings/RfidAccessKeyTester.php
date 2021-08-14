<?php

namespace Shipard\iotThings;


/**
 * Class RfidAccessKeyTester
 */
class RfidAccessKeyTester extends \Shipard\iotThings\Core
{
	var $valueType = '';

	public function run()
	{
		$this->valueType = $this->valueCfg['type'];
		$this->doValue();
	}

	function doValue()
	{
		if ($this->valueType === 'access-key')
		{
			$displayItem = $this->searchDisplayItem();
			if (!$displayItem)
			{
				//echo "displayItem not found\n";
				return;
			}

			$this->sendMqttMessage($displayItem['topic'], 'page:2');

			// -- check
			$requestData = ['value' => $this->cmdPayload];
			$url = $this->app->serverCfg['dsUrl'].'api/objects/call/mac-access-key-test';
			$test = $this->apiCall ($url, $requestData);
			//echo json_encode($test)."\n";

			$waitSecs = 10;
			if (!$test || !isset($test['success']) || !$test['success'])
			{
				$show = [
					'set' => [
						'page' => '4'
					]
				];
				$waitSecs = 15;
			}
			else
			{
				$i1value = isset($test['person']) ? $test['person'] : '???';
				$show = [
					'set' => [
						'page' => '3',
						'i1value.txt' => $i1value,
					]
				];

				if (isset($test['keys']))
				{
					$num = 2;
					foreach ($test['keys'] as $k)
					{
						$show['set']['i'.$num.'title.txt'] = $k['keyType'].':';
						$show['set']['i'.$num.'value.txt'] = $k['key'];
						$num++;
						if ($num === 4)
							break;
					}
				}
			}

			// -- pause reader
			$this->sendMqttMessage($this->cmdTopic.':pause', strval($waitSecs*1000));

			// -- display result
			$payload = json_encode($show, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$this->sendMqttMessage($displayItem['topic'].':set', $payload);

			sleep($waitSecs);
			$this->sendMqttMessage($displayItem['topic'].':set', 'page:1');
		}
	}

	function searchDisplayItem()
	{
		foreach ($this->thingCfg['items']['displays'] as $item)
		{
			return $item;
		}

		return NULL;
	}
}
