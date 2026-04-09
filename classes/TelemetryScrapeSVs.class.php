<?php

use Telemetry as Tm;
use TelemetryStatus as TmSt;

/**
 * Set of utilities to comb through user-supplied SV files for telemetry data.
 * Each datapoint is extracted by a Lua script, has a "type" and "time" field.
 * 
 * Input files are logged into 'sv_files' table, with last modified time and last scraped time to only rescrape changed files.
 * Extracted datapoints are stored into 'events' table, with foreign key to sv_files table.
 */
class TelemetryScrapeSVs extends TelemetryScrape {
	static $config_errors = [];

	static function init() {
	}

	static function config($cfg=[]) {
		self::$CFG = &Telemetry::$CFG;

		$configfile = (array)(@include "config-scrape-sv.inc.php"); // load defaults
		if (!$configfile) throw new ConfigException("Failed to load config-scrape-sv.inc.php");

		self::$CFG->add($configfile,12,"scrape sv config");

		if (!isset(self::$CFG['SV_STORAGE_ROOT'])) throw new ConfigException("SV_STORAGE_ROOT not set");
		if (!is_dir(self::$CFG['SV_STORAGE_ROOT'])) throw new ConfigException("SV_STORAGE_ROOT is not a valid directory: ".self::$CFG['SV_STORAGE_ROOT']);
		
		$svsync_config_path = self::$CFG['SV_STORAGE_ROOT']."/config.inc.php";
		if (!file_exists($svsync_config_path)) throw new ConfigException("SV Sync config not found at: ".$svsync_config_path);

		// load sync's config
		@include $svsync_config_path; // defines SYNC_CFG
		if (!isset($SYNC_CFG)) throw new ConfigException("SV Sync config invalid in ".$svsync_config_path);
		self::$CFG->add(['SV_STORAGE_DATA_PATH'=>Telemetry::cfgstr('SV_STORAGE_DATA_PATH',['SYNC_FOLDER'=>$SYNC_CFG['folder']])],13,"scrape sv: storage path");
		if (!is_dir(self::$CFG['SV_STORAGE_DATA_PATH'])) throw new ConfigException("Missing SV storage folder: ".self::$CFG['SV_STORAGE_DATA_PATH']);

		if ($missing_flavours=array_filter(array_map(function($flav) { $path = Tm::cfgstr('SV_STORAGE_FLAVOUR_PATH',['FLAVOUR'=>$flav]); return !is_dir($path) ? $path : null; }, array_keys(self::$CFG['WOW_FLAVOUR_DATA'])))) {
			throw new ConfigException("Missing SV storage flavour folders: ".join(", ",$missing_flavours));
		}

	}

	static function startup() {
		self::init();
		self::config();
	}

	static function identifySelf() {
		return [
			'key' => 'sv',
			'label' => 'Saved Variables',
			'description' => 'User-submitted game client saved variables',
		];
	}

	/**
	 * Get configured paths for this scraper
	 * @return array Array of configured storage paths
	 */
	static function getConfiguredPaths() {
		try {
			$paths = [];
			if (isset(self::$CFG['SV_STORAGE_ROOT'])) {
				$paths[] = self::$CFG['SV_STORAGE_ROOT'];
			}
			if (isset(self::$CFG['SV_STORAGE_DATA_PATH'])) {
				$paths[] = self::$CFG['SV_STORAGE_DATA_PATH'];
			}
			return $paths;
		} catch (Exception $e) {
			return [];
		}
	}

	static function filter_younger_files($files, $days_old) {
		$time_limit = time() - ($days_old * DAY);
		return array_values(array_filter($files, function($f) use ($time_limit) {
			return filemtime($f) >= $time_limit;
		}));
	}
	
