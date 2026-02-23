<?php

/**
 * Set of utilities to comb through arbitrary files for telemetry data.
 * Each datapoint is extracted by a Lua script, has a "type" and "time" field.
 * 
 */
class TelemetryScrape extends Telemetry {
	static function init() {
		parent::init();
		// any local inits?
	}

	static function config($cfg=[]) {
		parent::config($cfg);

		$configfile = (array)(@include "config-scrape.inc.php"); // load scraping defaults
		self::$CFG = self::merge_configs(self::$CFG, $configfile);
	}

	static function filter_younger_files($files, $days_old) {
		$time_limit = time() - ($days_old * DAY);
		return array_values(array_filter($files, function($f) use ($time_limit) {
			return filemtime($f) >= $time_limit;
		}));
	}
	
	static function find_files($path, $filemask, $loud=false) {
		$files = self::rglob($path."/**/".$filemask, 10);
		return array_values($files);



		/*
		$find_cmd = "find ".escapeshellarg($path)." ".($days_old ? "-mtime -$days_old " : "")." -name ".escapeshellarg($filemask);
		$proc = popen($find_cmd, 'r');
		if (!$proc) throw new Exception("Failed to execute find command");
		$files = [];
		echo "\n\x1b[1A;";
		$t=0;
		while ($line = fgets($proc)) {
			$files[] = trim($line);
			$c = count($files);
			if ($loud && $c%100==0 && time()>=$t+1) { echo "\r$c"; $t=time(); }
		}
		echo "\r          \r";
		pclose($proc);
		return $files;
		*/
	}

	static function fetch_last_mtimes($flavour, $files) {
		$slice=100; $qs=0;
		$last_mtimes = [];
		for ($ffi=0;$ffi<count($files);$ffi+=$slice) {
			$batch = array_slice($files,$ffi,$slice);
			$batch = array_map(function($f) use ($flavour) { $f=self::split_filename($f)[1]; return $flavour."/".$f; }, $batch);  // flavour/user/bnet
			$last_mtimes_batch = self::db_get_svfile_mtimes($batch);
			$qs++;
			if ($last_mtimes_batch) $last_mtimes += $last_mtimes_batch;
		}
		self::$DBG['mtime_queries'] = $qs;
		return $last_mtimes;
	}

	static function group_ranges($arr) {
		// join consecutive yyyymmdd values with "-", replacing 3,4,5,6,7 with "3-7"
		$grouped = [];
		$current_range = [];
		foreach ($arr as $value) {
			$last = end($current_range);
			if (!$last) {
				$current_range = [$value];
			} elseif ($value == $last + 1) {
				$current_range[] = $value;
			} elseif (($last%10000== 131 && $value%10000== 201)
			       || ($last%10000== 228+(floor($value/10000)%4==0?1:0) && $value%10000== 301)
				   || ($last%10000== 331 && $value%10000== 401)
				   || ($last%10000== 430 && $value%10000== 501)
				   || ($last%10000== 531 && $value%10000== 601)
				   || ($last%10000== 630 && $value%10000== 701)
				   || ($last%10000== 731 && $value%10000== 801)
				   || ($last%10000== 831 && $value%10000== 901)
				   || ($last%10000== 930 && $value%10000==1001)
				   || ($last%10000==1031 && $value%10000==1101)
				   || ($last%10000==1130 && $value%10000==1201)
				   || ($last%10000==1231 && $value%10000==0101)) {
				$current_range[] = $value;
			} else {
				$grouped[] = $current_range;
				$current_range = [$value];
			}
		}
		if (!empty($current_range)) {
			$grouped[] = $current_range;
		}

		// Format the ranges
		$formatted = [];
		foreach ($grouped as $range) {
			if (count($range) > 1) {
				$formatted[] = $range[0] . "-" . end($range);
			} else {
				$formatted[] = $range[0];
			}
		}
		return $formatted;
	}

