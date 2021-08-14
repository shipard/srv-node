<?php

if (!defined ('__NODE_DIR__'))
{
	$parts = explode('/', __DIR__);
	array_pop($parts);
	define('__NODE_DIR__', implode('/', $parts).'/');
}


function __autoload_tables ($class_name)
{
	if (substr ($class_name, 0, 8) === "Shipard\\")
	{
		$fnparts = explode ("\\", substr ($class_name, 8));

		if (count($fnparts) === 1)
			$fn = __NODE_DIR__.'/src/'.$fnparts[0].'.php';
		else
		{
			$cbn = array_pop($fnparts);
			$fn = __NODE_DIR__.'/src/'.implode('/', $fnparts).'/'.$cbn.'.php';
		}

		if (is_file ($fn))
			include_once ($fn);
		else
			error_log ('file not found: ' . $fn . ' (required for class ' . $class_name . ')');

		return;
	}
}

spl_autoload_register('__autoload_tables');

