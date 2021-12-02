<?php

namespace Shipard\rg;


class RackGuard 
{
	public function __construct(public \Shipard\WebApplication $app)
	{
	}

	protected function localSensors()
	{
		$redis = $this->app->redis();
		$lc = $redis->get('localSensors');

		$this->app->sendJson($lc);

		return TRUE;
	}

	public function sendContent()
	{
		if ($this->app->requestPath(1) === 'localSensors')
		{
			return $this->localSensors();	
		}

		if ($this->app->requestPath(1) === '')
		{
			$html = file_get_contents('/usr/lib/shipard-node/www/rg/rg.html');
			$this->app->sendHtml($html);
			return TRUE;
		}

		$this->app->sendHtml("<h1>123 456 789 abc def</h1><br>".$this->app->requestPath(0));

		return TRUE;
	}
}
