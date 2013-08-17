<?php

namespace OCA\files\lib;

class Helper
{
	public static function buildFileStorageStatistics($dir) {
		$l = new \OC_L10N('files');
		$maxUploadFilesize = \OCP\Util::maxUploadFilesize($dir);
		$maxHumanFilesize = \OCP\Util::humanFileSize($maxUploadFilesize);
		$maxHumanFilesize = $l->t('Upload') . ' max. ' . $maxHumanFilesize;

		// information about storage capacities
		$storageInfo = \OC_Helper::getStorageInfo();

		return array('uploadMaxFilesize' => $maxUploadFilesize,
					 'maxHumanFilesize'  => $maxHumanFilesize,
					 'usedSpacePercent'  => (int)$storageInfo['relative']);
	}

	/**
	 * Comparator function to sort files alphabetically and have
	 * the directories appear first
	 * @param array $a file
	 * @param array $b file
	 * @return -1 if $a must come before $b, 1 otherwise
	 */
	public static function fileCmp($a, $b) {
		if ($a['type'] === 'dir' and $b['type'] !== 'dir') {
			return -1;
		} elseif ($a['type'] !== 'dir' and $b['type'] === 'dir') {
			return 1;
		} else {
			return strnatcasecmp($a['name'], $b['name']);
		}
	}

	/**
	 * Retrieves the contents of the given directory and
	 * returns it as a sorted array.
	 * @param string $dir path to the directory
	 * @return array of files
	 */
	public static function getFiles($dir) {
		$content = \OC\Files\Filesystem::getDirectoryContent($dir);
		$files = array();

		foreach ($content as $i) {
			$i['date'] = \OCP\Util::formatDate($i['mtime']);
			if ($i['type'] === 'file') {
				$fileinfo = pathinfo($i['name']);
				$i['basename'] = $fileinfo['filename'];
				if (!empty($fileinfo['extension'])) {
					$i['extension'] = '.' . $fileinfo['extension'];
				} else {
					$i['extension'] = '';
				}
			}
			$i['directory'] = $dir;
			$files[] = $i;
		}

		usort($files, array('\OCA\files\lib\Helper', 'fileCmp'));

		return $files;
	}

	/**
	 * Splits the given path into a breadcrumb structure.
	 * @param string $dir path to process
	 * @return array where each entry is a hash of the absolute
	 * directory path and its name
	 */
	public static function makeBreadcrumb($dir){
		$breadcrumb = array();
		$pathtohere = '';
		foreach (explode('/', $dir) as $i) {
			if ($i !== '') {
				$pathtohere .= '/' . $i;
				$breadcrumb[] = array('dir' => $pathtohere, 'name' => $i);
			}
		}
		return $breadcrumb;
	}
}
