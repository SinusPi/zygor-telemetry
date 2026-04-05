<?php

use Telemetry as Tm;
use TelemetryStatus as TmSt;

/**
 * Set of utilities to extract data from packager logs.
 * 
 * Extracted datapoints are stored into 'events' table, with foreign key to packagerlog_files table.
 */
class TelemetryScrapePackagerLog extends TelemetryScrape {
	static $config_errors = [];

	static function startup() {
		self::config();
	}

	static function config($cfg=[]) {
		self::$CFG = &Telemetry::$CFG; // reference parent's CFG slot (self:: would be a separate null slot)
		$configfile = (array)(@include "config-scrape-packagerlog.inc.php"); // load defaults
		if (!$configfile) throw new ConfigException("Failed to load config-scrape-packagerlog.inc.php");
		
		self::$CFG->add($configfile, 13, "scrape packagerlog config");
		
		if (!self::$CFG['PACKAGERLOG_PATH']) throw new ConfigException("PACKAGERLOG_PATH not defined in config, config-scrape-packagerlog.inc.php not loaded?");
		if (!is_dir(self::$CFG['PACKAGERLOG_PATH'])) throw new ConfigException("PACKAGERLOG_PATH is not a valid directory: ".self::$CFG['PACKAGERLOG_PATH']);
	}

	static function identifySelf() {
		return [
			'key' => 'packagerlog',
			'label' => 'Packager Logs',
			'description' => 'Packager build and deployment logs',
		];
	}

	/**
	 * Get configured paths for this scraper
	 * @return array Array of configured log paths
	 */
	static function getConfiguredPaths() {
		try {
			$paths = [];
			if (isset(self::$CFG['PACKAGERLOG_PATH'])) {
				$paths[] = self::$CFG['PACKAGERLOG_PATH'];
			}
			return $paths;
		} catch (Exception $e) {
			return [];
		}
	}

