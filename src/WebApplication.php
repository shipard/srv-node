<?php

namespace Shipard;


class WebApplication extends \Shipard\Application
{
	var $dsRoot;
	var $urlRoot;
	var $requestPath;
	var $command;

	var $cmd = '';
	var $authToken = '';

	var $nodeTokens = NULL;

	protected function parseUrl ()
	{
		// -- parse url for routing
		$url = $_SERVER ["REQUEST_URI"];
		$p = strpos($url, '?');
		if($p !== false)
			$url = substr($url, 0, $p);

		$url = str_replace("//","/",$url);

		$this->dsRoot = strstr($_SERVER['SCRIPT_NAME'], '/index.php', true);
		$this->urlRoot = $this->dsRoot;

		$requestURI = explode ('/', $url);
		$scriptName = explode ('/', $_SERVER['SCRIPT_NAME']);
		$this->requestPath = array_values (array_diff_assoc ($requestURI, $scriptName));
	}

	public function requestPath ($idx = -1)
	{
		if ($idx === -1)
			return '/' . implode ('/', $this->requestPath);
		if (isset ($this->requestPath [$idx]))
			return $this->requestPath [$idx];
		return '';
	}

	public function init ()
	{
		$this->parseUrl();
		$this->command = $this->requestPath[0];

		$this->authToken = $this->requestPath[0] ?? '';
		$this->cmd = $this->requestPath[1] ?? '';

		$this->nodeTokens = $this->loadCfgFile('/etc/shipard-node/node-tokens.json');
		if (!$this->nodeTokens)
			$this->nodeTokens = [];
	}

	protected function error ($status, $msg)
	{
		//header ('X-Frame-Options: SAMEORIGIN');
		header("HTTP/1.1 " . $status.' '.$msg);
		echo ("ERROR ".$status.': '.$msg);
	}

	public function postData ()
	{
		$data = '';
		$handle = fopen('php://input','r');
		while (1)
		{
			$buffer = fgets($handle, 4096);
			if (strlen($buffer) === 0)
				break;
			$data .= $buffer;
		}
		fclose ($handle);
		return $data;
	}

	public function sendJson ($data)
	{
		//header ('X-Frame-Options: SAMEORIGIN');
		header ("Content-type: " . 'application/json');
		header ("HTTP/1.1 200 OK");

		$callback = '';
		if (isset($_GET['callback']))
			$callback = htmlspecialchars ($_GET['callback']);

		if ($callback !== '')
			echo ("$callback(");

		echo $data;

		if ($callback !== '')
			echo(')');
	}

	public function sendHtml ($text)
	{
		//header ('X-Frame-Options: SAMEORIGIN');
		header ("Content-type: " . 'text/html');
		header ("HTTP/1.1 200 OK");

		echo $text;
	}

	public function sendText ($text)
	{
		header ("Content-type: " . 'text/plain');
		header ("HTTP/1.1 200 OK");

		echo $text;
	}

	public function archive()
	{
		$data = file_get_contents($this->camsDir.'/archive/video/archive.json');
		$this->sendJson($data);

		return 0;
	}

	protected function camerasImages($newFormat)
	{
		$callback = '';

		$redis = new \Redis ();
		$redis->connect('127.0.0.1');

		$pictures = [];

		foreach ($this->nodeCfg['cfg']['cameras'] as $cam)
		{
			$camId = $cam['ndx'];

			$imageKey = 'e10-monc-camLastImg-'.$camId;
			$timeKey = 'e10-monc-camLastImgTime-'.$camId;
			$thisTime = time();

			if ($newFormat)
			{
				$pictureTime = intval($redis->get($timeKey));
				$error = (($thisTime - $pictureTime) > 10) ? 1 : 0;
				$pictures [$camId] = ['image' => $redis->get($imageKey), 'time' => $redis->get($timeKey), 'error' => $error];
			}
			else
				$pictures [$camId] = $redis->get ($imageKey);
		}

		$data = '';
		if ($callback != '')
			$data.= "$callback(";
		$data .= json_encode ($pictures);
		if ($callback != "")
			$data .= ')';

		$this->sendJson($data);

		return 0;
	}