	/**
	 * Grab data from SVs, store into db
	 * @param string $flavour
	 * @param string[] $topics
	 */
	static function scrape($flavour, $topics_selected=[]) {
		if (!Telemetry::is_ready()) throw new Exception("Telemetry core not initialized");
		return self::scrape2($flavour, $topics_selected); // new scrape method with generator for files, but keep old one for now for comparison and safety

		// THE REST IS DEPRECATED

		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) throw new ErrorException("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

		self::$logtag = "SCRAPE-".strtoupper(str_replace("-","_", $flavour));
		$status = TmSt::get_status(self::$logtag, true);
		if ($status['status']=="SCRAPING") {
			Logger::log("Another scrape for flavour '\x1b[38;5;78m{$flavour}\x1b[0m' is already in progress, aborting.");
			return;
		}

		/** @var Topic[] $topics */
		$topics = Telemetry::$TOPICS;
		if ($topics_selected) $topics = array_filter($topics, function($t,$name) use ($topics_selected) { return in_array($name, $topics_selected); }, ARRAY_FILTER_USE_BOTH);
		$topics = array_filter($topics, function($t) { return (isset($t->scraper['input']) && $t->scraper['input'] == "sv"); });

		$sync_path = Tm::cfgstr('SV_STORAGE_FLAVOUR_PATH',["FLAVOUR"=>$flavour]);

		Logger::log("Starting scrape of flavour '\x1b[38;5;78m{$flavour}\x1b[0m' topics ".implode(", ", array_map(function($t) { return $t->name; }, $topics))." in \x1b[33;1m{$sync_path}\x1b[0m.");

		TmSt::stat(['status'=>"ENUMERATING",'stage'=>1,'stageof'=>2,'flavour'=>$flavour,'progress'=>[],'time_started'=>time(),'time_started_hr'=>date("Y-m-d H:i:s")]);		

		Logger::log("Enumerating files matching ".self::$CFG['filemask']);
		
		$t1 = microtime(true);
		$files = self::find_files($sync_path, self::$CFG['filemask'], true); // FINDING FILES. TAKES LONG. We're not expecting filecount to exceed memory... yet...
		$total_filecount = count($files);
		Logger::vlog("Found $total_filecount files [".round(microtime(true)-$t1,2)."s]");

		if (isset(self::$CFG['TELEMETRY_FILE_AGE'])) {
			$days_old = intval(self::$CFG['TELEMETRY_FILE_AGE']/DAY)+1; // old way of detecting file age
			$files = self::filter_younger_files($files, $days_old);
			$t1 = microtime(true);
			Logger::vlog("Filtered out files older than $days_old days: {$total_filecount} -> ".count($files)." files [".round(microtime(true)-$t1,2)."s]");
		}

		while ($files[0]==="") array_shift($files);
		//$files = str_replace($sync_path."/","",$files);

		TmSt::stat(['files_total'=>count($files)],true);
		Logger::log(count($files)." files to process.");

		if (!count($files)) {
			Logger::log("Nothing to do here.");
			TmSt::stat(['status'=>"IDLE"]);
			return;
		}

		$freshfiles = $files; //array_values(array_filter($files,function($f) { return NOW-filemtime($f)<=TELEMETRY_INTERVAL; }));

		$freshhashes = array_map(function($f) use ($flavour) { return substr(md5($flavour."/".$f),0,8); },$freshfiles);
		$counts = array_count_values($freshhashes);
		$dupes = array_filter($counts,function($c) { return $c>1; });
		if (count($dupes)) {
			TmSt::stat(['status'=>"ERROR",'error'=>"Duplicate files found, see log."]);
			throw new ErrorException(count($dupes)." duplicate files found (same name in different folders): ".join(", ",array_keys($dupes)).". They will be processed only once.");
		}

		TmSt::stat(['status'=>"EXTRACTING",'files_fresh'=>count($freshfiles),'files_skipped'=>0,'tmfiles_skipped'=>0,'tmfiles_written'=>0,'tmfiles_last'=>"",'file_last'=>"",'not_files'=>0,'broken_lua'=>0]);

		//Logger::log(count($freshfiles)." of them are fresh enough (".intval(TELEMETRY_INTERVAL/DAY)." days).");

		//$telefolder = Tm::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour]);
		//$last_scrape_dates = (array)@json_decode(@file_get_contents($telefolder."/".self::$CFG['MTIMES_CACHE_FILENAME'])); // names relative to sync folder
		$t1 = microtime(true);
		$last_mtimes = self::fetch_last_mtimes($flavour, $freshfiles);
		$t2 = microtime(true);
		Logger::vlog("Loaded ".count($last_mtimes)." last scrape dates from DB in ".Telemetry::$DBG['mtime_queries']." queries [".round($t2-$t1,2)."s]");
		
		$totals=[];
		$first_day_relevant = date("Ymd",time()-self::$CFG['TELEMETRY_DATA_AGE']);
		Logger::log("We check for data max \x1b[1m".round(self::$CFG['TELEMETRY_DATA_AGE']/86400)."\x1b[0m days old (>= \x1b[38;5;118m$first_day_relevant\x1b[0m)");

		
		// prepare list of files to actually process

		$freshfiles_to_process = [];

		foreach ($freshfiles as $n=>$filename_full) {
			if (!$filename_full) continue;
			if (!is_file($filename_full)) {
				$totals['not_file']=$filename_full;
				$totals['not_files']++;
				continue;
			}

			// $filename_full is a full path, chop it
			list ($filename_userfile, $filename_slug) = Telemetry::split_filename($filename_full);
			$flavourslug = $flavour."/".$filename_slug; // flavour/user/bnet

			if (self::$CFG['filematch'] && strpos($filename_userfile,self::$CFG['filematch'])!==FALSE) continue; //skip

			TmSt::stat(['file_last'=>$filename_userfile],true);

			if (!self::$CFG['ignore-mtimes'] && isset($last_mtimes[$flavourslug]) && filemtime($filename_full) <= $last_mtimes[$flavourslug]) {  // do not re-scrape if the file hasn't been updated
				$totals['files_skipped']++;
				//$totals['files_skipped_last_why'] = date("Ymd",filemtime($full_filename))."<=".date("Ymd",$last_mtimes[$filename_full]);
				continue; //skip
			}

			$freshfiles_to_process[]=$filename_full;
		}
	
		Logger::log(sprintf(
			"Processing %d files (%s%s%d not changed since scrape).",
			count($freshfiles_to_process),
			$totals['files_skipped'] ? $totals['files_skipped'] . " skipped, " : "",
			$totals['not_files'] ? $totals['not_files'] . " not files, " : "",
			count($freshfiles) - count($freshfiles_to_process)
		));
		
		unset($freshfiles);

		$totals['inserted_datapoints'] = 0;


		// PRE OPT!!
		
		/*

		// process in chunks, for concurrency. Calculate last file in chunk.
		$chunk_size = 3; // number of userfolders to process in parallel
		$start_n = 0;
		$chunks = [];
		while ($start_n < count($freshfiles_to_process)) {
			// grab $chunk_size base folders, however many files they have
			$base_folder_count = 0;
			$userfolder_last = basename(dirname($freshfiles_to_process[$start_n]));
			$n = $start_n+1;
			// proceed with $n, stop when userfolder changes $chunk_size times
			// $n is now at the second file of the next chunk
			while ($n < count($freshfiles_to_process)) {
				$userfolder_cur = basename(dirname($freshfiles_to_process[$n]));
				if ($userfolder_cur != $userfolder_last) {
					$base_folder_count++;
					$userfolder_last = $userfolder_cur;
					if ($base_folder_count >= $chunk_size) break;
				}
				$n++;
			}
			$chunks[] = [$start_n, $n-1, basename(dirname($freshfiles_to_process[$start_n])), basename(dirname($freshfiles_to_process[$n-1]))]; // [first_file_index, last_file_index, first_file_fullpath, last_file_fullpath]
			$start_n = $n;
		}

		*/

		foreach ($freshfiles_to_process as $n => $filename_full) {
			self::process_single_sv_file($flavour, ['filename_full'=>$filename_full], $topics, $totals);

			// obey limit
			if (isset(self::$CFG['limit']) && $n>=self::$CFG['limit']-1) {
				Telemetry::$db->commit();
				throw new ErrorException("Limit ".self::$CFG['limit']." hit, aborting.\n");
			}

			// update progress
			TmSt::update_progress(self::$logtag,$n,count($freshfiles_to_process),['totals'=>$totals],self::$CFG['verbose']);

		}

		//self::write_intermediate_mtimes($flavour,$last_scrape_dates,true);
		$tot1 = array_filter($totals,function($v) { return is_numeric($v); });
		$tots = array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));
		Logger::log("Scrape of $flavour complete; ".implode(", ",$tots));

		if (count($totals['files_without_zgvs'])/(count($freshfiles_to_process)-$totals['files_skipped'])>0.5)
			Logger::log("Weird. Out of ".(count($freshfiles_to_process)-$totals['files_skipped'])." files read, ".count($totals['files_without_zgvs'])." had no ZGVs.");

		TmSt::stat(['status'=>"IDLE"]);
	}

	/**
	 * Grab data from SVs, store into db
	 * @param string $flavour
	 */
	static function scrape2($flavour, $topics_selected=[]) {
		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) throw new ErrorException("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

		self::$logtag = "SCRAPE-".strtoupper(str_replace("-","_", $flavour));
		$status = TmSt::get_status(self::$logtag, true);
		if ($status['status']=="SCRAPING") {
			Logger::log("Another scrape for flavour '\x1b[38;5;78m{$flavour}\x1b[0m' is already in progress, aborting.");
			return;
		}

		/** @var Topic[] $topics_sv */
		$topics_sv = array_filter(Telemetry::$TOPICS, function($t) { return (isset($t->scraper['input']) && $t->scraper['input'] == "sv"); }, ARRAY_FILTER_USE_BOTH);
		if ($topics_selected) $topics_sv = array_filter($topics_sv, function($t,$name) use ($topics_selected) { return in_array($name, $topics_selected); }, ARRAY_FILTER_USE_BOTH);

		$sync_path = Tm::cfgstr('SV_STORAGE_FLAVOUR_PATH',["FLAVOUR"=>$flavour]);

		Logger::log("Starting scrape of flavour '\x1b[38;5;78m{$flavour}\x1b[0m' topics ".implode(", ", array_map(function($t) { return "'".$t->name."'"; }, $topics_sv))." in \x1b[33;1m{$sync_path}\x1b[0m.");

		// get svfiles that may have fresh data for the topics listed
		$gen_fresh_svfiles = self::get_fresh_files_gen(array_keys($topics_sv), $sync_path, self::$CFG['filemask'], [__CLASS__,'file_path_to_slug'], "sv", self::$CFG['BATCH_SIZE']);

		// narrow down per configuration
		$gen_narrowed_svfiles = Telemetry::filter_gen($gen_fresh_svfiles, function($file) {
			// too old, excluded
			if (isset(self::$CFG['TELEMETRY_FILE_AGE']) && (time() - $file->mtime > self::$CFG['TELEMETRY_FILE_AGE']*DAY)) {
				Logger::vlog("-- ".$file->fullpath.": too old for TELEMETRY_FILE_AGE");
				return false;
			}
			// skip today's files if not explicitly included
			if (!self::$CFG['today-too'] && date("Ymd", $file->mtime) == date("Ymd", time())) {
				Logger::vlog("-- ".$file->fullpath.": mtime today, but --today-too is off");
				return false;
			}
			// too old to possibly contain newer data
			// NO. Still need to mark as scraped, even if we ignore data within.
			/*
			if (isset(self::$CFG['start-day']) && $file->mtime < strtotime(self::$CFG['start-day'])) {
				Logger::vlog("-- ".$file->fullpath.": too old for --start-day");
				return false;
			}
			*/
			return true;
		});

		foreach ($gen_narrowed_svfiles as $n => $file) {
			$fresh_topics_in_file = array_filter($topics_sv, function($topic,$name) use ($file) { return $file->topics[$name]['fresh']; },ARRAY_FILTER_USE_BOTH);
			self::process_single_sv_file($flavour, $file, $fresh_topics_in_file, $totals);

			// obey limit
			if (isset(self::$CFG['limit']) && $n>=self::$CFG['limit']-1) {
				Telemetry::$db->commit();
				throw new ErrorException("Limit ".self::$CFG['limit']." hit, aborting.\n");
			}

			// update progress
			//self::update_progress(self::$logtag,$n,count($gen_narrowed_svfiles_2),['totals'=>$totals],self::$CFG['verbose']);
		}

		//self::write_intermediate_mtimes($flavour,$last_scrape_dates,true);
		
		/*
		$tot1 = array_filter($totals,function($v) { return is_numeric($v); });
		$tots = array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));
		Logger::log("Scrape of $flavour complete; ".implode(", ",$tots));

		if (count($totals['files_without_zgvs'])/(count($freshfiles_to_process)-$totals['files_skipped'])>0.5)
			Logger::log("Weird. Out of ".(count($freshfiles_to_process)-$totals['files_skipped'])." files read, ".count($totals['files_without_zgvs'])." had no ZGVs.");
		*/

		TmSt::stat(['status'=>"IDLE"]);
	}

	/** Run all SV-sourced topic scrapers on a single file
	 * @return void
	 */
	static function process_single_sv_file($flavour, $file, $topics, &$totals) {
		$filename_full = $file->fullpath;
		TmSt::stat(['file_last'=>$filename_full],true);
		list ($filename_userfile, $filename_slug) = Telemetry::split_filename($filename_full);
		$bnet = basename($filename_slug);
		$user = basename(dirname($filename_slug));
		$userfolder = dirname($filename_full);

		Logger::vlog("Scraping SV: \x1b[38;5;110m$user\x1b[0m/\x1b[38;5;116m$bnet\x1b[0m\x1b[30;1m--SavedVariables\x1b[0m for topics: ".join(", ", array_keys($topics)));

		$lock_code = $flavour."/".$filename_slug;
		$got_db_lock = false;

		try { // flock block
			if (Telemetry::is_linux()) {
				$fl = fopen($userfolder, 'rb');
				if (!$fl) throw new FileLockedException("Cannot open input folder for locking: $userfolder");
				if (!flock($fl, LOCK_EX | LOCK_NB)) {
					fclose($fl);
					throw new FileLockedException("Input folder locked: $userfolder");
				}
			} else {
				// Windows: use DB locks
				$got_db_lock = Telemetry::$db->lock($lock_code);
				if (!$got_db_lock) {
					throw new FileLockedException("Input folder locked (DB): $lock_code");
				} else {
					//Logger::vlog(microtime(true)." DB lock acquired for $lock_code");
				}
			}

			Logger::vlog(" - :. reading SV file...");

			Telemetry::$db->begin_transaction(); // need to start here to lock the DB record for this file

			$sv_raw = self::read_raw_sv($filename_full);

			Logger::vlog(" - .: extracting datapoints...");

			$extracted = self::extract_datapoints_with_lua($sv_raw,$flavour,$topics);

			// ===============================================================

			// handle errors
			if ($extracted['status'] != "ok") {
				if ($extracted['err'] == "stderr_output") {
					$totals['broken_lua']++;
					echo $filename_full . ": ERROR: " . $extracted['error'];
					// SV+Lua broken, it could mean our extraction failed, or the file was broken in the first place.
					return;
				} elseif ($extracted['err'] == "no_zgvs") {
					$size = filesize($filename_full);
					if ($size > 500) $totals['files_without_zgvs'][] = $filename_userfile; // just log it
					$totals['broken_lua']++;
					return;
				} elseif (!isset($extracted['datapoints'])) {
					throw new ErrorException("ERROR: no datapoints at all (did Lua even run?), reading $filename_full " . print_r($extracted, 1));
				}
			}

			$counts = array_count_values(array_column($extracted['datapoints'], 'type'));
			$times = $extracted['times'];
			Logger::vlog("Datapoints extracted by type: " . join(", ", array_map(function ($item, $key) use ($times) { return "\x1b[38;5;72m$key\x1b[0m:{$item} ({$times[$key]}s)"; }, array_values($counts), array_keys($counts))));

			// strip old data
			{
				$extracted['datapoints'] = array_values(array_filter($extracted['datapoints'], function ($dp) use ($file) {  return $dp['time'] > $file->topics[$dp['type']]['last_event_time'];  })); // only new events
				Logger::vlog("+ removing older than last scraped ".serialize($file->topics).", left ".count($extracted['datapoints']));
			}
			
			// strip by start-day
			if (isset(self::$CFG['start-day'])) {
				$extracted['datapoints'] = array_values(array_filter($extracted['datapoints'], function ($dp) { return $dp['time'] >= strtotime(self::$CFG['start-day']); })); // only new events
				Logger::vlog("+ removing older than start-day ".strtotime(self::$CFG['start-day']).", left ".count($extracted['datapoints']));
			}
			if (isset(self::$CFG['end-day'])) {
				$extracted['datapoints'] = array_values(array_filter($extracted['datapoints'], function ($dp) { return $dp['time'] < strtotime(self::$CFG['end-day']+86400); })); // only new events
				Logger::vlog("+ removing newer than end-day ".strtotime(self::$CFG['end-day']+86400).", left ".count($extracted['datapoints']));
			}

			$newest_per_topic = array_reduce($extracted['datapoints'], function($carry, $dp) {
				if (!isset($carry[$dp['type']]) || $dp['time'] > $carry[$dp['type']]) {
					$carry[$dp['type']] = $dp['time'];
				}
				return $carry;
			}, array_map(function($topic) { return $topic['last_event_time'] ?: 0; }, $file->topics));
			
			$last_event_time = max(array_column($extracted['datapoints'],'time')) ?: 0;
			
			Logger::vlog("Datapoints after filtering out old: ".count($extracted['datapoints']));

			// DB STORE TIME!

			$inserted = Telemetry::$db->store_datapoints(Telemetry::flavnum($flavour),$file->id,$extracted['datapoints']);
			Logger::vlog("Datapoints inserted into DB: $inserted");

			$totals['inserted_datapoints'] += $inserted;

			// $last_event_data = array_filter($extracted['datapoints'], function($dp) use ($last_event_time) { return $dp['time'] == $last_event_time; });
			// print_r($last_event_data);
			// die("LAST EVENT TIME: $last_event_time");

			//self::db_update_sv_file_times($file->id, filemtime($filename_full), NOW, $last_event_time);
			
			self::db_set_file_scrapetimes(array_keys($topics), $file->id, $newest_per_topic, $file->mtime);

			Telemetry::$db->commit();

		} catch (FileLockedException $e) {
			Logger::vlog($e->getMessage()." - $filename_full");
			Telemetry::$db->rollback();
			return;
		} catch (Exception $e) {
			Logger::log(microtime(true)." ERROR processing $filename_full: " . $e->getMessage() . " at stack trace: " . $e->getTraceAsString());
			throw $e;
		} finally {
			// unlock
			if (isset($fl)) { flock($fl, LOCK_UN); fclose($fl); }
			if ($got_db_lock) {
				$unl = Telemetry::$db->unlock($lock_code);
				//Logger::vlog(microtime(true)." DB lock released for $lock_code: ".($unl ? "ok" : "failed"));
			}
		}
	}

	/*
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
		Telemetry::$DBG['mtime_queries'] = $qs;
		return $last_mtimes;
	}
	*/

	/**
	 * Save scraped data for a specific day and user/account.
	 * @deprecated
	 */
	static function __save_day_scrape($flavour,$day,$user,$acct, $daydata, &$totals) {
		if (!$daydata) 
			return ++$totals['tmfiles_empty'];
		$scrape_folder = Tm::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
		mkdir($scrape_folder,0777,true);
		if (!is_dir($scrape_folder)) throw new ErrorException("Failed to create/access scrape folder at $scrape_folder");
		$scrape_file = "{$user}@{$acct}.json";
		$scrape_filepath = $scrape_folder."/".$scrape_file;
		if (file_exists($scrape_filepath))
			return $totals['tmfiles_skipped']++;

		//Logger::vlog("Saving ".count($daydata)." scrapes into \x1b[33;1m$scrape_filepath\x1b[0m");

		file_put_contents($scrape_filepath,json_encode($daydata),LOCK_EX);

		foreach ($daydata as $line) $totals['types'][$line['type']]++;
		$totals['tmfiles_written']++;
		$totals['tmfiles_last']=$scrape_filepath;
	}


	/** @deprecated */
	static function __write_intermediate_mtimes($flavour,$last_scrape_dates,$force=false) {
		static $time_last_mtimes=0;
		if ($force || time()-$time_last_mtimes >= self::$CFG['MTIMES_WRITE_INTERVAL']) {
			$mtimes_cache_filename = Tm::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour])."/".self::$CFG['MTIMES_CACHE_FILENAME'];
			$f=file_put_contents($mtimes_cache_filename,json_encode($last_scrape_dates),LOCK_EX);
			if (!$f) throw new ErrorException("Cannot write mtimes cache");
			$time_last_mtimes = time();
		}
	}

	static function read_raw_sv($filename) {
		// read gzipped SV file
		if (!file_exists($filename)) throw new ErrorException("TelemetryScrapeSVs::read_raw_sv: File not found: $filename");
		$fp = gzopen($filename, 'rb');
		if (!$fp) throw new ErrorException("TelemetryScrapeSVs::read_raw_sv: Cannot open gzipped file: $filename");
		$sv_raw = '';
		while (!gzeof($fp)) {
			$sv_raw .= gzread($fp, 100000);
		}
		gzclose($fp);

		return $sv_raw;
	}

	/** 
	* @param string $sv_raw 
	* @param string $flavour 
	* @param array $datapoint_defs
	* @return array ['status'=>"ok",'datapoints'=>[...], 'times'=>[...] ] or ['status'=>"err", 'err'=>...]
	*/
	static function extract_datapoints_with_lua($sv_raw,$flavour,$topic_defs) {
		$json_req = self::$CFG['LUA_JSON_MODULE_REQUIRE'];
		$lua_head=<<<ENDLUA
		   if not %ZGVS_VAR% then print('{"status":"err","err":"no_zgvs"}') return end
		   $json_req
		   times={}
		   count=0
		   print('{"status":"ok","datapoints":[')
ENDLUA;

		$lua_extractors = "";
		foreach($topic_defs as $name=>$def) {
			/** @var Topic|array $def */
			// Handle both Topic objects and raw arrays for backward compatibility
			$scraper = is_array($def) ? $def['scraper'] : ($def instanceof Topic ? $def->scraper : $def['scraper']);
			$lua_extractors .= 
				  "\nlocal time1=os.clock()\n"
				. $scraper['extraction_lua'] . "\n"
				. "times['$name']=os.clock()-time1\n";
		}

		$lua_foot = <<<ENDLUA
		    print('],"times":{')
		    local first=true
		    for k,v in pairs(times) do
		        if not first then print(',') end
		        print(string.format('"%s":%.3f',k,v))
		        first=false
		    end
		    print('}}')
ENDLUA;

		$lua = $lua_head . $lua_extractors . $lua_foot;

		$lua = preg_replace_callback("/%([A-Z_]+)%/",function($s) use ($flavour) { return self::$CFG['WOW_FLAVOUR_DATA'][$flavour][$s[1]]; },$lua);
		if (self::$CFG['debug-lua']) {
			file_put_contents("last_lua.lua",$sv_raw);
			file_put_contents("last_lua.lua",$lua,FILE_APPEND);
			self::$CFG->add(['debug-lua'=>false],999,"disable debug-lua after one use");
		}

		if (Telemetry::is_linux()) {
			// cleaner, no temp files
			list ($errcode, $datapoints, $stderr) = self::execute_lua_procopen($sv_raw, $lua);
		} else {
			// windows PHP has a weird bug in proc_open, so just use temp files :(
			list ($errcode, $datapoints, $stderr) = self::execute_lua_exec($sv_raw, $lua);
		}
	
		if ($errcode!=0) return ['status'=>"err",'err'=>"errcode_nonzero",'errcode'=>$errcode,'error'=>$stderr];
		if ($stderr) return ['status'=>"err",'err'=>"stderr_output",'error'=>$stderr,'source_lua'=>$lua,'cwd'=>getcwd(),'luapath'=>self::$CFG['LUA_PATH']];
		$arr = @json_decode($datapoints,true);
		//if (!is_array($arr)) return ['']
		if (!$arr) { return ['status'=>"err",'err'=>"bad_json_output",'json_err'=>json_last_error_msg(),'len'=>strlen($datapoints),'partial'=>substr($datapoints,0,6000)]; }
		return $arr;
   	}

	static function execute_lua_exec($sv_raw, $extraction_code) {
		$tmp_in = tempnam(sys_get_temp_dir(), "lua_in_");
		file_put_contents($tmp_in, $sv_raw . "\n" . $extraction_code);
		$tmp_err = tempnam(sys_get_temp_dir(), "lua_err_");

		$cmd = self::$CFG['LUA_PATH'];
		ob_start();
		$result = passthru($cmd." <$tmp_in 2>$tmp_err", $errcode);
		$stdout = ob_get_clean();
		unlink($tmp_in);
		$stderr = file_get_contents($tmp_err);
		unlink($tmp_err);
		if ($result === false) $errcode=-1;
		return [$errcode, $stdout, $stderr];
	}

	static function execute_lua_procopen($sv_raw, $extraction_code) {
		$descriptorspec = [
			0 => ["pipe", "r"],  // stdin is a pipe that the child will read from
			1 => ["pipe", "w"],  // stdout is a pipe that the child will write to
			2 => ["pipe", "w"] // stderr is a pipe to write to
		];
		$process = proc_open(self::$CFG['LUA_PATH'], $descriptorspec, $pipes, null, []);
		if (!is_resource($process))
			return [-1, "", "Failed to start Lua process"];

		fwrite($pipes[0],$sv_raw); // ZGVSV
		fwrite($pipes[0],$extraction_code);
		fclose($pipes[0]);
		// Lua runs

		//echo "Waiting for Lua process to finish...";

		/*
		$mt = microtime(true);
		for ($i=0;$i<10000;$i++) { // wait for process to finish, but not forever
			$status = proc_get_status($process);
			echo ".".serialize($status);
			if (!$status['running']) break;
			usleep(100000); // 0.1s
		}
		//echo microtime(true)-$mt . "s\n";
		if ($i>=9999) {
			echo "terminating";
			proc_terminate($process);
			return [-1, "", "Lua process timeout"];
		}
		*/
		//echo "\n";

		//echo "Lua process finished, getting output...\n";
		$datapoints = stream_get_contents($pipes[1]);
		//echo "Lua process finished, output length: ".strlen($datapoints).". Reading stderr...\n";
		fclose($pipes[1]);

		//echo "Reading stderr...\n";
		$stderr = stream_get_contents($pipes[2]);
		//echo "Lua process finished, output length: ".strlen($datapoints).", stderr length: ".strlen($stderr)."\n";
		fclose($pipes[2]);

		//echo "Closing process...\n";
		$errcode = proc_close($process);
		//echo "Lua script finished with code $errcode, output length: ".strlen($datapoints).", stderr length: ".strlen($stderr)."\n";

		return [$errcode, $datapoints, $stderr];
	}

	/*
	static function db_get_sv_file_data($flavourfile) {
		return self::db_get_file($flavourfile);
	}

	static function db_update_sv_file_times($sv_file_id,$mtime,$scrape_time,$last_event_time) {
		$q = self::qesc($_q="UPDATE sv_files SET mtime={d}, scrape_time={d}, last_event_time={d} WHERE id={d}", $mtime, $scrape_time, $last_event_time, $sv_file_id);
		$r = Telemetry::$db->query($q);
		if (!$r) throw new ErrorException("DB error, query $_q: ".Telemetry::$db->error);
		return $r;
	}

	static function db_get_svfile_mtimes($flavourfiles) {
		if (!count($flavourfiles)) return [];
		$q = self::qesc($_q="SELECT file,mtime FROM sv_files WHERE file IN ({sa})", $flavourfiles);
		$r = Telemetry::$db->query($q);
		if (!$r) throw new ErrorException("DB error, query $_q: ".Telemetry::$db->error);
		$res = [];
		while ($row = $r->fetch_assoc()) $res[$row['file']] = $row['mtime'];
		return $res;
	}
	*/


	// Tests, DB schemas

	static function self_tests() {
		self::test_paths();
		self::test_datapoints();
		try {
			Logger::vlog("Database: connected and present.");
		} catch (ErrorException $e) {
			die("DB Connection to ".self::$CFG['DB']['host']." FAILED - ".$e->getMessage());
		}
		Logger::vlog("Self-tests: \x1b[48;5;72;30mPASS\x1b[0m");
	}

	static function test_paths() {
		Logger::vlog("Testing paths:");

		if (!is_dir(self::$CFG['SV_STORAGE_ROOT'])) throw new ErrorException("Missing SV storage root: ".self::$CFG['SV_STORAGE_ROOT']."\n");
		Logger::vlog(" - Will read SVs in root of: \x1b[33m".self::$CFG['SV_STORAGE_ROOT']."\x1b[0m");

		if (!is_dir(self::$CFG['SV_STORAGE_DATA_PATH'])) throw new ErrorException("Missing SV storage folder: ".self::$CFG['SV_STORAGE_DATA_PATH']."\n");
		Logger::vlog(" - Specifically SV Sync config says: \x1b[33m".self::$CFG['SV_STORAGE_DATA_PATH']."\x1b[0m");

		foreach (self::$CFG['f'] as $flav) {
			Logger::vlog("   - Flavour: \x1b[38;5;78m$flav\x1b[0m");

			$svpath = Tm::cfgstr('SV_STORAGE_FLAVOUR_PATH',['FLAVOUR'=>$flav]);
			if (!is_dir($svpath)) throw new ErrorException("Missing SV storage flavour folder: ".$svpath."\n");
			Logger::vlog("     - Reading SVs from: \x1b[33m$svpath\x1b[0m");

			/*
			$scrapepath = Tm::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flav,'DAY'=>"YYYYMMDD"]);
			Logger::vlog("     - Temporary scrape folder: \x1b[33m$scrapepath\x1b[0m");

			$telepath = Tm::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flav]);
			if (!is_dir($telepath)) die("Missing Telemetry output flavour folder: ".$telepath."\n");
			Logger::vlog("     - Saving telemetry data into: \x1b[33m$telepath\x1b[0m");
			*/
		}

		$count=0;
		foreach (FileTools::rglob_gen("mock_storage","*.lua*",10) as $i=>$f)
			$count++;
		if ($count<50) throw new ErrorException("rglob_gen self-test failed, found only $count files in mock_storage, expected at least 50.");

		return true;
	}

	static function test_datapoints() {
		$test_dataps = self::extract_datapoints_with_lua("ZygorGuidesViewerSettings={char={bar={guidestephistory={foo={lasttime=12345}}}}}","wow",Telemetry::$TOPICS);
		if (!($test_dataps['status']=="ok" && $test_dataps['datapoints'][0]['type']=="usedguide" && $test_dataps['datapoints'][0]['time']==12345)) die("FAILED testing datapoint defs:\n".print_r($test_dataps,1)."\n");
	}

	/**
	 * @deprecated
	 */
	static function __split_data_by_types_days($datapoints) {
		$today = date("Ymd",NOW);
		$data_by_days = [];
		foreach ((array)$datapoints as $line) {
			$lineday=date("Ymd",$line['time']);
			if ($lineday<"20150101") continue; // ignore that old shit
			if (!self::$CFG['today-too'] && $lineday>=$today) continue; // sadly, ignore this and newer
			$data_by_days[$lineday][]=$line;
		}
		return $data_by_days;
	}

	// test group_ranges
	static function test_group_ranges() {
		$input = [1, 2, 3, 5, 6, 8, 9, 10];
		$expected = ["1-3", "5-6", "8-10"];
		$output = Telemetry::group_date_ranges($input);
		if ($output !== $expected) {
			die("FAILED testing group_ranges:\n".print_r($output,1)."\n");
		}
	}

	/** Convert full file path to flavour/account/filename slug. */
	static function file_path_to_slug($path) {
		if (preg_match("/([^\/]+)\/([^\/]+)\/([^\/]+)--SavedVariables--Zygor.*$/", $path, $matches)) {
			$flavour = $matches[1];
			$user = $matches[2];
			$filename = $matches[3];
			return "$flavour/$user/$filename";
		} else
			throw new ErrorException("Cannot slugify file path: $path");
	}

	/** Convert file slug (flavour/account/filename) to full file path. */
	static function file_slug_to_path($slug) {
		list($flavour, $acctfile) = explode("/", $slug, 2);
		return Tm::cfgstr('SV_STORAGE_FLAVOUR_PATH',['FLAVOUR'=>$flavour])."/".$acctfile."--SavedVariables--ZygorGuidesViewer.lua.gz";
	}

}
