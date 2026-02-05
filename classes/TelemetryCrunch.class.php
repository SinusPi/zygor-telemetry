<?php

/**
 */
class TelemetryCrunch extends Telemetry {

	/**
	 * Combine each day's events from telemetry/\<flavor\>/scraped/\<day\>/*.json into telemetry/\<flavor>\/\<metric\>/\<day\>.json
	 * @deprecated Use TelemetryCrunch::crunch_flavour() instead.
	 */
	static function crunch($flavour) {
		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) throw new Exception("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

		self::$tag = "CRUNCH-".strtoupper(str_replace("-","_", $flavour));

		$status['progress']=[];
		$status['totals']=[];
		self::stat([
			'status'=>"CRUNCHING_LISTING",
			'stage'=>2,
			'stageof'=>2,
		]);

		/*
		$scrape_path_root = dirname(self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>'0']));
		self::log("Starting rendering from \x1b[38;5;118m".str_replace("/$flavour/","/\x1b[38;5;190m$flavour\x1b[38;5;118m"."/",$scrape_path_root)."\x1b[0m");
		if (!is_dir($scrape_path_root)) {
			self::log("Scrape path root does not exist: $scrape_path_root");
			return;																				// #f00
		}

		$path = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>'????????']);
		$days = glob($path,GLOB_ONLYDIR);
		$days = str_replace($scrape_path_root."/","",$days);
		$days = array_values(array_filter($days,function($d) use ($startday,$today) { $d=intval($d); return $d >= 20000000 && $d <= 30000000 && $d >= $startday && $d < $today; }));
		*/
		
		$startday = isset(self::$CFG['start-day']) ? self::$CFG['start-day'] : 0;
		$endtime = self::$CFG['today-too'] ? date("Ymd",NOW) : date("Ymd",strtotime("-1 day")); // exclude today, unless forced; much could change, usually no point rendering today

		self::stat([
			'status'=>"CRUNCHING_DAYS",
		]);

		for ($day = $startday; $day <= $endtime; $day = date("Ymd", strtotime($day . " +1 day"))) {
			$days[] = $day;
		}

		//$status['data_total']=count($files);
		//$files = array_values(array_filter($files,function($fn) use ($from,$to) { return $fn>=$from && $fn<=$to; }));
		//$status['data_match']=count($files);

		$totaldays=count($days);
		$ndays=0;
		$newfiles=0;
		foreach ($days as $i=>$day) {
			self::update_progress(self::$tag,$i,$totaldays);

			$new = self::crunch_day($flavour,$day);
			
			if (is_numeric($new) && $new>0) {
				$newfiles += $new;
				$ndays++;
			}

			self::update_progress(self::$tag,$i,$totaldays);

			if ($ndays>=self::$CFG['MAX_CRUNCH_DAYS']) {
				self::log("\x1b[41;37;1m STOP \x1b[0m : Reached max crunch days (".self::$CFG['MAX_CRUNCH_DAYS'].").");
				break;	// #f80
			}
			//die("BOOOO $ndays");
		}

		self::log("Crunching of $flavour complete.");
	}

