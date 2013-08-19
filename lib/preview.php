<?php
/**
 * Copyright (c) 2013 Frank Karlitschek frank@owncloud.org
 * Copyright (c) 2013 Georg Ehrke georg@ownCloud.com
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 * Thumbnails:
 * structure of filename:
 * /data/user/thumbnails/pathhash/x-y.png
 * 
 */
namespace OC;

require_once('preview/images.php');
require_once('preview/movies.php');
require_once('preview/mp3.php');
require_once('preview/pdf.php');
require_once('preview/svg.php');
require_once('preview/txt.php');
require_once('preview/unknown.php');
require_once('preview/office.php');

class Preview {
	//the thumbnail folder
	const THUMBNAILS_FOLDER = 'thumbnails';

	//config
	private $maxScaleFactor;
	private $configMaxX;
	private $configMaxY;

	//fileview object
	private $fileview = null;
	private $userview = null;

	//vars
	private $file;
	private $maxX;
	private $maxY;
	private $scalingup;

	//preview images object
	private $preview;

	//preview providers
	static private $providers = array();
	static private $registeredProviders = array();

	/**
	 * @brief check if thumbnail or bigger version of thumbnail of file is cached
	 * @param string $user userid - if no user is given, OC_User::getUser will be used
	 * @param string $root path of root
	 * @param string $file The path to the file where you want a thumbnail from
	 * @param int $maxX The maximum X size of the thumbnail. It can be smaller depending on the shape of the image
	 * @param int $maxY The maximum Y size of the thumbnail. It can be smaller depending on the shape of the image
	 * @param bool $scalingUp Disable/Enable upscaling of previews
	 * @return mixed (bool / string) 
	 *					false if thumbnail does not exist
	 *					path to thumbnail if thumbnail exists
	*/
	public function __construct($user='', $root='/', $file='', $maxX=1, $maxY=1, $scalingUp=true) {
		//set config
		$this->configMaxX = \OC_Config::getValue('preview_max_x', null);
		$this->configMaxY = \OC_Config::getValue('preview_max_y', null);
		$this->maxScaleFactor = \OC_Config::getValue('preview_max_scale_factor', 2);

		//save parameters
		$this->setFile($file);
		$this->setMaxX($maxX);
		$this->setMaxY($maxY);
		$this->setScalingUp($scalingUp);

		//init fileviews
		if($user === ''){
			$user = \OC_User::getUser();
		}
		$this->fileview = new \OC\Files\View('/' . $user . '/' . $root);
		$this->userview = new \OC\Files\View('/' . $user);
		
		$this->preview = null;

		//check if there are preview backends
		if(empty(self::$providers)) {
			self::initProviders();
		}

		if(empty(self::$providers)) {
			\OC_Log::write('core', 'No preview providers exist', \OC_Log::ERROR);
			throw new \Exception('No preview providers');
		}
	}

	/**
	 * @brief returns the path of the file you want a thumbnail from
	 * @return string
	*/
	public function	getFile() {
		return $this->file;
	}

	/**
	 * @brief returns the max width of the preview
	 * @return integer
	*/
	public function getMaxX() {
		return $this->maxX;
	}

	/**
	 * @brief returns the max height of the preview
	 * @return integer
	*/
	public function getMaxY() {
		return $this->maxY;
	}

	/**
	 * @brief returns whether or not scalingup is enabled
	 * @return bool
	*/
	public function getScalingUp() {
		return $this->scalingup;
	}

	/**
	 * @brief returns the name of the thumbnailfolder
	 * @return string
	*/
	public function getThumbnailsFolder() {
		return self::THUMBNAILS_FOLDER;
	}

	/**
	 * @brief returns the max scale factor
	 * @return integer
	*/
	public function getMaxScaleFactor() {
		return $this->maxScaleFactor;
	}

	/**
	 * @brief returns the max width set in ownCloud's config
	 * @return integer
	*/
	public function getConfigMaxX() {
		return $this->configMaxX;
	}

	/**
	 * @brief returns the max height set in ownCloud's config
	 * @return integer
	*/
	public function getConfigMaxY() {
		return $this->configMaxY;
	}

	/**
	 * @brief set the path of the file you want a thumbnail from
	 * @param string $file
	 * @return $this
	*/
	public function setFile($file) {
		$this->file = $file;
		return $this;
	}

