<?php

namespace Shipard;

class Utility 
{
	var ?\Shipard\Application $app = NULL;

	public function __construct(\Shipard\Application $app)
	{
		$this->app = $app;
	}
}