	static function crunch_day($flavour,$day) {
		/*
		$day_path = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
		if (!is_dir($day_path)) {
			self::log("Scrape path does not exist: $day_path");
			return;												// #f00
		}
		*/
		
		try {
			$locked = self::db_query_one("SELECT GET_LOCK('telemetry_crunch_day_{$flavour}_{$day}', 0)");
			if (!$locked) {
				self::vlog(C_MTHD."Skipping day \x1b[38;5;82m$day\x1b[0m, already being processed".C_R);
				return false;									// #f00
			}
			// flock directory
			/*
			$fp = fopen($day_path, "r");
			if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
				self::vlog(C_MTHD."Skipping day \x1b[38;5;82m$day\x1b[0m, already being processed".C_R);
				return false;									// #f00
			}
			*/

			self::log("Crunching day \x1b[38;5;118m$day\x1b[0m:");

			//self::vlog("+ Input path: $day_path/*.json");

			$userfiles = self::find_files($day_path, self::$CFG['MAX_CRUNCH_HISTORY'], '*.json', true);

			$status['crunch_lastday_day']=$day;					// #0ff
			$status['crunch_lastday_users']=count($userfiles);  // #0ff

			//if (@file_exists($dayfile)) { $status['usedguide_days_existed']++; continue; }

			$userfile_mtimes = array_map("filemtime", $userfiles);
			$newest_userfile_date = max($userfile_mtimes);

			$DEFS = self::$CFG['TOPICS'];

			self::vlog("+ Newest input file mtime: ".date("Y-m-d H:i:s",$newest_userfile_date));
			self::vlog(".- Verifying output file mtimes for freshness...");

			// get maxmtime of each output file type
			foreach ($DEFS as $dp_name=>&$dp_def) {
				$mode = $dp_def['output_mode'];

				if ($mode=="day_user") {
					// 
					$dayglob = self::cfgstr('DATA_PATH_DPMODE_DAY_USER',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day,'USER'=>"*"]);
					$outuserfiles = glob($dayglob,GLOB_NOSORT);
					$mtimes = array_map("filemtime", $outuserfiles);
					$dp_def['dayuser_mtimes'] = array_combine($outuserfiles, $mtimes);
					$dp_def['max_out_mtime'] = max($mtimes) ?: 0;
				} elseif ($mode=="day") {
					$dayfile = self::cfgstr('DATA_PATH_DPMODE_DAY',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day]);
					$dp_def['max_out_mtime'] = @filemtime($dayfile) ?: 0;
				} else {
					$dp_def['max_out_mtime'] = 0;
				}

				$dp_def['skip'] = $newest_userfile_date <= $dp_def['max_out_mtime'];
				self::vlog(sprintf("| Datapoint %-10s (mode: %-8s) - last modified %s%s\x1b[0m - %s", $dp_name, $mode, ($dp_def['skip'] ? "\x1b[38;5;104m": "\x1b[38;5;174m"), $dp_def['max_out_mtime']?date("Y-m-d H:i:s",$dp_def['max_out_mtime']):"never",($dp_def['skip'] ? "\x1b[38;5;103mUP-TO-DATE\x1b[0m" : "\x1b[32mUPDATE NEEDED\x1b[0m")));
				/*
				if ($newest_userfile_date < $oldest_outfile_date) {
					if (count($nonew_streak)==0) self::log("No new userfiles for $day.");
					$nonew_streak[]=$day;
					continue; // next day!
				}
				if (count($nonew_streak)>1) self::log("... ".($nonew_streak[count($nonew_streak)-1]));
				$nonew_streak=[];
				*/
			}
			unset($dp_def);

			// count all $DEFS elements that have 'skip' field using array_column
			$skips = array_sum(array_column($DEFS, 'skip'));
			$nonskips = count($DEFS) - $skips;
			self::vlog("'- Verifying out file mtimes for freshness: done. ".($nonskips ? "\x1b[32m{$nonskips} datapoint(s) to render.\x1b[0m" : "\x1b[31mNothing to do.\x1b[0m"));

			if (!$nonskips) return; // #f80

			self::call_hooks("pre_dayfiles", []);

			$DATA = array_fill_keys(array_keys($DEFS), []);

			//
			// crunch scraped user datapoints into $DATA
			//
			
			$num_read=0;
			$num_crunched=0;
			foreach ($userfiles as $fn) {
				$status['crunch_lastday_userfile_last']=$fn;
				$userfn = basename($fn,".json");

				//if (@filemtime($scrape_path."/".$fn)<$oldest_outfile_time) continue; // user file older than all daily outfile types
				if (self::$CFG['rendermask'] && !fnmatch(self::$CFG['rendermask'],$fn)) continue; // #f80

				// ALL datapoints for USER on DAY
				$user_day_data = @json_decode(@file_get_contents($fn),true);
				if (!$user_day_data) continue;                                                    // #f80

				$num_read++;

				foreach ($user_day_data as $line) {
					$def = $DEFS[$line['type']] ?: null; if (!$def) continue; if ($def['skip']) continue; // up-to-date
					$def['crunch_func']($line,/*&*/$DATA,/*&*/$DATA[$line['type']], $userfn); // puts extracted data into global $DATA or local $dp_data datapoints.
					$num_crunched++;
				}
			}

			self::call_hooks("post_dayfiles", [$DATA]);

			$datacount = array_sum(array_map(function($v) { return count($v); }, $DATA));
			self::vlog("Data crunched. $num_read read, $num_crunched crunched: ".join(", ", array_map(function($k,$v) { return "$k=".count($v); }, array_keys($DATA), $DATA)));

			//if ($OPTS['verbose']) print_r($DATA);
			$written=0;

