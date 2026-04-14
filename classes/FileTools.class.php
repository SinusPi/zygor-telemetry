<?php
namespace Zygor\Telemetry;

class FileTools {
	/**
	 * Pretty much a FilesystemIterator with a limit
	 */
	static function rglob_gen($startfolder,$pat,$depthlimit=10) {
		$afterPattern = $pat;

		echo "rglob_gen: Searching for $afterPattern in $startfolder with depth limit $depthlimit...\n";
		
		$recursiveDir = function($dir, $depthlimit) use (&$recursiveDir, $afterPattern) {
			if (!is_dir($dir)) return;
			
			$handle = @opendir($dir);
			if (!$handle) return;
			
			// Scan directory: yield matching files, dive into subdirectories
			while (false !== ($entry = readdir($handle))) {
				if ($entry === '.' || $entry === '..') continue;
				
				$fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
				if (is_file($fullPath)) {
					if (fnmatch($afterPattern, $entry)) {
						yield $fullPath;
					}
				} elseif (is_dir($fullPath)) {
					// Recurse into subdirectories
					if ($depthlimit > 0) {
						foreach ($recursiveDir($fullPath, $depthlimit - 1) as $file) {
							yield $file;
						}
					}
				}
			}
			
			closedir($handle);
			
		};
		
		foreach ($recursiveDir($startfolder, $depthlimit) as $file) {
			yield $file;
		}
	}
	
	// use glob to find all matching files, allowing ** to recurse into all folders
	/** @deprecated */
	static function __rglob__old($pat) {
		$p = strpos($pat, '**');
		if ($p === false) {
			//echo "$pat: just glob\n";
			return glob($pat);
		}
		$before = substr($pat, 0, $p);
		$after = substr($pat, $p + 3);

		$files = glob($before.$after); // seeking fee/**/bar*fle.txt, try to match fee/bar*fle.txt first
		//echo "plain glob $before.$after = ".count($files)."\n";
		
		$gl = $before === '' ? '*' : "{$before}*";
		$folders = glob($gl,GLOB_ONLYDIR);
		//echo "$pat: glob $gl\n";
		foreach ($folders as $folder) {
			//echo "- rglob $folder/**/$after\n";
			$files = array_merge($files, self::rglob("$folder/**/$after"));
		}
		return $files;
	}

	static function rglob($pat,$limit=10) {
		$p = strpos($pat, '**/');
		if ($p === false) {
			return glob($pat); // quit wasting my time!
		}
		$before = substr($pat, 0, $p);
		$after = substr($pat, $p + 3);
		$files=[];
		for ($i=1;$i<=$limit;$i++) {
			$asterisks = str_repeat("*/", $i);
			$files = array_merge($files, glob("$before$asterisks$after",GLOB_NOSORT));
		}
		return $files;
	}

	/**
	 * Helper generator to yield batches of items from an iterable.
	 */
	static function batchify($iterable, $batch_size) {
		$batch = [];
		foreach ($iterable as $item) {
			$batch[] = $item;
			if (count($batch) >= $batch_size) {
				yield $batch;
				$batch = [];
			}
		}
		if (!empty($batch)) {
			yield $batch;
		}
	}

	/**
	 * Safely load a PHP file that is expected to return an array.
	 * Returns the array, or null if the file does not return an array or fails to parse.
	 */
	static function safely_load_php($filename) {
		try {
			$parse = token_get_all(file_get_contents($filename));
			$file = include $filename;
			if (!is_array($file)) return null;
			return $file;
		} catch (\Exception $e) {
			throw new \Exception("Failed to parse file $filename: ".$e->getMessage()."\n");
		}
	}
}