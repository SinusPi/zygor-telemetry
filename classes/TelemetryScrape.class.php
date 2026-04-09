<?php

use Telemetry as Tm;

/**
 * Set of utilities to comb through arbitrary files for telemetry data.
 * Each datapoint is extracted by a Lua script, has a "type" and "time" field.
 * Base class to be extended by specific scrapers for different sources (e.g. SV, packager logs, etc).
 */
class TelemetryScrape {
	static $logtag = "";

	static $CFG = null; // ref to main config for easy access
	static $config_errors = [];

	static function init() {
	}

	static function startup() {
		if (!Telemetry::is_ready()) throw new Exception("Scrape core: Telemetry core not initialized");
		self::init();
		self::config();
		self::db_create(); // create generic scraping-related tables if not exist
		
		self::startup_scrapers();
	}

	static function config($cfg=[]) {
		self::$CFG = &Telemetry::$CFG;

		$configfile = (array)(@include "config-scrape.inc.php"); // load scraping defaults
		if (!$configfile) throw new ConfigException("Failed to load scrape config file: config-scrape.inc.php");
		self::$CFG->add($configfile,10,"scrape base config");
	}

	/**
	 * Get all subclasses of TelemetryScrape
	 * @return array List of subclass names
	 */
	static function getSubclasses() {
		return array_filter(get_declared_classes(), function($classname) {
			return get_parent_class($classname) === self::class;
		});
	}

	static function list_scrapers() {
		$list = [];
		foreach (self::getSubclasses() as $subclass) {
			if (method_exists($subclass, 'identifySelf')) {
				$info = $subclass::identifySelf();
				if (is_array($info)) {
					$info['class'] = $subclass;
					$list[$info['key']] = $info;
				}
			}
		}
		return $list;
	}

	static function startup_scrapers() {
		foreach (self::getSubclasses() as $subclass) {
			if (method_exists($subclass, 'startup')) {
				try {
					$subclass::startup();
				} catch (Exception $e) {
					Logger::log("Startup error in scraper $subclass: ".$e->getMessage());
					throw $e;
				}
			}
		}
	}









	static function filter_younger_files($files, $days_old) {
		$time_limit = time() - ($days_old * DAY);
		return array_values(array_filter($files, function($f) use ($time_limit) {
			return filemtime($f) >= $time_limit;
		}));
	}
	
