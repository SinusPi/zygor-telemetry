<?php

/**
 * Set of utilities to comb through user-supplied SV files for telemetry data.
 * Each datapoint is extracted by a Lua script, has a "type" and "time" field.
 * 
 * Input files are logged into 'sv_files' table, with last modified time and last scraped time to only rescrape changed files.
 * Extracted datapoints are stored into 'events' table, with foreign key to sv_files table.
 */
class TelemetryScrapeSVs extends Telemetry {
	static function init() {
		parent::init();
		// any local inits?
	}

	static function config($cfg=[]) {
		parent::config($cfg);

		$configfile = (array)(@include "config-scrape-sv.inc.php"); // load defaults
		self::$CFG = self::merge_configs(self::$CFG, $configfile);
		if (!self::$CFG['SV_STORAGE_ROOT']) throw new ErrorException("SV_STORAGE_ROOT not defined in config, config-scrape-sv.inc.php not loaded?");

		// load sync's config
		@include self::$CFG['SV_STORAGE_ROOT']."/config.inc.php"; // defines SYNC_CFG
		if (!$SYNC_CFG) throw new ErrorException("Failed to load sync config from ".self::$CFG['SV_STORAGE_ROOT']."/config.inc.php");
		self::$CFG['SV_STORAGE_DATA_PATH'] = self::cfgstr('SV_STORAGE_DATA_PATH',['SYNC_FOLDER'=>$SYNC_CFG['folder']]);
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

	/**
	 * Grab data from SVs, store into db
	 * @param string $flavour
	 */
	static function scrape($flavour) {
		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) throw new ErrorException("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

		self::$tag = "SCRAPE-".strtoupper(str_replace("-","_", $flavour));
		$status = self::get_status(self::$tag, true);
		if ($status['status']=="SCRAPING") {
			self::log("Another scrape for flavour '\x1b[38;5;78m{$flavour}\x1b[0m' is already in progress, aborting.");
			return;
		}

		$topics = self::$CFG['TOPICS'];
		$topics = array_filter($topics, function($t) { return ($t['scraper']['input']?:"") == "sv"; });
		$sync_path = self::cfgstr('SV_STORAGE_FLAVOUR_PATH',["FLAVOUR"=>$flavour]);

		self::log("Starting scrape of flavour '\x1b[38;5;78m{$flavour}\x1b[0m' in \x1b[33;1m{$sync_path}\x1b[0m.");

		self::stat(['status'=>"ENUMERATING",'stage'=>1,'stageof'=>2,'flavour'=>$flavour,'progress'=>[],'time_started'=>time(),'time_started_hr'=>date("Y-m-d H:i:s")]);		

		self::log("Enumerating files matching ".self::$CFG['filemask']);
		
		$t1 = microtime(true);
		$files = self::find_files($sync_path, self::$CFG['filemask'], true); // FINDING FILES. TAKES LONG.
		$total_filecount = count($files);
		self::vlog("Found $total_filecount files [".round(microtime(true)-$t1,2)."s]");

		if (isset(self::$CFG['TELEMETRY_FILE_AGE'])) {
			$days_old = intval(self::$CFG['TELEMETRY_FILE_AGE']/DAY)+1; // old way of detecting file age
			$files = self::filter_younger_files($files, $days_old);
			$t1 = microtime(true);
			self::vlog("Filtered out files older than $days_old days: {$total_filecount} -> ".count($files)." files [".round(microtime(true)-$t1,2)."s]");
		}

		while ($files[0]==="") array_shift($files);
		//$files = str_replace($sync_path."/","",$files);

		self::stat(['files_total'=>count($files)],true);
		self::log(count($files)." files to process.");

		if (!count($files)) {
			self::log("Nothing to do here.");
			self::stat(['status'=>"IDLE"]);
			return;
		}

		$freshfiles = $files; //array_values(array_filter($files,function($f) { return NOW-filemtime($f)<=TELEMETRY_INTERVAL; }));

		$freshhashes = array_map(function($f) use ($flavour) { return substr(md5($flavour."/".$f),0,8); },$freshfiles);
		$counts = array_count_values($freshhashes);
		$dupes = array_filter($counts,function($c) { return $c>1; });
		if (count($dupes)) {
			self::stat(['status'=>"ERROR",'error'=>"Duplicate files found, see log."]);
			self::log("Warning: ".count($dupes)." duplicate files found (same name in different folders): ".join(", ",array_keys($dupes)).". They will be processed only once.");
			die();
		}

		self::stat(['status'=>"EXTRACTING",'files_fresh'=>count($freshfiles),'files_skipped'=>0,'tmfiles_skipped'=>0,'tmfiles_written'=>0,'tmfiles_last'=>"",'file_last'=>"",'not_files'=>0,'broken_lua'=>0]);

		//self::log(count($freshfiles)." of them are fresh enough (".intval(TELEMETRY_INTERVAL/DAY)." days).");

		//$telefolder = self::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour]);
		//$last_scrape_dates = (array)@json_decode(@file_get_contents($telefolder."/".self::$CFG['MTIMES_CACHE_FILENAME'])); // names relative to sync folder
		$t1 = microtime(true);
		$last_mtimes = self::fetch_last_mtimes($flavour, $freshfiles);
		$t2 = microtime(true);
		self::vlog("Loaded ".count($last_mtimes)." last scrape dates from DB in ".self::$DBG['mtime_queries']." queries [".round($t2-$t1,2)."s]");
		
		$totals=[];
		$first_day_relevant = date("Ymd",time()-self::$CFG['TELEMETRY_DATA_AGE']);
		self::log("We check for data max \x1b[1m".round(self::$CFG['TELEMETRY_DATA_AGE']/86400)."\x1b[0m days old (>= \x1b[38;5;118m$first_day_relevant\x1b[0m)");

		
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
	
		self::log(sprintf(
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
			self::process_single_sv_file($flavour, $filename_full, $topics, $totals);

			// obey limit
			if (isset(self::$CFG['limit']) && $n>=self::$CFG['limit']-1) {
				self::$db->commit();
				throw new ErrorException("Limit ".self::$CFG['limit']." hit, aborting.\n");
			}

			// update progress
			self::update_progress(self::$tag,$n,count($freshfiles_to_process),['totals'=>$totals],self::$CFG['verbose']);

		}

		//self::write_intermediate_mtimes($flavour,$last_scrape_dates,true);
		$tot1 = array_filter($totals,function($v) { return is_numeric($v); });
		$tots = array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));
		self::log("Scrape of $flavour complete; ".implode(", ",$tots));

		if (count($totals['files_without_zgvs'])/(count($freshfiles_to_process)-$totals['files_skipped'])>0.5)
			self::log("Weird. Out of ".(count($freshfiles_to_process)-$totals['files_skipped'])." files read, ".count($totals['files_without_zgvs'])." had no ZGVs.");

		self::stat(['status'=>"IDLE"]);
	}