	protected function cameraImage ()
	{
		$cameraId = $this->requestPath(1);
		$imageFileName = $this->requestPath(2);
		if ($cameraId === '' || $imageFileName === '')
			return $this->error(404, 'Not found');

		$imgPath = $this->urlRoot.'/cameras/pictures/'.$cameraId.'/'.$imageFileName;
		$fullFileName = $this->picturesDir.'/'.$cameraId.'/'.$imageFileName;

		if (is_readable($fullFileName))
		{
			//header('X-Frame-Options: SAMEORIGIN');
			header("Content-type: " . 'image/jpeg');
			header('X-Accel-Redirect: ' . $imgPath);
			return '';
		}

		$imageResizer = new \Shipard\cameras\ImageResizer ($this);
		$imageResizer->run();

		return $this->error(404, 'Not found');
	}

	protected function camerasPictures()
	{
		$this->checkCORS();

		$redis = new \Redis ();
		$redis->connect('127.0.0.1');

		$pictures = [];

		foreach ($this->nodeCfg['cfg']['cameras'] as $cam)
		{
			$camId = $cam['ndx'];

			$imageKey = 'e10-monc-camLastImg-'.$camId;
			$timeKey = 'e10-monc-camLastImgTime-'.$camId;
			$thisTime = time();

			$pictureTime = intval($redis->get($timeKey));
			$error = (($thisTime - $pictureTime) > 10) ? 1 : 0;
			$pictures [$camId] = ['image' => $redis->get($imageKey), 'time' => $redis->get($timeKey), 'error' => $error];
		}

		$data = json_encode ($pictures);

		$this->sendJson($data);

		return 0;
	}

	protected function cameraImageNew ()
	{
		$cameraNdx = $this->requestPath(1);
		$imageFileName = $this->requestPath(2);
		if ($cameraNdx === '')
			return $this->error(404, 'Not found');

		$cam = isset($this->nodeCfg['cfg']['cameras'][$cameraNdx]) ? $this->nodeCfg['cfg']['cameras'][$cameraNdx] : NULL;
		if ($cam === NULL)
			return $this->error(404, 'Not found');
		$camId = (isset($cam['cfg']['picturesFolder']) && $cam['cfg']['picturesFolder'] !== '') ? $cam['cfg']['picturesFolder'] : $cam['ndx'];

		if ($imageFileName === '')
		{
			$redis = new Redis ();
			$redis->connect('127.0.0.1');
			$imageKey = 'e10-monc-camLastImg-'.$cameraNdx;
			$imageFileName = $redis->get ($imageKey);
		}

		$imgPath = $this->urlRoot.'/cameras/pictures/'.$camId.'/'.$imageFileName;
		$fullFileName = $this->picturesDir.'/'.$camId.'/'.$imageFileName;

		if (is_readable($fullFileName))
		{
			//header('X-Frame-Options: SAMEORIGIN');
			header('Content-type: image/jpeg');
			header('X-Accel-Redirect: ' . $imgPath);
			return '';
		}

		return $this->error(404, 'Not found');
	}

	public function control ()
	{
		$postDataStr = $this->postData();
		if ($postDataStr === '')
			return $this->error(404, 'Not found / no data');

		$postData = json_decode($postDataStr, TRUE);
		if (!$postData)
			return $this->error(404, 'Not found / invalid data');

		$ce = new \Shipard\control\Control($this);
		$ce->setRequest($postData);
		$ce->run();

		return $this->sendJson(json_encode($ce->responseData));
	}

	public function loadCfg ()
	{
		$cfgType = $this->requestPath(1);
		if ($cfgType !== 'node' && $cfgType !== 'lan')
			return $this->error(404, 'Not found');

		$redis = new \Redis ();
		$redis->connect('127.0.0.1');
		$redis->set ('e10-nw-loadCfg:'.$cfgType, '1');

		$result = ['status' => 'OK'];
		return $this->sendJson(json_encode($result));
	}

