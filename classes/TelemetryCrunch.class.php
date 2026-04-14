<?php
namespace Zygor\Telemetry;

use Telemetry as Tm;
use TelemetryStatus as TmSt;

/**
 */
class TelemetryCrunch {

	static $CFG = null;

	static function config() {
		self::$CFG = &Telemetry::$CFG; // reference main config for easy access

		$configfile = (array)(@include "config-crunch.inc.php"); // load defaults
		self::$CFG->add($configfile, 50, "crunch config file"); // add to main config with medium priority
	}

	static function startup() {
		self::config();
	}
	
	/**
	 * Combine each day's events from telemetry/\<flavor\>/scraped/\<day\>/*.json into telemetry/\<flavor>\/\<metric\>/\<day\>.json
	 * @deprecated Use TelemetryCrunch::crunch_flavour() instead.
	 */
	static function crunch($flavour) {
		if (!in_array($flavour,array_keys(Telemetry::$CFG['WOW_FLAVOUR_DATA']))) throw new Exception("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

		$tag = "CRUNCH-".strtoupper(str_replace("-","_", $flavour));

		$status['progress']=[];
		$status['totals']=[];
		TmSt::status($tag,[
			'status'=>"CRUNCHING_LISTING",
			'stage'=>2,
			'stageof'=>2,
		]);

		/*
		$scrape_path_root = dirname(self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>'0']));
		Logger::log("Starting rendering from \x1b[38;5;118m".str_replace("/$flavour/","/\x1b[38;5;190m$flavour\x1b[38;5;118m"."/",$scrape_path_root)."\x1b[0m");
		if (!is_dir($scrape_path_root)) {
			Logger::log("Scrape path root does not exist: $scrape_path_root");
			return;																				// #f00
		}

		$path = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>'????????']);
		$days = glob($path,GLOB_ONLYDIR);
		$days = str_replace($scrape_path_root."/","",$days);
		$days = array_values(array_filter($days,function($d) use ($startday,$today) { $d=intval($d); return $d >= 20000000 && $d <= 30000000 && $d >= $startday && $d < $today; }));
		*/
		
		$startday = isset(self::$CFG['start-day']) ? self::$CFG['start-day'] : 0;
		$endtime = self::$CFG['today-too'] ? date("Ymd",NOW) : date("Ymd",strtotime("-1 day")); // exclude today, unless forced; much could change, usually no point rendering today

		TmSt::stat([
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
			TmSt::update_progress($tag,$i,$totaldays);

			$new = self::crunch_day($flavour,$day);
			
			if (is_numeric($new) && $new>0) {
				$newfiles += $new;
				$ndays++;
			}

			TmSt::update_progress($tag,$i,$totaldays);

			if ($ndays>=self::$CFG['MAX_CRUNCH_DAYS']) {
				Logger::log("\x1b[41;37;1m STOP \x1b[0m : Reached max crunch days (".self::$CFG['MAX_CRUNCH_DAYS'].").");
				break;	// #f80
			}
			//die("BOOOO $ndays");
		}

		Logger::log("Crunching of $flavour complete.");
	}

	/** @deprecated */
	static function crunch_day($flavour,$day) {
		/*
		$day_path = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
		if (!is_dir($day_path)) {
			Logger::log("Scrape path does not exist: $day_path");
			return;												// #f00
		}
		*/
		
		$lockname = "telemetry_crunch_day_{$flavour}_{$day}";
		try {
			$locked = Tm::$db->lock($lockname, 0);
			if (!$locked) {
				Logger::vlog(C_MTHD."Skipping day \x1b[38;5;82m$day\x1b[0m, already being processed".C_R);
				return false;									// #f00
			}
			// flock directory
			/*
			$fp = fopen($day_path, "r");
			if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
				Logger::vlog(C_MTHD."Skipping day \x1b[38;5;82m$day\x1b[0m, already being processed".C_R);
				return false;									// #f00
			}
			*/

			Logger::log("Crunching day \x1b[38;5;118m$day\x1b[0m:");

			//Logger::vlog("+ Input path: $day_path/*.json");

			$userfiles = TelemetryScrape::find_files($day_path, self::$CFG['MAX_CRUNCH_HISTORY'], '*.json', true);

			$status['crunch_lastday_day']=$day;					// #0ff
			$status['crunch_lastday_users']=count($userfiles);  // #0ff

			//if (@file_exists($dayfile)) { $status['usedguide_days_existed']++; continue; }

			$userfile_mtimes = array_map("filemtime", $userfiles);
			$newest_userfile_date = max($userfile_mtimes);

			$DEFS = Telemetry::$TOPICS;

			Logger::vlog("+ Newest input file mtime: ".date("Y-m-d H:i:s",$newest_userfile_date));
			Logger::vlog(".- Verifying output file mtimes for freshness...");

			// get maxmtime of each output file type
			foreach ($DEFS as $dp_name=>&$dp_def) {
				/** @var Topic $dp_def */
				$mode = $dp_def->get('output_mode');

				if ($mode=="day_user") {
					// 
					$dayglob = self::cfgstr('DATA_PATH_DPMODE_DAY_USER',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day,'USER'=>"*"]);
					$outuserfiles = glob($dayglob,GLOB_NOSORT);
					$mtimes = array_map("filemtime", $outuserfiles);
					$dp_def->dayuser_mtimes = array_combine($outuserfiles, $mtimes);
					$dp_def->max_out_mtime = max($mtimes) ?: 0;
				} elseif ($mode=="day") {
					$dayfile = self::cfgstr('DATA_PATH_DPMODE_DAY',['FLAVOUR'=>$flavour,'TOPIC'=>$dp_name,'DAY'=>$day]);
					$dp_def->max_out_mtime = @filemtime($dayfile) ?: 0;
				} else {
					$dp_def->max_out_mtime = 0;
				}

				$dp_def->skip = $newest_userfile_date <= $dp_def->max_out_mtime;
				$skip_display = $dp_def->skip;
				$max_out_mtime = $dp_def->max_out_mtime;
				Logger::vlog(sprintf("| Datapoint %-10s (mode: %-8s) - last modified %s%s\x1b[0m - %s", $dp_name, $mode, ($skip_display ? "\x1b[38;5;104m": "\x1b[38;5;174m"), $max_out_mtime?date("Y-m-d H:i:s",$max_out_mtime):"never",($skip_display ? "\x1b[38;5;103mUP-TO-DATE\x1b[0m" : "\x1b[32mUPDATE NEEDED\x1b[0m")));
				/*
				if ($newest_userfile_date < $oldest_outfile_date) {
					if (count($nonew_streak)==0) Logger::log("No new userfiles for $day.");
					$nonew_streak[]=$day;
					continue; // next day!
				}
				if (count($nonew_streak)>1) Logger::log("... ".($nonew_streak[count($nonew_streak)-1]));
				$nonew_streak=[];
				*/
			}
			unset($dp_def);

			// count all $DEFS elements that have 'skip' field using array_column
			$skips = array_sum(array_column($DEFS, 'skip'));
			$nonskips = count($DEFS) - $skips;
			Logger::vlog("'- Verifying out file mtimes for freshness: done. ".($nonskips ? "\x1b[32m{$nonskips} datapoint(s) to render.\x1b[0m" : "\x1b[31mNothing to do.\x1b[0m"));

			if (!$nonskips) return; // #f80

			Telemetry::call_hooks("pre_dayfiles", []);

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

			Telemetry::call_hooks("post_dayfiles", [$DATA]);

			$datacount = array_sum(array_map(function($v) { return count($v); }, $DATA));
			Logger::vlog("Data crunched. $num_read read, $num_crunched crunched: ".join(", ", array_map(function($k,$v) { return "$k=".count($v); }, array_keys($DATA), $DATA)));

		//if ($OPTS['verbose']) print_r($DATA);
		$written=0;

		// write out $DATA to summary file as {datatype}/{day}.json
		foreach ($DEFS as $dp_name=>$dp_def) {
			/** @var Topic $dp_def */
			if ($dp_def->skip) { continue; }
			//if (!$DATA[$dp_name]) continue;  // no data - no job. // #f80
			$dp_data = isset($DATA[$dp_name]) ? $DATA[$dp_name] : [];
			$output_mode = $dp_def->get('output_mode');
			switch ($output_mode) {
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
			Logger::log("Rendering of day $day complete; ".implode(", ",$progs));

			return $written;

		} finally {
			$unlocked = Tm::$db->unlock($lockname);
		}
	}

	static function crunch_flavour($flavour, $crunchersSelected=[]) {
		$topics = Telemetry::$TOPICS;

		// get all crunchers from all topics that match the selected crunchers (if any)
		$crunchers = [];
		foreach ($topics as $name=>$topicObj) {
			/** @var Topic $topicObj */
			foreach ($topicObj->crunchers as $num=>$cruncher) {
				/** @var Cruncher $cruncher */
				$subname = $cruncher->name ?: ($num+1);
				$fullcrunchername = "{$name}/{$subname}";
				if (in_array($fullcrunchername, $crunchersSelected)) {
					$crunchers[] = [
						'topic' => $name,
						'subname' => $subname,
						'obj' => $cruncher,
					];
				}
			}
		}

		Logger::vlog("Running crunchers for flavour \x1b[38;5;78m{$flavour}\x1b[0m...");
		foreach($crunchers as $cruncherInfo) {
			/** @var Cruncher $cruncher */
			$cruncher = $cruncherInfo['obj'];
			$name = $cruncherInfo['topic'];
			$subname = $cruncherInfo['subname'];
			$colorname = "\x1b[38;5;148m{$name}\x1b[0m";
			$colordashsubname = "-\x1b[38;5;118m".$subname."\x1b[0m";
			Logger::vlog("Running cruncher {$colorname}{$colordashsubname}...");

			// create if needed
			if ($cruncher->table_schema !== null && $cruncher->table !== null) {
				$table = $cruncher->table;
				Tm::$db->query("SHOW CREATE TABLE {$table}");
				if (Tm::$db->error()) {
					Logger::vlog("\x1b[31;1mTable '{$table}' for cruncher '{$subname}' does not exist, creating...\x1b[0m");
					$schema_sql = $cruncher->table_schema;
					$schema_sql = str_replace("<TABLE>",$table,$schema_sql);
					Tm::$db->query($schema_sql);
					if (Tm::$db->error()) 
						throw new Exception("Failed to create table `{$table}`: ".Tm::$db->error());
					Logger::vlog("Table '{$table}' created.");
				}
			}

			// start fetching new events to process:

			$flavnum = Tm::flavnum($flavour);
			$type = $cruncher->eventtype ?: $name;

			// get starting point
			$max_id = Tm::$db->query_one(Tm::$db->qesc("SELECT IFNULL(MAX(event_id),0) FROM {$table} WHERE flavnum={d}",$flavnum)) ?: 0;
			Logger::vlog("Processing {$type} events, starting with index ".($max_id+1)."...");

			// get new events
			if ($cruncher->eventsubtype) {
				$getquery = Tm::$db->qesc("SELECT * FROM events WHERE flavnum={d} AND type={s} AND subtype={s} AND id>{d}",$flavnum,$type,$cruncher->eventsubtype,$max_id);
			} else {
				$getquery = Tm::$db->qesc("SELECT * FROM events WHERE flavnum={d} AND type={s} AND id>{d}",$flavnum,$type,$max_id);
			}
			//Logger::vlog("DEBUG: getquery: $getquery");
			$getrequest = Tm::$db->query($getquery);

			if ($getrequest->num_rows==0) {
				Logger::vlog("No new {$name}-{$subname} events to process.");
				continue;
			}
			Logger::vlog("Found ".strval($getrequest->num_rows)." records, processing...");
			
			$count = 0;
			while ($event = $getrequest->fetch_assoc()) {
				$count++;
				//Logger::vlog("Processing ".strval($count)."/".strval($getrequest->num_rows));

				$func = $cruncher->function;
				$fields = $func($event);

				if ($cruncher->action === "insert" && $cruncher->table !== null) {
					$table = $cruncher->table;
					$insertquery = Tm::$db->qarrayesc("INSERT INTO {$table} ({keys}) VALUES ({values})",$fields);
					$insertrequest = Tm::$db->query($insertquery);
					if (Tm::$db->affected_rows()!=1) throw new Exception("FAILED to insert crunched event id {$event['id']} into {$table}");
					if (Tm::$db->error()) throw new Exception("ERROR inserting event id {$event['id']} into {$table}: ".Tm::$db->error());
				}
			}

			if ($cruncher->action === "insert") {
				Logger::vlog("Added ".strval($count)." new {$name}-{$subname} records.");
			}
		}
		Logger::vlog("Crunchers for flavour \x1b[38;5;78m{$flavour}\x1b[0m complete.");
	}

}
