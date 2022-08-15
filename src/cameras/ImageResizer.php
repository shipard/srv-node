<?php


namespace Shipard\cameras;


/**
 * Class ImageResizer
 * @package lib\cameras
 */
class ImageResizer
{
	/** @var  \WebApp */
	private $app;

	private $params = [];
	private $srcFileName;
	private $cachePath = '';
	private $requestPath;
	private $convertFormat = FALSE;
	private $quality = FALSE;

	private $width = 0;
	private $height = 0;

	private $convertParams = array ();
	public $cacheFullFileName;
	public $cacheRelativeFileName;

	public function __construct ($app)
	{
		$this->app = $app;
	}

	public function run ()
	{
		$this->createSrcFileName ();
		if (!file_exists ($this->srcFileName))
		{
			error_log ("file not found: " . $this->srcFileName);
			header('HTTP/1.1 404 Not Found');
			echo 'Error: image does not exist! ';
			return;
		}

		$this->resize ();
		$this->send();
	}

	public function createSrcFileName ($fn = FALSE)
	{
		$cpi = 1;
		$this->srcFileName = $this->app->picturesDir;
		$fileName = ($fn !== FALSE) ? $fn : $this->app->requestPath ();
		$this->requestPath = $fileName;
		$fileNameParts = explode ('/', substr($fileName, 1));
		unset($fileNameParts[0]);
		foreach ($fileNameParts as $p)
		{
			if ($p [0] == '-')
			{
				$this->params[] = $p;
				continue;
			}
			$this->srcFileName .= '/' . $p;
			if ($cpi)
			{
				$this->cachePath .= '/' . $p;
				$cpi--;
			}
		}
		$this->srcFileName = urldecode ($this->srcFileName);
	} // createSrcFileName

	public function parseParams ($util, $fileSuffix)
	{
		foreach ($this->params as $p)
		{
			switch ($p [1])
			{
				case 'w': $this->width = (int) substr($p, 2); break;
				case 'h': $this->height = (int) substr($p, 2); break;
				case 'x': $this->convertFormat = TRUE; ;break;
				case 'q': $this->quality = intval(substr($p, 2)); break;
			}
		}

		// convert -resize
		if (($util == "convert") && (($this->width) || ($this->height)))
		{
			$prm = '-resize ';
			if ($this->width)
				$prm .= $this->width;
			$prm .= 'x';
			if ($this->height)
				$prm .= $this->height;
			$prm .= '\>';
			$this->convertParams [] = $prm;

			if ($fileSuffix === '.jpg' || $fileSuffix === '.jpeg')
			{
				if ($this->quality === FALSE)
					$this->convertParams [] = '-quality 90 -interlace Plane -strip';
				else
					$this->convertParams [] = '-quality ' . $this->quality . ' -interlace Plane -strip';
				$this->convertParams[] = '-auto-orient';
			}
		}

		// rsvg-convert -resize´
		if (($util == "rsvg-convert") && (($this->width) || ($this->height)))
		{
			$prm = '';
			if ($this->width)
				$prm .= ' -w ' . $this->width;
			if ($this->height)
				$prm .= ' -h ' . $this->height;
			$this->convertParams [] = $prm;
		}
	} // parseParams