	/**
	 * @brief set the the max width of the preview
	 * @param int $maxX
	 * @return $this
	*/
	public function setMaxX($maxX=1) {
		if($maxX <= 0) {
			throw new \Exception('Cannot set width of 0 or smaller!');
		}
		$configMaxX = $this->getConfigMaxX();
		if(!is_null($configMaxX)) {
			if($maxX > $configMaxX) {
				\OC_Log::write('core', 'maxX reduced from ' . $maxX . ' to ' . $configMaxX, \OC_Log::DEBUG);
				$maxX = $configMaxX;
			}
		}
		$this->maxX = $maxX;
		return $this;
	}

	/**
	 * @brief set the the max height of the preview
	 * @param int $maxY
	 * @return $this
	*/
	public function setMaxY($maxY=1) {
		if($maxY <= 0) {
			throw new \Exception('Cannot set height of 0 or smaller!');
		}
		$configMaxY = $this->getConfigMaxY();
		if(!is_null($configMaxY)) {
			if($maxY > $configMaxY) {
				\OC_Log::write('core', 'maxX reduced from ' . $maxY . ' to ' . $configMaxY, \OC_Log::DEBUG);
				$maxY = $configMaxY;
			}
		}
		$this->maxY = $maxY;
		return $this;
	}

	/**
	 * @brief set whether or not scalingup is enabled
	 * @param bool $scalingUp
	 * @return $this
	*/
	public function setScalingup($scalingUp) {
		if($this->getMaxScaleFactor() === 1) {
			$scalingUp = false;
		}
		$this->scalingup = $scalingUp;
		return $this;
	}

	/**
	 * @brief check if all parameters are valid
	 * @return bool
	*/
	public function isFileValid() {
		$file = $this->getFile();
		if($file === '') {
			\OC_Log::write('core', 'No filename passed', \OC_Log::ERROR);
			return false;
		}

		if(!$this->fileview->file_exists($file)) {
			\OC_Log::write('core', 'File:"' . $file . '" not found', \OC_Log::ERROR);
			return false;
		}

		return true;
	}

	/**
	 * @brief deletes previews of a file with specific x and y
	 * @return bool
	*/
	public function deletePreview() {
		$file = $this->getFile();

		$fileInfo = $this->fileview->getFileInfo($file);
		$fileId = $fileInfo['fileid'];

		$previewPath = $this->getThumbnailsFolder() . '/' . $fileId . '/' . $this->getMaxX() . '-' . $this->getMaxY() . '.png';
		$this->userview->unlink($previewPath);
		return !$this->userview->file_exists($previewPath);
	}

	/**
	 * @brief deletes all previews of a file
	 * @return bool
	*/
	public function deleteAllPreviews() {
		$file = $this->getFile();

		$fileInfo = $this->fileview->getFileInfo($file);
		$fileId = $fileInfo['fileid'];
		
		$previewPath = $this->getThumbnailsFolder() . '/' . $fileId . '/';
		$this->userview->deleteAll($previewPath);
		$this->userview->rmdir($previewPath);
		return !$this->userview->is_dir($previewPath);
	}