	public function lans ()
	{
		$infoType = $this->requestPath(1);
		if ($infoType !== 'status')
			return $this->error(404, 'Not found...');

		$data = '{}';
		$lan = new \Shipard\lan\LanInfo($this);
		if ($infoType === 'status')
			$data = $lan->status();

		$result = ['status' => 'OK', 'serverId' => $this->serverCfg['serverId'], 'data' => $data];
		return $this->sendJson(json_encode($result));
	}

	public function lcSSH ()
	{
		$fileName = $this->requestPath(2);
		if ($fileName === '' || !str_ends_with($fileName, '.pub'))
			return $this->error(404, 'Not found...');

		$tftpHomeDir = $this->tftpHomeDir();
		if ($tftpHomeDir === FALSE)
			return $this->error(404, 'Not found...');

		$ffn = $tftpHomeDir.'/'.$fileName;
		if (!is_readable($ffn))
			return $this->error(404, 'Not found...');

		$fileData = file_get_contents($ffn);

		$this->sendText($fileData);

		return TRUE;
	}

	public function rg ()
	{
		$rg = new \Shipard\rg\RackGuard($this);
		return $rg->sendContent();
	}

	public function remotePrint ()
	{
		$fn = 'rp-' . time() . '-' . mt_rand(100000, 999999) . '.rawprint';
		$destFileName = '/var/lib/shipard-node/tmp/' . $fn;

		$fileReader = fopen ('php://input', "r");
		$fileWriter = fopen ($destFileName, "w+");

		while (true)
		{
			$buffer = fgets ($fileReader, 4096);
			if (strlen ($buffer) == 0)
			{
				fclose ($fileReader);
				fclose ($fileWriter);
				break;
			}
			fwrite ($fileWriter, $buffer);
		}

		$copies = 1;
		if (isset ($_GET ['copies']))
			$copies = intval($_GET ['copies']);
		if ($copies < 1 || $copies > 5)
			$copies = 1;

		$printer = '';
		if (isset ($_GET ['printer']))
			$printer = $_GET ['printer'];

		$cmd = "lp -n $copies ";
		if ($printer !== '')
			$cmd .= "-d $printer ";

		$cmd .= $destFileName;

		exec ($cmd);
	}

	protected function getAllHeaders()
	{
		$headers = [];
		foreach ($_SERVER as $name => $value)
		{
			if (substr($name, 0, 5) == 'HTTP_')
				$headers[str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))))] = $value;
		}
		return $headers;
	}

	protected function checkCORS()
	{
		$headers = $this->getAllHeaders();
		$origin = (isset($headers['origin'])) ? $headers['origin'] : '';
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
		{
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Allow-Origin: '.$origin);
			header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
			header('Access-Control-Allow-Headers: Origin, content-type');
			die();
		}

		if ($origin !== '')
		{
			header('Access-Control-Allow-Origin: '.$origin);
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
		}
	}

	public function run ()
	{
		$this->init();

		if (!$this->command || $this->command === '')
			return $this->error(404, 'Not found!!!');

		switch ($this->command)
		{
			case 'archive':	return $this->archive();
			case 'cameras':	return $this->camerasImages(TRUE);
			case 'campicts':return $this->camerasPictures();
			case 'camera':	return $this->cameraImageNew();
			case 'imgs':		return $this->cameraImage();
			case 'print':		return $this->remotePrint();
			case 'loadcfg':	return $this->loadCfg();
			case 'lans':		return $this->lans();
			case 'control':	return $this->control();
			case 'rg':			return $this->rg();
		}

		if (!in_array($this->authToken, $this->nodeTokens))
			return $this->error(404, 'Not found!!!');

		switch ($this->cmd)
		{
			case 'lc-ssh':	return $this->lcSSH();
		}

		$this->error(404, 'Not found!');
	}
}
