<?php

/**
 * Set of utilities to extract data from packager logs.
 * 
 * Extracted datapoints are stored into 'events' table, with foreign key to packagerlog_files table.
 */
class TelemetryScrapePackagerLog extends TelemetryScrape {
	static function init() {
		parent::init();
	}

	static function config($cfg=[]) {
		parent::config($cfg);

		$configfile = (array)(@include "config-scrape-packagerlog.inc.php"); // load defaults
		self::$CFG = self::merge_configs(self::$CFG, $configfile);
		if (!self::$CFG['PACKAGERLOG_PATH']) throw new ErrorException("PACKAGERLOG_PATH not defined in config, config-scrape-packagerlog.inc.php not loaded?");
	}

	static function registerSelf() {
		parent::registerSource('packagerlog', [
			'class' => self::class,
			'label' => 'Packager Logs',
			'description' => 'Packager build and deployment logs'
		]);
	}

	/**
	 * Get configured paths for this scraper
	 * @return array Array of configured log paths
	 */
	static function getConfiguredPaths() {
		try {
			self::config();
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
	 * Verify that the packager log path is configured and accessible
	 * @return bool True if path exists and is a directory, false otherwise
	 */
	static function verifyConfiguredPaths() {
		try {
			self::config();
			if (!isset(self::$CFG['PACKAGERLOG_PATH'])) {
				return false;
			}
			return is_dir(self::$CFG['PACKAGERLOG_PATH']);
		} catch (Exception $e) {
			return false;
		}
	}

	/**
	 * Grab data from SVs, store into db
	 * @param string $flavour
	 */
	static function scrape() {
		self::$tag = "SCRAPEPACKLOG";
		$status = self::get_status(self::$tag, true);
		if ($status['status']=="SCRAPING") throw new MinorError("Another scrape of packager logs is already in progress, aborting.");

		// TODO : go through log-<Y>-<M>-<D> files, bzipped or not, extract flavour update lines, treat them similarly to "ui-VERSION" type events (but store them separately!). Remember which logs were parsed.

		$topics = self::$CFG['TOPICS'];
		
		// pick just the topics relevant to packager logs
		//$topics = array_filter($topics, function($t) { return ($t['scraper']['input']?:"") == "packagerlog"; });
		//if (!count($topics)) throw new MinorError("No topics to scrape for packager logs, aborting.");
		
		self::log("Starting scrape of topics: \x1b[38;5;78m".implode(", ",array_keys($topics))."\x1b[0m in packager logs.");

		self::stat(['status'=>"ENUMERATING",'stage'=>1,'stageof'=>2,'progress'=>[],'time_started'=>time(),'time_started_hr'=>date("Y-m-d H:i:s")]);		
		self::log("Enumerating files matching ".self::$CFG['PACKAGERLOG_MASK']." in ".self::$CFG['PACKAGERLOG_PATH']);
		
		$files = self::find_logs();
		$total_filecount = count($files);
		self::vlog("Found $total_filecount files.");

		$files = self::filter_by_dates($files);

		self::stat(['files_total'=>count($files)],true);
		self::log(count($files)." files to process.");
		self::log(join(",",$files));

		$freshfiles = self::db_get_unprocessed_logfiles($files,array_keys($topics));

		if (!count($freshfiles)) {
			self::log("Nothing to do here.");
			self::stat(['status'=>"IDLE"]);
			return;
		}

		self::stat(['status'=>"EXTRACTING",'files_fresh'=>count($freshfiles),'files_skipped'=>0,'tmfiles_skipped'=>0,'tmfiles_written'=>0,'tmfiles_last'=>"",'file_last'=>"",'not_files'=>0,'broken_lua'=>0]);

		$totals=[];

		return;
		
		/*
		$first_day_relevant = date("Ymd",time()-self::$CFG['TELEMETRY_DATA_AGE']);
		self::log("We check for data max \x1b[1m".round(self::$CFG['TELEMETRY_DATA_AGE']/86400)."\x1b[0m days old (>= \x1b[38;5;118m$first_day_relevant\x1b[0m)");
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

			self::stat(['file_last'=>$filename_userfile],true);

			if (!self::$CFG['ignore-mtimes'] && isset($last_mtimes[$flavourslug]) && filemtime($filename_full) <= $last_mtimes[$flavourslug]) {  // do not re-scrape if the file hasn't been updated
				$totals['files_skipped']++;
				//$totals['files_skipped_last_why'] = date("Ymd",filemtime($full_filename))."<=".date("Ymd",$last_mtimes[$filename_full]);
				continue; //skip
			}

			$freshfiles_to_process[]=$filename_full;
		}
		*/
		$freshfiles_to_process = $freshfiles; // for now, process all files
	
		self::log(sprintf("Processing %d logs.",count($freshfiles_to_process)));
		
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
			self::stat(['file_last'=>$filename],true);

			self::vlog("Scraping Packager log: \x1b[38;5;110m$filename\x1b[0m");

			$lock_code = $filename;
			$got_db_lock = false;

			try { // flock block
				$fl = fopen(self::$CFG['PACKAGERLOG_PATH']."/".$filename, 'r');
				if (!$fl) throw new FileLockedException("Cannot open logfile for locking: $filename");
				if (!flock($fl, LOCK_EX | LOCK_NB)) {
					fclose($fl);
					throw new FileLockedException("Logfile locked: $filename");
				}

				self::$db->begin_transaction();

				self::vlog(" - :. reading logfile...");



				$sv_file_data = self::db_get_sv_file_data($flavourfile);
				if (!$sv_file_data) {
					self::log("Cannot get sv_file_data for $filename_full. Locked?");
					throw new FileLockedException("DB locked for $flavourfile");
				}

				$last_event_stored = $sv_file_data['last_event_time'] ?: 0;

				// ===============================================================

				$sv_raw = self::read_raw_sv($filename_full);

				self::vlog(" - .: extracting datapoints...");

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
				self::vlog("Datapoints extracted by type: " . join(", ", array_map(function ($item, $key) use ($times) { return "\x1b[38;5;72m$key\x1b[0m:{$item} ({$times[$key]}s)"; }, array_values($counts), array_keys($counts))));

				/*
					// locale
					$lang_match = preg_match("#translation\"\]=\{\[\"(....)\"#",$file,$lang_m);
						if ($lang_match) {
							$metrics['languages'][$lang_m[1]]++;
							$metrics['files_withlang']++;
						}
				*/

				$extracted['datapoints'] = array_values(array_filter($extracted['datapoints'], function ($dp) use ($last_event_stored) {  return $dp['time'] > $last_event_stored;  })); // only new events
				self::vlog("Datapoints after filtering out old (<= ".($last_event_stored ? date("Y-m-d H:i:s",$last_event_stored) : "never")."): ".count($extracted['datapoints']));

				$inserted = self::$db->store_datapoints($flavour,$sv_file_data['id'],$extracted['datapoints']);
				self::vlog("Datapoints inserted into DB: $inserted");

				$totals['inserted_datapoints'] += $inserted;

				/*
				$datapoints_by_days = self::split_data_by_types_days($extracted['datapoints']);
				ksort($datapoints_by_days);

				//self::vlog("Days present: ".implode(",",self::group_ranges(array_keys($datapoints_by_days))));
				$all_days = array_keys($datapoints_by_days);
				foreach ($datapoints_by_days as $day=>&$data) 
					if ($day<$first_day_relevant) unset($datapoints_by_days[$day]);

				self::vlog(sprintf("Days present: \x1b[38;5;118m%s\x1b[0m-\x1b[38;5;118m%s\x1b[0m", current($all_days), end($all_days)));
				self::vlog("Days relevant: ".implode(",",array_map(function($s) { return "\x1b[38;5;118m$s\x1b[0m"; }, self::group_ranges(array_keys($datapoints_by_days)))));
				
				foreach ($datapoints_by_days as $day=>&$daydata) {
					self::vlog("Day $day: ".count($daydata)." events (".join(", ", array_unique(array_map(function ($item) { return $item['type']; }, $daydata))).")");
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
				self::update_progress(self::$tag,$n,count($freshfiles_to_process),['totals'=>$totals],self::$CFG['verbose']);

				// obey limit
				if (isset(self::$CFG['limit']) && $n>=self::$CFG['limit']) {
					echo "Limit ".self::$CFG['limit']." hit, aborting.\n";
					break;
				}
				self::$db->commit();

			} catch (FileLockedException $e) {
				self::vlog($e->getMessage()." - $filename_full");
				self::$db->rollback();
				continue;
			} catch (Exception $e) {
				self::log(microtime(true)." ERROR processing $filename_full: " . $e->getMessage());
				throw $e;
			} finally {
				// unlock
				if (isset($fl)) { flock($fl, LOCK_UN); fclose($fl); }
				if ($got_db_lock) {
					$unl = self::$db->unlock($lock_code);
					//self::vlog(microtime(true)." DB lock released for $lock_code: ".($unl ? "ok" : "failed"));
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
		self::log("Scrape of $flavour complete; ".implode(", ",$tots));

		if (count($totals['files_without_zgvs'])/(count($freshfiles_to_process)-$totals['files_skipped'])>0.5)
			self::log("Weird. Out of ".(count($freshfiles_to_process)-$totals['files_skipped'])." files read, ".count($totals['files_without_zgvs'])." had no ZGVs.");

		self::stat(['status'=>"IDLE"]);
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
		$scrape_folder = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
		mkdir($scrape_folder,0777,true);
		if (!is_dir($scrape_folder)) throw new ErrorException("Failed to create/access scrape folder at $scrape_folder");
		$scrape_file = "{$user}@{$acct}.json";
		$scrape_filepath = $scrape_folder."/".$scrape_file;
		if (file_exists($scrape_filepath))
			return $totals['tmfiles_skipped']++;

		//self::vlog("Saving ".count($daydata)." scrapes into \x1b[33;1m$scrape_filepath\x1b[0m");

		file_put_contents($scrape_filepath,json_encode($daydata),LOCK_EX);

		foreach ($daydata as $line) $totals['types'][$line['type']]++;
		$totals['tmfiles_written']++;
		$totals['tmfiles_last']=$scrape_filepath;
	}


	/** @deprecated */
	static function __write_intermediate_mtimes($flavour,$last_scrape_dates,$force=false) {
		static $time_last_mtimes=0;
		if ($force || time()-$time_last_mtimes >= self::$CFG['MTIMES_WRITE_INTERVAL']) {
			$mtimes_cache_filename = self::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour])."/".self::$CFG['MTIMES_CACHE_FILENAME'];
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
		foreach($topic_defs as $name=>$def)
			$lua_extractors .= 
				  "\nlocal time1=os.clock()\n"
				. $def['extraction_lua'] . "\n"
				. "times['$name']=os.clock()-time1\n";

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
		if (self::$CFG['debug_lua']) echo $lua;
		unset(self::$CFG['debug_lua']);

		$descriptorspec = [
			0 => ["pipe", "r"],  // stdin is a pipe that the child will read from
			1 => ["pipe", "w"],  // stdout is a pipe that the child will write to
			2 => ["pipe", "w"]  // NOPE:pipe // stderr is a file to write to
		];
		$process = proc_open(self::$CFG['LUA_PATH'], $descriptorspec, $pipes, null, []);
		
		fwrite($pipes[0],$sv_raw); // ZGVSV
		fwrite($pipes[0],$lua);
	
		fclose($pipes[0]); // Lua runs

		$datapoints = stream_get_contents($pipes[1])."\n";
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$errcode = proc_close($process);
   
		if ($errcode!=0) return ['status'=>"err",'err'=>"errcode_nonzero",'errcode'=>$errcode,'error'=>$stderr];
		if ($stderr) return ['status'=>"err",'err'=>"stderr_output",'error'=>$stderr,'source_lua'=>$lua,'cwd'=>getcwd(),'luapath'=>self::$CFG['LUA_PATH']];
		$arr = @json_decode($datapoints,true);
		//if (!is_array($arr)) return ['']
		if (!$arr) { return ['status'=>"err",'err'=>"bad_json_output",'json_err'=>json_last_error_msg(),'len'=>strlen($datapoints),'partial'=>substr($datapoints,0,6000)]; }
		return $arr;
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

	// Tests, DB schemas

	static function self_tests() {
		self::test_paths();
		//self::test_datapoints();
		try {
			self::$db->create_tables();
			self::test_status();
			self::vlog("Database: connected and present.");
		} catch (ErrorException $e) {
			die("DB Connection to ".self::$CFG['DB']['host']." FAILED - ".$e->getMessage());
		}
		self::vlog("Self-tests: \x1b[48;5;70;30mPASS\x1b[0m");
	}

	static function test_paths() {
		self::vlog("Testing paths:");

		if (!is_dir(self::$CFG['PACKAGERLOG_PATH'])) die("Missing Packager Log path: ".self::$CFG['PACKAGERLOG_PATH']."\n");
		self::vlog(" - Will read Packager Logs from: \x1b[33m".self::$CFG['PACKAGERLOG_PATH']."\x1b[0m");

		$mask = str_replace(["<Y>","<M>","<D>"],["*","*","*"],self::$CFG['PACKAGERLOG_MASK']);
		$g = glob(self::$CFG['PACKAGERLOG_PATH']."/".$mask,GLOB_NOSORT);
		if (!$g || !count($g)) die("No Packager Log files found matching mask: ".$mask."\n");
		self::vlog(" - Found ".count($g)." Packager Log files matching mask '".$mask."', we're good.");

		return true;
	}

	static function test_datapoints() {
		$test_dataps = self::extract_datapoints_with_lua("ZygorGuidesViewerSettings={char={bar={guidestephistory={foo={lasttime=12345}}}}}","wow",self::$CFG['TOPICS']);
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

	static function filter_by_dates($files) {
		return array_filter($files, function($f) {
				$datepart = preg_replace("/.*-(\d{2})-(\d{2})-(\d{2}).*/","20$1$2$3",$f);
				if (!$datepart) return false;
				return ($datepart >= date("Ymd", time() - self::$CFG['maxdays'] * 86400))
				    && ($datepart >= self::$CFG['start-day'])
					&& ($datepart <= date("Ymd", time() - (self::$CFG['today-too'] ? 0 : 86400)));
		});
	}

	// test group_ranges
	static function test_group_ranges() {
		$input = [1, 2, 3, 5, 6, 8, 9, 10];
		$expected = ["1-3", "5-6", "8-10"];
		$output = self::group_ranges($input);
		if ($output !== $expected) {
			die("FAILED testing group_ranges:\n".print_r($output,1)."\n");
		}
	}

	/** Convert full file path to flavour/account/filename slug. */
	static function file_path_to_slug($path) {
		$svstorage_path = self::$CFG['PACKAGERLOG_PATH'];
		$relative_path = str_replace($svstorage_path."/", "", $path); // remove base path; should leave "flavour/user/filename"
		$relative_path = str_replace("\\", "/", $relative_path); // normalize slashes
		return $relative_path; // "flavour/user/filename"
	}

	/** Convert file slug (flavour/account/filename) to full file path. */
	static function file_slug_to_path($slug) {
		return self::$CFG['PACKAGERLOG_PATH']."/".$slug;
	}
}

TelemetryScrapePackagerLog::registerSelf();