	/**
	 * @brief check if thumbnail or bigger version of thumbnail of file is cached
	 * @return mixed (bool / string) 
	 *				false if thumbnail does not exist
	 *				path to thumbnail if thumbnail exists
	*/
	private function isCached() {
		$file = $this->getFile();
		$maxX = $this->getMaxX();
		$maxY = $this->getMaxY();
		$scalingUp = $this->getScalingUp();
		$maxscalefactor = $this->getMaxScaleFactor();

		$fileInfo = $this->fileview->getFileInfo($file);
		$fileId = $fileInfo['fileid'];

		if(is_null($fileId)) {
			return false;
		}

		$previewPath = $this->getThumbnailsFolder() . '/' . $fileId . '/';
		if(!$this->userview->is_dir($previewPath)) {
			return false;
		}

		//does a preview with the wanted height and width already exist?
		if($this->userview->file_exists($previewPath . $maxX . '-' . $maxY . '.png')) {
			return $previewPath . $maxX . '-' . $maxY . '.png';
		}

		$wantedAspectRatio = (float) ($maxX / $maxY);

		//array for usable cached thumbnails
		$possibleThumbnails = array();

		$allThumbnails = $this->userview->getDirectoryContent($previewPath);
		foreach($allThumbnails as $thumbnail) {
			$name = rtrim($thumbnail['name'], '.png');
			$size = explode('-', $name);
			$x = (int) $size[0];
			$y = (int) $size[1];

			$aspectRatio = (float) ($x / $y);
			if($aspectRatio !== $wantedAspectRatio) {
				continue;
			}

			if($x < $maxX || $y < $maxY) {
				if($scalingUp) {
					$scalefactor = $maxX / $x;
					if($scalefactor > $maxscalefactor) {
						continue;
					}
				}else{
					continue;
				}
			}
			$possibleThumbnails[$x] = $thumbnail['path'];
		}

		if(count($possibleThumbnails) === 0) {
			return false;
		}

		if(count($possibleThumbnails) === 1) {
			return current($possibleThumbnails);
		}

		ksort($possibleThumbnails);

		if(key(reset($possibleThumbnails)) > $maxX) {
			return current(reset($possibleThumbnails));
		}

		if(key(end($possibleThumbnails)) < $maxX) {
			return current(end($possibleThumbnails));
		}

		foreach($possibleThumbnails as $width => $path) {
			if($width < $maxX) {
				continue;
			}else{
				return $path;
			}
		}
	}

	/**
	 * @brief return a preview of a file
	 * @return \OC_Image
	*/
	public function getPreview() {
		if(!is_null($this->preview) && $this->preview->valid()){
			return $this->preview;
		}

		$this->preview = null;
		$file = $this->getFile();
		$maxX = $this->getMaxX();
		$maxY = $this->getMaxY();
		$scalingUp = $this->getScalingUp();

		$fileInfo = $this->fileview->getFileInfo($file);
		$fileId = $fileInfo['fileid'];

		$cached = $this->isCached();

		if($cached) {
			$image = new \OC_Image($this->userview->file_get_contents($cached, 'r'));
			$this->preview = $image->valid() ? $image : null;
			$this->resizeAndCrop();
		}

		if(is_null($this->preview)) {
			$mimetype = $this->fileview->getMimeType($file);
			$preview = null;

			foreach(self::$providers as $supportedMimetype => $provider) {
				if(!preg_match($supportedMimetype, $mimetype)) {
					continue;
				}

				\OC_Log::write('core', 'Generating preview for "' . $file . '" with "' . get_class($provider) . '"', \OC_Log::DEBUG);

				$preview = $provider->getThumbnail($file, $maxX, $maxY, $scalingUp, $this->fileview);

				if(!($preview instanceof \OC_Image)) {
					continue;
				}

				$this->preview = $preview;
				$this->resizeAndCrop();

				$previewPath = $this->getThumbnailsFolder() . '/' . $fileId . '/';
				$cachePath = $previewPath . $maxX . '-' . $maxY . '.png';

				if($this->userview->is_dir($this->getThumbnailsFolder() . '/') === false) {
					$this->userview->mkdir($this->getThumbnailsFolder() . '/');
				}

				if($this->userview->is_dir($previewPath) === false) {
					$this->userview->mkdir($previewPath);
				}

				$this->userview->file_put_contents($cachePath, $preview->data());

				break;
			}
		}

		if(is_null($this->preview)) {
			$this->preview = new \OC_Image();
		}

		return $this->preview;
	}

	/**
	 * @brief show preview
	 * @return void
	*/
	public function showPreview() {
		\OCP\Response::enableCaching(3600 * 24); // 24 hours
		if(is_null($this->preview)) {
			$this->getPreview();
		}
		$this->preview->show();
		return;
	}

	/**
	 * @brief show preview
	 * @return void
	*/
	public function show() {
		return $this->showPreview();
	}