	/**
	 * Pretty much a FilesystemIterator with a limit
	 */
	static function rglob_gen($startfolder,$pat,$depthlimit=10) {
		$afterPattern = $pat;
		
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

	/**
	 * Helper generator to yield batches of items from an iterable.
	 */
	static function get_batches($iterable, $batch_size) {
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

	static function get_fresh_files_gen($topic, $filemask, $batch_size=20) {
		$files_gen = self::rglob_gen($filemask,10);
		$file_batches_gen = self::get_batches($files_gen, $batch_size);
		foreach ($file_batches_gen as $batch) {
			$filenames = array_map(function($f) { return self::split_filename($f)[1]; }, $batch);
			foreach (self::get_file_times_generic_gen($topic, $filenames) as $times) {
				yield $times;
			}
		}
	}

	static function get_file_times_generic_gen($topic, $filenames, $batch_size=20) {
		for ($i = 0; $i < count($filenames); $i += $batch_size) {
			$batch = array_slice($filenames, $i, $batch_size);
			$results = self::get_file_times_generic($topic, $batch);
			if ($results) {
				foreach ($results as $row) {
					yield $row;
				}
			}
		}
	}

	// Tests, DB schemas
	static function get_file_times_generic($topic, $filenames) {
		$q = self::qesc($_q="SELECT * FROM `topic_times_file`,`files` WHERE `topic_times_file`.`fileid`=`files`.`id` AND `topic`={s} AND `filename` IN ({sa})", $topic, $filenames);
		$r = self::$db->query($q);
		if (!$r) throw new ErrorException("DB error in $_q: ".self::$db->error);
		if ($r->num_rows) {
			$row = $r->fetch_assoc();
			return $row;
		}
		return null;
	}
	
	static function set_file_times_generic($topic, $filetype, $fileid, $scrape_time=null, $mtime=null) {
		$q = self::qesc($_q="INSERT INTO topic_times_file (topic, filetype, fileid, scrape_time, mtime) VALUES ({s}, {s}, {d}, {d}, {d}) ON DUPLICATE KEY UPDATE scrape_time=VALUES(scrape_time), mtime=VALUES(mtime)", $topic, $filetype, $fileid, $scrape_time ?: time(), $mtime ?: time());
		$r = self::$db->query($q);
		if (!$r) throw new ErrorException("DB error, query $_q: ".self::$db->error);
		return $r;
	}

	/**
	 * Create generic scraping-related tables. None so far.
	 */
	static function db_create() {
		parent::db_create();
	}

	/**
	 * Create the topic_seen_file table, which is used to track which files have been processed for each topic, to avoid reprocessing them.
	 * Every scraper flavour should use this to mark which files it has processed, and skip files that are already marked as seen.
	 */

	static function db_create_topic_seen_file() {
		self::$db->query("SHOW CREATE TABLE topic_seen_file;");
		if (self::$db->error) {
			$schema_sql =
				"CREATE TABLE `topic_seen_file` (
					`topic` char(10) NOT NULL,
					`fileid` int(10) NOT NULL,
					`scrape_time` int(11) DEFAULT NULL,
					`mtime` int(11) DEFAULT NULL,
					UNIQUE KEY `topic_file` (`topic`, `fileid`)
				)
				ENGINE=InnoDB
				DEFAULT CHARSET=latin1
				COLLATE=latin1_swedish_ci
				COMMENT='used to mark which SV files have been processed and when';
			";
			self::$db->query($schema_sql);
			if (self::$db->error) 
				throw new ErrorException("Failed to create table `topic_seen_file`: ".self::$db->error);
		}

		self::vlog("DB schema created.");
	}

	static function self_tests() {
		parent::self_tests();

		$count=0;
		foreach (self::rglob_gen("mock_storage","*.lua*",10) as $i=>$f) {
			$count++;
		}
		if ($count<50) throw new ErrorException("rglob_gen self-test failed, found only $count files in mock_storage, expected at least 50.");
	}

}
