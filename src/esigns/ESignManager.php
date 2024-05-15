<?php
namespace Shipard\esigns;


/**
 * class ESignManager
 */
class ESignManager extends \Shipard\host\Core
{
  var $esignsCfg = NULL;
  var $dstDir = '/var/lib/shipard-node/esigns/';

  protected function loadESignsCfg()
  {
    $this->esignsCfg = $this->app->loadCfgFile('/etc/shipard-node/esigns.json');
  }

  protected function doOne($esignNdx, $esign)
  {
    $url = $this->app->serverCfg['dsUrl'].'/api/objects/call/iot-mac-esign-image';
    $requestData = ['esignNdx' => $esignNdx];


    $result = $this->app->apiSend ($url, $requestData);
    //echo json_encode($result)."\n";

    if (!$result || !isset($result['success']) || !intval($result['success']))
    {
      return;
    }

    // -- copy image file
    if (!is_dir($this->dstDir))
      mkdir($this->dstDir, 0775, TRUE);

    if (!is_link('/var/www/iot-boxes/esigns'))
			symlink($this->dstDir, '/var/www/iot-boxes/esigns');

    $imageDestFileName = $this->dstDir.$esign['epdId'].'.sbef';
    copy ($result['esignImg']['imageEinkURL'], $imageDestFileName);

    $epdStatus = ['v' => $result['esignImg']['version']];
    $statusDestFileName = $this->dstDir.$esign['epdId'].'.json';
    file_put_contents($statusDestFileName, json_encode($epdStatus));
    $statusDestFileName = $this->dstDir.$esign['epdId'].'.ver';
    file_put_contents($statusDestFileName, strval($result['esignImg']['version']));
  }

  protected function doAll()
  {
    foreach ($this->esignsCfg as $esignNdx => $esign)
    {
      //echo "#". $esignNdx . ": ".json_encode($esign)."\n";
      $this->doOne($esignNdx, $esign);
    }
  }

  public function run()
  {
    $this->loadESignsCfg();
    if (!$this->esignsCfg || !count($this->esignsCfg))
      return;

    $this->doAll();
  }
}