	/** Run all SV-sourced topic scrapers on a single file
	 * @return void
	 */
	static function process_single_sv_file($flavour, $filename_full, $topics, &$totals) {
		self::stat(['file_last'=>$filename_full],true);
		list ($filename_userfile, $filename_slug) = self::split_filename($filename_full);
		$bnet = basename($filename_slug);
		$user = basename(dirname($filename_slug));
		$userfolder = dirname($filename_full);

		self::vlog("Scraping SV: \x1b[38;5;110m$user\x1b[0m/\x1b[38;5;116m$bnet\x1b[0m\x1b[30;1m--SavedVariables...\x1b[0m");

		$is_windows = (strpos(PHP_OS, 'WIN') === 0); $is_linux = !$is_windows;
		$lock_code = $flavour."/".$filename_slug;
		$got_db_lock = false;

		try { // flock block
			if ($is_linux) {
				$fl = fopen($userfolder, 'rb');
				if (!$fl) throw new FileLockedException("Cannot open input folder for locking: $userfolder");
				if (!flock($fl, LOCK_EX | LOCK_NB)) {
					fclose($fl);
					throw new FileLockedException("Input folder locked: $userfolder");
				}
			} else {
				// Windows: use DB locks
				$got_db_lock = self::db_lock($lock_code);
				if (!$got_db_lock) {
					throw new FileLockedException("Input folder locked (DB): $lock_code");
				} else {
					//self::vlog(microtime(true)." DB lock acquired for $lock_code");
				}
			}

			self::vlog(" - :. reading SV file...");

			self::$db->begin_transaction(); // need to start here to lock the DB record for this file

			$flavourfile = $flavour."/".$filename_slug; // flavour/user/bnet
			$sv_file_data = self::db_get_sv_file_data($flavourfile);
			if (!$sv_file_data) {
				self::log("Cannot get/set sv_file_data for $filename_full. Locked?");
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
			self::vlog("Datapoints extracted by type: " . join(", ", array_map(function ($item, $key) use ($times) { return "\x1b[38;5;72m$key\x1b[0m:{$item} ({$times[$key]}s)"; }, array_values($counts), array_keys($counts))));

			/*
				// locale
				$lang_match = preg_match("#translation\"\]=\{\[\"(....)\"#",$file,$lang_m);
					if ($lang_match) {
						$metrics['languages'][$lang_m[1]]++;
						$metrics['files_withlang']++;
					}
				// wtf is this even?
			*/

			$extracted['datapoints'] = array_values(array_filter($extracted['datapoints'], function ($dp) use ($last_event_stored) {  return $dp['time'] > $last_event_stored;  })); // only new events
			self::vlog("Datapoints after filtering out old (<= ".($last_event_stored ? date("Y-m-d H:i:s",$last_event_stored) : "never")."): ".count($extracted['datapoints']));

			// DB STORE TIME!

			$inserted = self::db_store_datapoints($flavour,$sv_file_data['id'],$extracted['datapoints']);
			self::vlog("Datapoints inserted into DB: $inserted");

			$totals['inserted_datapoints'] += $inserted;

			$last_event_time = max(array_column($extracted['datapoints'],'time')) ?: 0;
			// $last_event_data = array_filter($extracted['datapoints'], function($dp) use ($last_event_time) { return $dp['time'] == $last_event_time; });
			// print_r($last_event_data);
			// die("LAST EVENT TIME: $last_event_time");

			self::db_update_sv_file_times($sv_file_data['id'], filemtime($filename_full), NOW, $last_event_time);

			self::$db->commit();

		} catch (FileLockedException $e) {
			self::vlog($e->getMessage()." - $filename_full");
			self::$db->rollback();
			return;
		} catch (Exception $e) {
			self::log(microtime(true)." ERROR processing $filename_full: " . $e->getMessage());
			throw $e;
		} finally {
			// unlock
			if (isset($fl)) { flock($fl, LOCK_UN); fclose($fl); }
			if ($got_db_lock) {
				$unl = self::db_unlock($lock_code);
				//self::vlog(microtime(true)." DB lock released for $lock_code: ".($unl ? "ok" : "failed"));
			}
		}
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
		foreach($topic_defs as $name=>$def) {
			$scraper = $def['scraper'];
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

	static function db_get_sv_file_data($flavourfile) {
		$q = self::qesc("SELECT * FROM sv_files WHERE file={s}  LIMIT 1  FOR UPDATE  NOWAIT", $flavourfile);
		$r = self::$db->query($q);
		if (!$r && self::$db->errno==3572) { // lock wait timeout
			return null;
		}
		if (!$r) throw new ErrorException("DB error: ".self::$db->error);
		if ($r->num_rows) {
			$row = $r->fetch_assoc();
			return $row;
		} else {
			$q = self::qesc($_q="INSERT INTO sv_files (file) VALUES ({s})", $flavourfile);
			$r = self::$db->query($q);
			if (!$r) throw new ErrorException("DB error, query $_q: ".self::$db->error);
			$id = self::$db->insert_id;
			$q2 = self::qesc($_q="SELECT * FROM sv_files WHERE id={d} LIMIT 1 FOR UPDATE", $id);
			$r2 = self::$db->query($q2);
			if (!$r2) throw new ErrorException("DB error, query $_q: ".self::$db->error);
			if ($r2->num_rows) {
				$row2 = $r2->fetch_assoc();
				return $row2;
			}
		}
	}

	static function db_update_sv_file_times($sv_file_id,$mtime,$scrape_time,$last_event_time) {
		$q = self::qesc($_q="UPDATE sv_files SET mtime={d}, scrape_time={d}, last_event_time={d} WHERE id={d}", $mtime, $scrape_time, $last_event_time, $sv_file_id);
		$r = self::$db->query($q);
		if (!$r) throw new ErrorException("DB error, query $_q: ".self::$db->error);
		return $r;
	}

	static function db_get_svfile_mtimes($flavourfiles) {
		if (!count($flavourfiles)) return [];
		$q = self::qesc($_q="SELECT file,mtime FROM sv_files WHERE file IN ({sa})", $flavourfiles);
		$r = self::$db->query($q);
		if (!$r) throw new ErrorException("DB error, query $_q: ".self::$db->error);
		$res = [];
		while ($row = $r->fetch_assoc()) $res[$row['file']] = $row['mtime'];
		return $res;
	}


	// Tests, DB schemas

	static function self_tests() {
		parent::self_tests();
		
		self::test_paths();
		self::test_datapoints();
		try {
			self::db_create();
			self::test_status();
			self::vlog("Database: connected and present.");
		} catch (ErrorException $e) {
			die("DB Connection to ".self::$CFG['DB']['host']." FAILED - ".$e->getMessage());
		}
		self::vlog("Self-tests: \x1b[48;5;70;30mPASS\x1b[0m");
	}

	static function test_paths() {
		self::vlog("Testing paths:");
		
		if (!is_dir(self::$CFG['SV_STORAGE_ROOT'])) die("Missing SV storage root: ".self::$CFG['SV_STORAGE_ROOT']."\n");
		self::vlog(" - Will read SVs in root of: \x1b[33m".self::$CFG['SV_STORAGE_ROOT']."\x1b[0m");

		if (!is_dir(self::$CFG['SV_STORAGE_DATA_PATH'])) die("Missing SV storage folder: ".self::$CFG['SV_STORAGE_DATA_PATH']."\n");
		self::vlog(" - Specifically SV Sync config says: \x1b[33m".self::$CFG['SV_STORAGE_DATA_PATH']."\x1b[0m");

		foreach (self::$CFG['f'] as $flav) {
			self::vlog("   - Flavour: \x1b[38;5;78m$flav\x1b[0m");

			$svpath = self::cfgstr('SV_STORAGE_FLAVOUR_PATH',['FLAVOUR'=>$flav]);
			if (!is_dir($svpath)) die("Missing SV storage flavour folder: ".$svpath."\n");
			self::vlog("     - Reading SVs from: \x1b[33m$svpath\x1b[0m");

			/*
			$scrapepath = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flav,'DAY'=>"YYYYMMDD"]);
			self::vlog("     - Temporary scrape folder: \x1b[33m$scrapepath\x1b[0m");

			$telepath = self::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flav]);
			if (!is_dir($telepath)) die("Missing Telemetry output flavour folder: ".$telepath."\n");
			self::vlog("     - Saving telemetry data into: \x1b[33m$telepath\x1b[0m");
			*/
		}
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

	// test group_ranges
	static function test_group_ranges() {
		$input = [1, 2, 3, 5, 6, 8, 9, 10];
		$expected = ["1-3", "5-6", "8-10"];
		$output = self::group_ranges($input);
		if ($output !== $expected) {
			die("FAILED testing group_ranges:\n".print_r($output,1)."\n");
		}
	}

	static function db_create() {
		parent::db_create();

		self::$db->query("SHOW CREATE TABLE sv_files;");
		if (self::$db->error) {
			$schema_sql = "
				CREATE TABLE `sv_files` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`file` char(50) NOT NULL,
					`scrape_time` int(11) DEFAULT NULL,
					`mtime` int(10) DEFAULT NULL,
					`last_event_time` int(10) DEFAULT NULL,
					UNIQUE KEY `file` (`file`),
					UNIQUE KEY `id` (`id`)
				)
				ENGINE=InnoDB
				DEFAULT CHARSET=latin1
				COLLATE=latin1_swedish_ci
				COMMENT='used to mark which SV files have been processed and when';
			";
			self::$db->query($schema_sql);
			if (self::$db->error) 
				throw new ErrorException("Failed to create table `sv_files`: ".self::$db->error);
		}

		self::vlog("DB schema created.");
	}
}