	/**
	 * Grab data from packager logs
	 * @param string $flavour
	 */
	static function scrape() {
		if (self::$config_errors) {
			Logger::log("Cannot start Packager Log scrape due to configuration errors: ".join("; ", self::$config_errors));
			throw new MinorError("Configuration errors: ".join("; ", self::$config_errors));
		}

		$tag = "SCRAPEPACKLOG";
		$status = TelemetryStatus::get_status($tag, true);
		if ($status['status']=="SCRAPING") throw new MinorError("Another scrape of packager logs is already in progress, aborting.");

		// TODO : go through log-<Y>-<M>-<D> files, bzipped or not, extract flavour update lines, treat them similarly to "ui-VERSION" type events (but store them separately!). Remember which logs were parsed.

		$topics = Telemetry::$TOPICS;
		$topics = array_filter($topics, function($t) { 
			/** @var Topic $t */
			$scraper = $t->getScraper();
			return ($scraper['input']?:"") == "packagerlog"; 
		});
		
		// pick just the topics relevant to packager logs
		//$topics = array_filter($topics, function($t) { return ($t['scraper']['input']?:"") == "packagerlog"; });
		//if (!count($topics)) throw new MinorError("No topics to scrape for packager logs, aborting.");
		
		Logger::log("Starting scrape of topics: \x1b[38;5;78m".implode(", ",array_keys($topics))."\x1b[0m in packager logs.");

		TmSt::stat(['status'=>"ENUMERATING",'stage'=>1,'stageof'=>2,'progress'=>[],'time_started'=>time(),'time_started_hr'=>date("Y-m-d H:i:s")]);		
		Logger::log("Enumerating files matching ".self::$CFG['PACKAGERLOG_MASK']." in ".self::$CFG['PACKAGERLOG_PATH']);
		
		$files = self::find_logs();
		$total_filecount = count($files);
		Logger::vlog("Found $total_filecount files.");

		$files = self::filter_by_dates($files);

		TmSt::stat(['files_total'=>count($files)],true);
		Logger::log(count($files)." files to process.");
		Logger::log(join(",",$files));

		$freshfiles = self::db_get_unprocessed_logfiles($files,array_keys($topics));

		if (!count($freshfiles)) {
			Logger::log("Nothing to do here.");
			TmSt::stat(['status'=>"IDLE"]);
			return;
		}

		TmSt::stat(['status'=>"EXTRACTING",'files_fresh'=>count($freshfiles),'files_skipped'=>0,'tmfiles_skipped'=>0,'tmfiles_written'=>0,'tmfiles_last'=>"",'file_last'=>"",'not_files'=>0,'broken_lua'=>0]);

		$totals=[];

		return;
		
		/*
		$first_day_relevant = date("Ymd",time()-self::$CFG['TELEMETRY_DATA_AGE']);
		Logger::log("We check for data max \x1b[1m".round(self::$CFG['TELEMETRY_DATA_AGE']/86400)."\x1b[0m days old (>= \x1b[38;5;118m$first_day_relevant\x1b[0m)");
		*/

		// prepare list of files to actually process

		/*
		$freshfiles_to_process = [];

		foreach ($freshfiles as $n=>$filename_full) {
			if (!$filename_full) continue;
			if (!is_file($filename_full)) {
				$totals['not_file']=$filename_full;
				$totals['not_files']++;
				continue;
			}

			// $filename_full is a full path, chop it
			list ($filename_userfile, $filename_slug) = self::split_filename($filename_full);
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
		*/
		$freshfiles_to_process = $freshfiles; // for now, process all files
	
		Logger::log(sprintf("Processing %d logs.",count($freshfiles_to_process)));
		
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

		foreach ($freshfiles_to_process as $n=>$filename) {
			TmSt::stat(['file_last'=>$filename],true);

			Logger::vlog("Scraping Packager log: \x1b[38;5;110m$filename\x1b[0m");

			$lock_code = $filename;
			$got_db_lock = false;

			try { // flock block
				$fl = fopen(self::$CFG['PACKAGERLOG_PATH']."/".$filename, 'r');
				if (!$fl) throw new FileLockedException("Cannot open logfile for locking: $filename");
				if (!flock($fl, LOCK_EX | LOCK_NB)) {
					fclose($fl);
					throw new FileLockedException("Logfile locked: $filename");
				}

				Telemetry::$db->begin_transaction();

				Logger::vlog(" - :. reading logfile...");



				$sv_file_data = self::db_get_sv_file_data($flavourfile);
				if (!$sv_file_data) {
					Logger::log("Cannot get sv_file_data for $filename_full. Locked?");
					throw new FileLockedException("DB locked for $flavourfile");
				}

				$last_event_stored = $sv_file_data['last_event_time'] ?: 0;

				// ===============================================================

				$sv_raw = self::read_raw_sv($filename_full);

				Logger::vlog(" - .: extracting datapoints...");

				$extracted = self::extract_datapoints_with_lua($sv_raw,$flavour,$topics);

				// ===============================================================

				// handle errors
				if ($extracted['status'] != "ok") {
					if ($extracted['err'] == "stderr_output") {
						$totals['broken_lua']++;
						echo $filename_full . ": ERROR: " . $extracted['error'];
						continue; // SV+Lua broken, it could mean our extraction failed, or the file was broken in the first place.
					} elseif ($extracted['err'] == "no_zgvs") {
						$size = filesize($filename_full);
						if ($size > 500) $totals['files_without_zgvs'][] = $filename_userfile; // just log it
						$totals['broken_lua']++;
						continue;
					} elseif (!isset($extracted['datapoints'])) {
						trigger_error("ERROR: no datapoints at all (did Lua even run?), reading $filename_full " . print_r($extracted, 1));
					}
				}

				$counts = array_count_values(array_column($extracted['datapoints'], 'type'));
				$times = $extracted['times'];
				Logger::vlog("Datapoints extracted by type: " . join(", ", array_map(function ($item, $key) use ($times) { return "\x1b[38;5;72m$key\x1b[0m:{$item} ({$times[$key]}s)"; }, array_values($counts), array_keys($counts))));

				/*
					// locale
					$lang_match = preg_match("#translation\"\]=\{\[\"(....)\"#",$file,$lang_m);
						if ($lang_match) {
							$metrics['languages'][$lang_m[1]]++;
							$metrics['files_withlang']++;
						}
				*/

				$extracted['datapoints'] = array_values(array_filter($extracted['datapoints'], function ($dp) use ($last_event_stored) {  return $dp['time'] > $last_event_stored;  })); // only new events
				Logger::vlog("Datapoints after filtering out old (<= ".($last_event_stored ? date("Y-m-d H:i:s",$last_event_stored) : "never")."): ".count($extracted['datapoints']));

				$inserted = Telemetry::$db->store_datapoints($flavour,$sv_file_data['id'],$extracted['datapoints']);
				Logger::vlog("Datapoints inserted into DB: $inserted");

				$totals['inserted_datapoints'] += $inserted;

				/*
				$datapoints_by_days = self::split_data_by_types_days($extracted['datapoints']);
				ksort($datapoints_by_days);

				//Logger::vlog("Days present: ".implode(",",self::group_ranges(array_keys($datapoints_by_days))));
				$all_days = array_keys($datapoints_by_days);
				foreach ($datapoints_by_days as $day=>&$data) 
					if ($day<$first_day_relevant) unset($datapoints_by_days[$day]);

				Logger::vlog(sprintf("Days present: \x1b[38;5;118m%s\x1b[0m-\x1b[38;5;118m%s\x1b[0m", current($all_days), end($all_days)));
				Logger::vlog("Days relevant: ".implode(",",array_map(function($s) { return "\x1b[38;5;118m$s\x1b[0m"; }, self::group_ranges(array_keys($datapoints_by_days)))));
				
				foreach ($datapoints_by_days as $day=>&$daydata) {
					Logger::vlog("Day $day: ".count($daydata)." events (".join(", ", array_unique(array_map(function ($item) { return $item['type']; }, $daydata))).")");
					self::store_day_scrape($flavour,$day,$user,$bnet, $daydata, filemtime($filename_full), $totals);
				} unset ($daydata);
				//self::write_intermediate_mtimes($flavour,$last_scrape_dates);
				*/



				$last_event_time = max(array_column($extracted['datapoints'],'time')) ?: 0;
				// $last_event_data = array_filter($extracted['datapoints'], function($dp) use ($last_event_time) { return $dp['time'] == $last_event_time; });
				// print_r($last_event_data);
				// die("LAST EVENT TIME: $last_event_time");

				self::db_update_sv_file_times($sv_file_data['id'], filemtime($filename_full), NOW, $last_event_time);

				// update progress
				TmSt::update_progress($tag,$n,count($freshfiles_to_process),['totals'=>$totals],self::$CFG['verbose']);

				// obey limit
				if (isset(self::$CFG['limit']) && $n>=self::$CFG['limit']) {
					echo "Limit ".self::$CFG['limit']." hit, aborting.\n";
					break;
				}
				Telemetry::$db->commit();

			} catch (FileLockedException $e) {
				Logger::vlog($e->getMessage()." - $filename_full");
				Telemetry::$db->rollback();
				continue;
			} catch (Exception $e) {
				Logger::log(microtime(true)." ERROR processing $filename_full: " . $e->getMessage());
				throw $e;
			} finally {
				// unlock
				if (isset($fl)) { flock($fl, LOCK_UN); fclose($fl); }
				if ($got_db_lock) {
					$unl = Telemetry::$db->unlock($lock_code);
					//Logger::vlog(microtime(true)." DB lock released for $lock_code: ".($unl ? "ok" : "failed"));
				}
			}

			$processed_files = $n + 1;
			if (isset(self::$CFG['limit']) && $processed_files >= self::$CFG['limit']) {
				echo "Limit " . self::$CFG['limit'] . " hit, aborting.\n";
				break;
			}
			
		}
		//self::write_intermediate_mtimes($flavour,$last_scrape_dates,true);
		$tot1 = array_filter($totals,function($v) { return is_numeric($v); });
		$tots = array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));
		Logger::log("Scrape of $flavour complete; ".implode(", ",$tots));

		if (count($totals['files_without_zgvs'])/(count($freshfiles_to_process)-$totals['files_skipped'])>0.5)
			Logger::log("Weird. Out of ".(count($freshfiles_to_process)-$totals['files_skipped'])." files read, ".count($totals['files_without_zgvs'])." had no ZGVs.");

		TmSt::stat(['status'=>"IDLE"]);
	}

	static function find_logs() {
		$mask = self::$CFG['PACKAGERLOG_PATH']."/".str_replace(['<Y>','<M>','<D>'],"*",self::$CFG['PACKAGERLOG_MASK']); // log-*-*-*.txt*
		$files = glob($mask);
		$files = array_map('basename', $files);
		return $files;
	}

	/**
	 * Save scraped data for a specific day and user/account.
	 * @deprecated
	 */
	static function __save_day_scrape($flavour,$day,$user,$acct, $daydata, &$totals) {
		if (!$daydata) 
			return ++$totals['tmfiles_empty'];
		$scrape_folder = Telemetry::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
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
			$mtimes_cache_filename = Telemetry::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour])."/".self::$CFG['MTIMES_CACHE_FILENAME'];
			$f=file_put_contents($mtimes_cache_filename,json_encode($last_scrape_dates),LOCK_EX);
			if (!$f) throw new ErrorException("Cannot write mtimes cache");
			$time_last_mtimes = time();
		}
	}
}