			// write out $DATA to summary file as {datatype}/{day}.json
			foreach ($DEFS as $dp_name=>$dp_def) {
				if ($dp_def['skip']) { continue; }
				//if (!$DATA[$dp_name]) continue;  // no data - no job. // #f80
				$dp_data = $DATA[$dp_name] ?: [];
				switch ($dp_def['output_mode']) {
					case "day":
						$outfile = self::cfgstr('DATA_PATH_DPMODE_DAY',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day]);
						self::vflog("renderdetails","Rendering {$dp_name} mode='day' into {$outfile}");
						@mkdir(dirname($outfile),0777,true);
						@file_put_contents($outfile,@json_encode($dp_data));
						$status[$dp_name.'_days_written']++;
						$status[$dp_name.'_days_lastfile']=$outfile;
						$written++;
						break;
					case "day_user":
						foreach ($dp_data as $userfn=>$userdata) {
							$outfile = self::cfgstr('DATA_PATH_DPMODE_DAY_USER',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day,'USER'=>$userfn]);
							self::vflog("renderdetails","Rendering {$dp_name} mode='day_user' {$userfn} into {$outfile}");
							@mkdir(dirname($outfile),0777,true);
							@file_put_contents($outfile,@json_encode($userdata));
							$status[$dp_name.'_days_written']++;
							$status[$dp_name.'_days_lastfile']=$outfile;
							$written++;
						}
						// else
						if (!$dp_data) {
							// record empty file, to prevent future processing
							$outfile = self::cfgstr('DATA_PATH_DPMODE_DAY_USER',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day,'USER'=>'_@_']);
							self::vflog("renderdetails","Rendering empty {$dp_name} mode='day_user' into {$outfile}");
							@mkdir(dirname($outfile),0777,true);
							@file_put_contents($outfile,@json_encode([]));
							$written++;
						}
						break;
				}
			}
			unset($dp_data);

			$progs=[]; foreach ($DEFS as $dp_name=>$dp_def) { $progs[]=$dp_name.'='.$status[$dp_name.'_days_written']; }
			self::log("Rendering of day $day complete; ".implode(", ",$progs));

			return $written;

		} finally {
			$unlocked = self::db_query_one("SELECT RELEASE_LOCK('telemetry_render_day_{$flavour}_{$day}', 0)");
		}
	}

	static function crunch_flavour($flavour) {
		$topics = self::$CFG['TOPICS'];

		self::vlog("Running crunchers for flavour \x1b[38;5;78m{$flavour}\x1b[0m...");
		foreach($topics as $name=>$topic) {
			foreach($topic['crunchers'] as $cruncher) {
				self::vlog("Running cruncher \x1b[38;5;148m{$name}\x1b[0m-\x1b[38;5;118m{$cruncher['name']}\x1b[0m...");

				// create if needed
				if (isset($cruncher['table_schema'],$cruncher['table'])) {
					$table = $cruncher['table'];
					self::db_qesc("SHOW CREATE TABLE {$table}");
					if (self::$db->error) {
						self::vlog("\x1b[31;1mTable '{$table}' for cruncher '{$cruncher['name']}' does not exist, creating...\x1b[0m");
						$schema_sql = $cruncher['table_schema'];
						self::$db->query($schema_sql);
						if (self::$db->error) 
							throw new Exception("Failed to create table `{$table}`: ".self::$db->error);
						self::vlog("Table '{$table}' created.");
					}
				}

				// start fetching new events to process:

				$flavnum = self::flavnum($flavour);
				$type = $cruncher["eventtype"];

				// get starting point
				$max_id = self::db_query_one(self::qesc("SELECT IFNULL(MAX(event_id),0) FROM {$table} WHERE flavnum={d}",$flavnum)) ?: 0;
				self::vlog("Processing {$type} events, starting with index {$max_id}...");

				// get new events
				$getquery = self::qesc("SELECT * FROM events WHERE flavnum={d} AND type={s} AND id>{d}",$flavnum,$type,$max_id);
				//self::vlog("DEBUG: getquery: $getquery");
				$getrequest = self::$db->query($getquery);

				if ($getrequest->num_rows==0) {
					self::vlog("No new {$name}-{$cruncher['name']} events to process.");
					continue;
				}
				self::vlog("Found ".strval($getrequest->num_rows)." records, processing...");
				
				$count = 0;
				while ($event = $getrequest->fetch_assoc()) {
					$count++;
					//self::vlog("Processing ".strval($count)."/".strval($getrequest->num_rows));

					$func = $cruncher['crunch_function'] ?: $cruncher['function'];

					$fields = $func($event);

					if ($cruncher['action']=="insert" && isset($cruncher['table'])) {
						$table = $cruncher['table'];
						$insertquery = self::qarrayesc("INSERT INTO {$table} ({keys}) VALUES ({values})",$fields);
						$insertrequest = self::$db->query($insertquery);
						if (self::$db->affected_rows!=1) throw new Exception("FAILED to insert event id {$event['id']} crunched into {$table}");
						if (self::$db->error) throw new Exception("ERROR inserting event id {$event['id']} into {$table}: ".self::$db->error);
					}
				}

				if ($cruncher['action']=="insert") {
					self::vlog("Added ".strval($count)." new {$name}-{$cruncher['name']} records.");
				}
			}
		}
		self::vlog("Crunchers for flavour \x1b[38;5;78m{$flavour}\x1b[0m complete.");
	}

}