	public function resize ()
	{
		$fileTypes = array ();
		$fileTypes['default'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/undefined.svg');
		$fileTypes['.txt'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/generic.svg');
		$fileTypes['.xls'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/spreadsheet.svg');
		$fileTypes['.ods'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/spreadsheet.svg');
		$fileTypes['.doc'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/document.svg');
		$fileTypes['.odt'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o', 'icon' => 'e10-modules/e10/server/icons/default/mime/document.svg');
		$fileTypes['.jpg'] = array ('util' => 'convert');
		$fileTypes['.jpeg'] = array ('util' => 'convert');
		$fileTypes['.png'] = array ('util' => 'convert', 'destFileType' => 'png');
		$fileTypes['.gif'] = array ('util' => 'convert', 'destFileType' => 'gif');
		$fileTypes['.tif'] = array ('util' => 'convert');
		$fileTypes['.tiff'] = array ('util' => 'convert');
		$fileTypes['.svg'] = array ('util' => 'rsvg-convert', 'extraParam' => '-a', 'outputFileParam' => '-o');
		$fileTypes['.pdf'] = array ('util' => 'convert', 'extraSourceNameParam' => '.jpg', 'extraParam' => '-quality 90');

		$srcType = strtolower (substr ($this->srcFileName, strrpos ($this->srcFileName, ".")));
		if (isset ($fileTypes[$srcType]))
			$fileType = $fileTypes[$srcType];
		else
			$fileType = $fileTypes ['default'];

		$extraParam = "";
		if (isset ($fileType['extraParam']))
			$extraParam = $fileType['extraParam']." ";
		$extraSourceNameParam = "";
		if (isset ($fileType['extraSourceNameParam']))
			$extraSourceNameParam = $fileType['extraSourceNameParam'];
		$outputFileParam = "";
		if (isset ($fileType['outputFileParam']))
			$outputFileParam = $fileType['outputFileParam']." ";

		// -- cache file name
		$destFileType = isset($fileType['destFileType']) ? $fileType['destFileType'] : 'jpg';
		$cacheBaseFileName = md5 ($this->requestPath) . '.' . $destFileType;
		$cacheDir = '/var/lib/shipard-node/imgcache' . $this->cachePath;
		$this->cacheFullFileName = $cacheDir . '/' . $cacheBaseFileName;
		$this->cacheRelativeFileName = '/imgcache/'.$this->cachePath.'/'.$cacheBaseFileName;

		if (file_exists ($this->cacheFullFileName))
			return;

		if (!is_dir($cacheDir))
			mkdir ($cacheDir, 0775, TRUE);

		$this->parseParams ($fileType['util'], $srcType);
		if ($this->convertFormat)
		{
			$suffixPos = strrpos($this->srcFileName, '.');
			$this->srcFileName = substr($this->srcFileName, 0, $suffixPos);
		}
		$srcFileName = $this->srcFileName;

		if ($srcType == '.pdf')
			$cmd = "gs -dNOPAUSE -dBATCH -dFirstPage=1 -dLastPage=1 -sDEVICE=jpeg -r300 -o {$this->cacheFullFileName}.jpg $srcFileName && " .
				$fileType['util'] . " " . $extraParam . implode (" ", $this->convertParams) .
				" \"{$this->cacheFullFileName}.jpg\"" .
				' '. $outputFileParam . "\"" . $this->cacheFullFileName . "\"";
		else
		{
			$resizeViaPIL = intval($this->app->serverCfg['resizeViaPIL'] ?? 0);
			if ($resizeViaPIL && $srcType === '.jpg')
			{
				$cmd = '/usr/lib/shipard-node/tools/shn-resize-image.py' .
					" \"" . $srcFileName . "\"" .
					" \"" . $this->cacheFullFileName . "\"";
			}
			else
			{
				$cmd = $fileType['util'] . " " . $extraParam . implode (" ", $this->convertParams) .
					" \"" . $srcFileName . $extraSourceNameParam . "\"" .
					' '. $outputFileParam . "\"" . $this->cacheFullFileName . "\"";
			}
		}
		exec ($cmd);
	}

	public function send ()
	{
		$mime = mime_content_type ($this->cacheFullFileName);
		header ("Content-type: $mime");
		header ("Cache-control: max-age=3600");
		header ('Expires: '.gmdate('D, d M Y H:i:s', time()+3600).'GMT'); // 1 hour
		header ('Content-Disposition: inline; filename=' . basename ($this->cacheFullFileName));

		header ('X-Accel-Redirect: ' . $this->app->urlRoot.$this->cacheRelativeFileName);
		die();
	}
}
