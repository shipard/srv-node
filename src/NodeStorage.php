<?php


namespace Shipard;


/**
 * Class NodeStorage
 * @package lib
 */
class NodeStorage
{
	/** @var  \lib\Application */
	var $app;
	var $redis;

	public function __construct($app)
	{
		$this->app = $app;

		$this->redis = new \Redis ();
		$this->redis->connect('127.0.0.1');
	}

	public function addItem ($class, $id, $item)
	{
		$keyId = $class.':'.$id;

		$this->redis->hMset($keyId, $item);
	}

	public function addItems ($class, $keyId, $items)
	{
		foreach ($items as $item)
			$this->addItem($class, $item[$keyId], $items);
	}

	public function getItem ($class, $id)
	{
		$keyId = $class.':'.$id;
		$i = $this->redis->hGetAll($keyId);
		return $i;
	}

	public function getItems ($class)
	{
		$keys = [];
		$items = [];

		$iterate = null;
		$this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
		while ($arr_keys = $this->redis->scan($iterate, $class.':*'))
		{
			foreach ($arr_keys as $str_key)
				$keys[] = $str_key;
		}

		foreach ($keys as $key)
			$items[] = $this->redis->hGetAll($key);

		return $items;
	}

	public function setField ($class, $id, $key, $value)
	{
		$keyId = $class.':'.$id;
		$this->redis->hSet($keyId, $key, $value);
	}

	public function wasKey ($key)
	{
		$oldKey = $key . '-OLD';
		if ($this->redis->rename($key, $oldKey))
		{
			$value = $this->redis->get ($oldKey);
			$this->redis->del($oldKey);
			return $value;
		}

		return FALSE;
	}
}