	static function find_files($path, $filemask, $loud=false) {
		$files = FileTools::rglob($path."/**/".$filemask, 10);
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
			$batch = array_map(function($f) use ($flavour) { $f=Tm::split_filename($f)[1]; return $flavour."/".$f; }, $batch);  // flavour/user/bnet
			$last_mtimes_batch = self::db_get_svfile_mtimes($batch);
			$qs++;
			if ($last_mtimes_batch) $last_mtimes += $last_mtimes_batch;
		}
		Tm::$DBG['mtime_queries'] = $qs;
		return $last_mtimes;
	}

	/**
	 * Generator that yields only files "fresh" for given topic(s) (with mtimes newer than the last recorded scrape time for that topic)
	 * @param string[] $topics list of topic names to check freshness
	 * @param string $startfolder folder to start searching in
	 * @param string $filemask glob pattern to match files, e.g. "*.lua*"
	 * @param string $prefix prefix replacing $startfolder in the file paths when looking up in DB
	 * @yield File $file
	 */
	static function get_fresh_files_gen($topics, $startfolder, $filemask, $cb_slugger, $filetype, $batch_size=20) {
		Logger::vlog("Finding files in $startfolder matching $filemask...");
		$files_gen = FileTools::rglob_gen($startfolder,$filemask,10);
		$file_batches_gen = FileTools::batchify($files_gen, $batch_size);
		foreach ($file_batches_gen as $batch) {
			Logger::vlog("* Processing batch of ".count($batch)." files...");
			// filenames in batch are full; need to shorten for DB
			// add prefix to batch items, e.g. "flavour/filename"
			$batch_slugs = array_map($cb_slugger,$batch);
			$files = Tm::$db->get_files($batch_slugs,$filetype,true); // same order maintained
			$ids = array_map(function($f) { return $f->id ?: null; }, $files);
			if (self::$CFG['ignore-mtimes']) {
				Logger::vlog("- Ignoring mtimes for freshness check (config ignore-mtimes=true)");
				// fill array: [$ids]=>-1
				$batch_scrapetimes = array_map(function($id) { return ['topics' => [], 'newest_scrape_time' => 0]; }, $ids);
			} else {
				$batch_scrapetimes = self::get_file_scrapetimes_batch($topics,$ids);
			}
			
			foreach ($files as $i=>$file) {
				// add full file names and mtimes to results for easier filtering below
				$file->fullpath = $batch[$i];
				$file->topics = $batch_scrapetimes[$i]['topics'] ?: [];
				$file->mtime = filemtime($file->fullpath);
				
				// determine freshness: is file mtime newer than scrape time for any of the topics?
				foreach ($topics as $topic) {
					$file->topics[$topic]['fresh'] = $file->mtime > ($file->topics[$topic]['scrape_time'] ?: 0);
					if ($file->topics[$topic]['fresh'])
						$file->any_fresh = true;
				}

				if (!$file->any_fresh) {
					Logger::vlog("- File {$file->slug} is NOT fresh for any of the topics (mtime: ".Tm::dt($file->mtime).", scraped: ".join(", ", array_map(function($t) use ($file) { return "$t=".Tm::dt($file->topics[$t]['scrape_time'] ?: 0); }, $topics))."), skipping.");
				} else {
					// list fresh topics with mtimes
					$fresh_topics = array_filter($topics, function($t) use ($file) { return $file->topics[$t]['fresh']; });
					$mtime = $file->mtime;
					Logger::vlog("- File {$file->slug} (".Tm::dt($mtime).") is fresh for topics: ".join(", ", array_map(function($t) use ($file) { return "$t (".Tm::dt($file->topics[$t]['scrape_time'] ?: 0).")"; }, $fresh_topics)));
					
					yield $file;
				}
			}
			Logger::vlog("* Batch complete.");
		}
	}

	/**
	 * Get scrape times for multiple files and topics in one query, returning an array of scrape times grouped by file_id
	 * @return array [ file_id => [ 'topics' => [ topic:string => [last_event_time:int, scrape_time:int] ], 'newest_scrape_time' => int ] ]
	 */
	static function get_file_scrapetimes_batch($topics, $ids) {
		$r = Tm::$db->query($_q="SELECT * FROM `topic_scrapetimes` WHERE `topic` IN ({sa}) AND `file_id` IN ({sa})", $topics, $ids);
		if (!$r) throw new ErrorException("DB error in $_q: ".Tm::$db->error());

		// aggregate by file_id
		$files = [];
		while ($row = $r->fetch_assoc())
			$files[$row['file_id']]['topics'][$row['topic']] = [
				'scrape_time' => $row['scrape_time'],
				'last_event_time' => $row['last_event_time'],
			];
		
		// add field for newest scrape time for this file across all topics, to make it easier to filter out old files in the generator
		foreach ($files as &$data) {
			$data['newest_scrape_time'] = max(array_column($data['topics'], 'scrape_time'));
		} unset($data);

		// reorder by ids
		$results = array_map(function($id) use ($files) {
			return $files[$id] ?: ['topics' => [], 'newest_scrape_time' => -1];
		}, $ids);
		return $results;
	}

	/**
	 * Update scrape times for multiple topics on a file
	 */
	static function db_set_file_scrapetimes ($topics, $file_id, $last_events_per_topic=[], $scrape_time=null) {
		$values_sql = join(", ", array_map(function($topic) use ($file_id, $last_events_per_topic, $scrape_time) {
			return Tm::$db->qesc("({s},{d},{d},{d})", $topic, $file_id, $last_events_per_topic[$topic] ?: null, $scrape_time ?: time());
		}, $topics));
		$r = Tm::$db->query($_q="INSERT INTO `topic_scrapetimes` (topic, file_id, last_event_time, scrape_time) VALUES $values_sql ON DUPLICATE KEY UPDATE last_event_time=VALUES(last_event_time), scrape_time=VALUES(scrape_time)");
		if (!$r) throw new ErrorException("DB error, query $_q: ".Tm::$db->error());
		Logger::vlog("Updated scrape times for file_id $file_id and topics: scrape time ".Tm::dt($scrape_time).", ".join(", ",array_map(function($t) use ($last_events_per_topic) { return $t."=".Tm::dt($last_events_per_topic[$t] ?: 0); }, $topics)));
	}

	/**
	 * Update one topic scrape time for a file
	 */
	static function db_set_file_scrapetime ($topic, $file_id, $last_event_time=null, $scrape_time=null) {
		$q = Tm::$db->qesc($_q="INSERT INTO `topic_scrapetimes` (topic, file_id, last_event_time, scrape_time) VALUES ({s}, {d}, {d}, {d}) ON DUPLICATE KEY UPDATE scrape_time=VALUES(scrape_time), last_event_time=VALUES(last_event_time)", $topic, $file_id, $last_event_time ?: null, $scrape_time ?: time());
		$r = Tm::$db->query($q);
		if (!$r) throw new ErrorException("DB error, query $_q: ".Tm::$db->error());
	}

	// Tests, DB schemas

	static function self_tests() {
		foreach (self::getSubclasses() as $subclass) {
			if (method_exists($subclass, 'self_test')) {
				$subclass::self_test();
			}
		}
	}

	/**
	 * Create generic scraping-related tables.
	 */
	private static function db_create() {
		self::db_create_topic_scrapetimes();
	}

	/**
	 * Create the topic_seen_file table, which is used to track which files have been processed for each topic, to avoid reprocessing them.
	 * Every scraper flavour should use this to mark which files it has processed, and skip files that are already marked as seen.
	 */

	private static function db_create_topic_scrapetimes() {
		$result = (new SchemaManager(Tm::$db->conn))->manageTable("topic_scrapetimes",[
			'1'=> "CREATE TABLE `topic_scrapetimes` (
					`topic` char(10) NOT NULL,
					`file_id` int(10) NOT NULL,
					`scrape_time` int(11) DEFAULT NULL,
					`last_event_time` int(10) DEFAULT NULL,
					UNIQUE KEY `topic_file` (`topic`, `file_id`)
				)
				ENGINE=InnoDB
				DEFAULT CHARSET=latin1
				COLLATE=latin1_swedish_ci
				COMMENT='used to mark which files have been processed and when'"
			]);
		if ($result && $result['status'] === 'migrated') Logger::vlog("DB: 'topic_scrapetimes' table created or migrated to version ".$result['target_version']);
	}

		}
	}

}