	/**
	 * @brief resize, crop and fix orientation
	 * @return void
	*/
	private function resizeAndCrop() {
		$image = $this->preview;
		$x = $this->getMaxX();
		$y = $this->getMaxY();
		$scalingUp = $this->getScalingUp();
		$maxscalefactor = $this->getMaxScaleFactor();

		if(!($image instanceof \OC_Image)) {
			\OC_Log::write('core', '$this->preview is not an instance of OC_Image', \OC_Log::DEBUG);
			return;
		}

		$image->fixOrientation();

		$realx = (int) $image->width();
		$realy = (int) $image->height();

		if($x === $realx && $y === $realy) {
			$this->preview = $image;
			return true;
		}

		$factorX = $x / $realx;
		$factorY = $y / $realy;

		if($factorX >= $factorY) {
			$factor = $factorX;
		}else{
			$factor = $factorY;
		}

		if($scalingUp === false) {
			if($factor > 1) {
				$factor = 1;
			}
		}

		if(!is_null($maxscalefactor)) {
			if($factor > $maxscalefactor) {
				\OC_Log::write('core', 'scalefactor reduced from ' . $factor . ' to ' . $maxscalefactor, \OC_Log::DEBUG);
				$factor = $maxscalefactor;
			}
		}

		$newXsize = (int) ($realx * $factor);
		$newYsize = (int) ($realy * $factor);

		$image->preciseResize($newXsize, $newYsize);

		if($newXsize === $x && $newYsize === $y) {
			$this->preview = $image;
			return;
		}

		if($newXsize >= $x && $newYsize >= $y) {
			$cropX = floor(abs($x - $newXsize) * 0.5);
			//don't crop previews on the Y axis, this sucks if it's a document.
			//$cropY = floor(abs($y - $newYsize) * 0.5);
			$cropY = 0;

			$image->crop($cropX, $cropY, $x, $y);
			
			$this->preview = $image;
			return;
		}

		if($newXsize < $x || $newYsize < $y) {
			if($newXsize > $x) {
				$cropX = floor(($newXsize - $x) * 0.5);
				$image->crop($cropX, 0, $x, $newYsize);
			}

			if($newYsize > $y) {
				$cropY = floor(($newYsize - $y) * 0.5);
				$image->crop(0, $cropY, $newXsize, $y);
			}

			$newXsize = (int) $image->width();
			$newYsize = (int) $image->height();

			//create transparent background layer
			$backgroundlayer = imagecreatetruecolor($x, $y);
			$white = imagecolorallocate($backgroundlayer, 255, 255, 255);
			imagefill($backgroundlayer, 0, 0, $white);

			$image = $image->resource();

			$mergeX = floor(abs($x - $newXsize) * 0.5);
			$mergeY = floor(abs($y - $newYsize) * 0.5);

			imagecopy($backgroundlayer, $image, $mergeX, $mergeY, 0, 0, $newXsize, $newYsize);

			//$black = imagecolorallocate(0,0,0);
			//imagecolortransparent($transparentlayer, $black);

			$image = new \OC_Image($backgroundlayer);

			$this->preview = $image;
			return;
		}
	}

	/**
	 * @brief register a new preview provider to be used
	 * @param string $provider class name of a Preview_Provider
	 * @param array $options
	 * @return void
	 */
	public static function registerProvider($class, $options=array()) {
		self::$registeredProviders[]=array('class'=>$class, 'options'=>$options);
	}

	/**
	 * @brief create instances of all the registered preview providers
	 * @return void
	 */
	private static function initProviders() {
		if(count(self::$providers)>0) {
			return;
		}

		foreach(self::$registeredProviders as $provider) {
			$class=$provider['class'];
			$options=$provider['options'];

			$object = new $class($options);

			self::$providers[$object->getMimeType()] = $object;
		}

		$keys = array_map('strlen', array_keys(self::$providers));
		array_multisort($keys, SORT_DESC, self::$providers);
	}

	public static function post_write($args) {
		self::post_delete($args);
	}
	
	public static function post_delete($args) {
		$path = $args['path'];
		if(substr($path, 0, 1) === '/') {
			$path = substr($path, 1);
		}
		$preview = new Preview(\OC_User::getUser(), 'files/', $path);
		$preview->deleteAllPreviews();
	}

	public static function isMimeSupported($mimetype) {
		//check if there are preview backends
		if(empty(self::$providers)) {
			self::initProviders();
		}

		//remove last element because it has the mimetype *
		$providers = array_slice(self::$providers, 0, -1);
		foreach($providers as $supportedMimetype => $provider) {
			if(preg_match($supportedMimetype, $mimetype)) {
				return true;
			}
		}
		return false;
	}
}