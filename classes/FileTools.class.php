<?php
class FileTools {
	/**
	 * Pretty much a FilesystemIterator with a limit
	 */
	static function rglob_gen($startfolder,$pat,$depthlimit=10) {
		$afterPattern = $pat;

		echo "rglob_gen: Searching for $afterPattern in $startfolder with depth limit $depthlimit...\n";
		
		$recursiveDir = function($dir, $depthlimit) use (&$recursiveDir, $afterPattern) {
			if (!is_dir($dir)) return;
			
			$files = glob("$dir/$afterPattern", GLOB_NOSORT);
			foreach ($files as $file) {
				if (is_file($file)) yield $file;
			}
			
			if ($depthlimit <= 0) return; // don't go deeper
			$subdirs = glob("$dir/*", GLOB_ONLYDIR | GLOB_NOSORT);
			foreach ($subdirs as $subdir) {
				foreach ($recursiveDir($subdir, $depthlimit - 1) as $file) {
					yield $file;
				}
			}
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
}