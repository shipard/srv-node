<?php

namespace Shipard\lanControl\devices\mikrotik;

use Shipard\Utility;

class Parser extends Utility
{
	var $srcScript = '';
	var $srcScriptRows = NULL;
	var $parsedData = [];

	public function setSrcScript($srcScript)
	{
		$this->srcScript = $srcScript;
	}

	public function parse()
	{
		$rows = preg_split("/\\r\\n|\\r|\\n/", $this->srcScript);
    $this->srcScriptRows = [];

    $firstSubRow = 1;
    $row = '';
    foreach ($rows as $r)
    {
      if (str_starts_with($r, 'Flags:'))
        continue;
      if ($r === '')
      {
        if ($row !== '')
          $this->srcScriptRows[] = $row;
        $row = '';
        $firstSubRow = 1;
        continue;
      }
      if ($firstSubRow)
      {
        $row = trim(strstr(trim($r), ' '));
        if (str_starts_with($row, ';;;'))
          $row = 'comment="'.substr($row, 4).'"';
      }
      else
        $row .= ' '.trim($r);
      $firstSubRow = 0;
    }
    //echo json_encode($this->srcScriptRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";


    while(1)
		{
			if (!$this->parseNextRow())
				break;
		}
	}

	protected function parseNextRow()
	{
		$row = array_shift($this->srcScriptRows);
		if ($row === NULL)
			return FALSE;
		if ($row === '' || $row[0] === '#')
			return TRUE;

		$this->parseRow($row);

		return TRUE;
	}

	function parseRow($row)
	{
		$params = preg_split("/ (?=[^\"]*(\"[^\"]*\"[^\"]*)*$)/", $row);
		//$cmd = ['type' => '?', 'params' => []];
    $cmds = [];
//echo "  --> ".json_encode($params)."\n";
		$cmdRoot = '';
		$cmdRootChunks = [];


		while(1)
		{
			$prm = array_shift($params);
			if ($prm === NULL)
				break;

			$this->parseCmd($prm, $cmds);
		}

		$this->parsedData[] = $cmds;
	}

	function parseCmd($prm, &$addTo)
	{
//    if (str_starts_with($prm, ';;;'))
//      return;

		$assignmentMark = strstr($prm, '=');
		if ($assignmentMark === FALSE)
		{
			//$addTo[$prm] = NULL;
      if (!isset($addTo['flag']))
        $addTo['flag'] = $prm;
      else
      {
        $fidx = 1;
        while(1)
        {
          if (!isset($addTo['flag'.$fidx]))
          {
            $addTo['flag'.$fidx] = $prm;
            return;
          }
          $fidx++;
        }
      }
			return;
		}

		$k = strstr($prm, '=', TRUE);
		$v = substr($assignmentMark, 1);
		if ($v[0] === '"')
			$v = substr($v, 1, -1);

		$addTo[$k] = $v;
	}

}