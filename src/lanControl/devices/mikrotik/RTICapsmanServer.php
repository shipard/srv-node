<?php

namespace Shipard\lanControl\devices\mikrotik;

use Shipard\Utility;

/**
 * class RTICapsmanServer
 */
class RTICapsmanServer extends Utility
{
  var $rtiData = [];
  var $cmsDevice = 0;

	public function doIt()
	{
		$allDevices = $this->app->lanControlDeviceCfg(NULL);
		if (!$allDevices)
			return;

		foreach ($allDevices as $deviceNdx => $deviceCfg)
		{
			if (!isset($deviceCfg['ipManagement']))
				continue;

			if (!isset($deviceCfg['macDeviceType']))
				continue;
			if (substr($deviceCfg['macDeviceType'], 0, 6) !== 'router' && substr($deviceCfg['macDeviceType'], 0, 3) !== 'ad-')
				continue;

      if (!intval($deviceCfg['cfg']['capsmanServer'] ?? 0))
        continue;

      $this->cmsDevice = $deviceNdx;
      //echo json_encode($deviceCfg)."\n\n";

			if ($this->app->debug)
				echo '==== get capsman for device #'.$deviceNdx.' '.$deviceCfg['ipManagement']." ====\n";

      $this->rtiData = [];

      $eng = new \Shipard\lanControl\LanControlHost($this->app);
      $lcd = $eng->createDevice($deviceNdx);
      if ($lcd)
      {
        $this->rtiData['capsman']['ifaces'] = $this->getInfoPart($lcd, 'getCapsmanInterfaces');
        $this->rtiData['capsman']['radio'] = $this->getInfoPart($lcd, 'getCapsmanServerRadio');
        $this->rtiData['capsman']['rt'] = $this->getInfoPart($lcd, 'getCapsmanServerRegistrationTable');
        $this->rtiData['dhcp']['leases'] = $this->getInfoPart($lcd, 'getDHCPLeases');

        if ($this->app->debug)
          echo json_encode($this->rtiData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
      }

      $this->createData();
		}
	}

  protected function getInfoPart(\Shipard\lanControl\devices\LanControlDeviceCore $lcd, $cmdId)
  {
    $lcd->initFileNames($cmdId);
    $shellCmd = $lcd->shellCommand($cmdId);
    $lcd->runCommand($cmdId, $shellCmd);

    $dataStr = $lcd->cmdResults[$cmdId]['data'];

    if ($this->app->debug)
      echo "\n----\n".$dataStr."\n----\n";

    $parser = new \Shipard\lanControl\devices\mikrotik\Parser($this->app);
    $parser->setSrcScript($dataStr);
    $parser->parse();

    //$rtiData['capsman']['rt'] = $parser->parsedData;
    return $parser->parsedData;
  }

  protected function createData()
  {
    // -- reset counters - radios
    foreach ($this->rtiData['capsman']['radio'] as $radioItem)
    {
      $apId = $radioItem['remote-cap-identity'] ?? '';
      if ($apId === '')
        continue;
      $this->rtiData['rti']['cnt']['ap'][$apId] = 0;
    }

    $this->rtiData['rti']['clients'] = [];
    foreach ($this->rtiData['capsman']['rt'] as $rtItem)
    {
      $item = [
        'mac' => $rtItem['mac-address'],
        'hostName' => '',
        'ssid' => $rtItem['ssid'] ?? '',
        'rssi' => intval($rtItem['rx-signal'] ?? 0),
        'apId' => '',
        'cch' => '',
        'txRate' => $rtItem['tx-rate'] ?? '',
        'rxRate' => $rtItem['rx-rate'] ?? '',
      ];

      //echo $rtItem['mac-address']."; ".$rtItem['ssid']."; rssi: ".intval($rtItem['rx-signal'])."; ";


      $leases = $this->searchMacLeases($rtItem['mac-address']);
      if (isset($leases[0]['host-name']))
        $item['hostName'] = $leases[0]['host-name'];
      //echo count($leases).' '.$leases[0]['host-name'].'; ';

      //echo "<".$rtItem['interface']."> ";

      $radio = NULL;
      $iface = $this->searchIface($rtItem['interface']);
      if ($iface)
      {
        //echo json_encode($iface)." ";
        if (isset($iface['master-interface']) && $iface['master-interface'] !== 'none')
        {
          $radio = $this->searchRadio($iface['master-interface']);
          $masterIface = $this->searchIface($iface['master-interface']);
          if ($masterIface)
            $item['cch'] = $masterIface['current-channel'];
        }
        else
        {
          $item['cch'] = $iface['current-channel'];
          $radio = $this->searchRadio($rtItem['interface']);
        }
      }
      else
        $radio = $this->searchRadio($rtItem['interface']);

      //if ($radio)
      //  echo $radio['remote-cap-identity'].'; ';

      if ($radio)
      {
        $item['apId'] = $radio['remote-cap-identity'];
      }

      //echo "\n";
      $this->rtiData['rti']['clients'][] = $item;
      if (isset($item['ssid']) && $item['ssid'] !== '')
      {
        if (isset($this->rtiData['rti']['cnt']['ssid'][$item['ssid']]))
          $this->rtiData['rti']['cnt']['ssid'][$item['ssid']]++;
        else
          $this->rtiData['rti']['cnt']['ssid'][$item['ssid']] = 1;
      }
      if (isset($item['apId']) && $item['apId'] !== '')
      {
        if (isset($this->rtiData['rti']['cnt']['ap'][$item['apId']]))
          $this->rtiData['rti']['cnt']['ap'][$item['apId']]++;
        else
          $this->rtiData['rti']['cnt']['ap'][$item['apId']] = 1;
      }
    }

    $this->rtiData['rti']['cmsDevice'] = $this->cmsDevice;

    if ($this->app->debug)
      echo json_encode($this->rtiData['rti'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

    // -- statsd --> netdata
    $netDataStrValue = '';
    foreach ($this->rtiData['rti']['cnt'] as $cntClass => $cntClassInfo)
    {
      foreach ($cntClassInfo as $ciId => $ci)
      {
        $netDataStrValue .= 'capsman.'.$cntClass.'.'.$ciId.': '.$ci.'|g|#name='.$cntClass.'usersCount'."\n";
      }
    }
    //echo $netDataStrValue;
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_sendto($sock, $netDataStrValue, strlen($netDataStrValue), 0, '127.0.0.1', 8125);
    socket_close($sock);


    // -- send
    if (isset($this->rtiData['rti']) && count($this->rtiData['rti']))
    {
      $url = $this->app->serverCfg['dsUrl'].'/api/objects/call/mac-lan-wifi-upload/'.$this->app->serverCfg['serverId'];
      //echo $url."\n";
  		$res = $this->app->apiSend($url, $this->rtiData['rti']);
      //echo json_encode($res)."\n";
    }
  }

  public function searchMacLeases($mac)
  {
    $leases = [];
    foreach ($this->rtiData['dhcp']['leases'] as $leaseItem)
    {
      if ($leaseItem['mac-address'] !== $mac)
        continue;
      if ($leaseItem['status'] !== 'bound')
        continue;

      $leases[] = $leaseItem;
    }

    return $leases;
  }

  public function searchIface($interface)
  {
    foreach ($this->rtiData['capsman']['ifaces'] as $ifaceItem)
    {
      if ($ifaceItem['name'] !== $interface)
        continue;

      return $ifaceItem;
    }

    return NULL;
  }


  public function searchRadio($interface)
  {
    //$radio = [];
    foreach ($this->rtiData['capsman']['radio'] as $radioItem)
    {
      if ($radioItem['interface'] !== $interface)
        continue;

      return $radioItem;
    }

    return NULL;
  }


  public function run()
  {
    $this->doIt();
  }
}
