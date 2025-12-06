<?php
require_once "includes/zygor.class.inc.php";

if (!defined('DAY')) define('DAY', 24 * 60 * 60);
if (!defined('NOW')) define('NOW', time());

//colors
define("C_MTHD","\x1b[38;5;134m");
define("C_R","\x1b[0m");

/*
 *          mtime = 2025-10-07          stime? 20251013
 * 
 * flavour/user-bnet--SV--ZGV.lua.gz
 *                                   -> 20251004
 *                                                   -> ui
 *                                                   -> usedguide
 *                                   -> 20251005
 *                                                   -> ui
 *                                                   -> usedguide
 *                                   -> 20251006
 *                                                   -> ui
 *                                                   -> usedguide
 */
class Telemetry {
	static $CFG = [
		'TELEMETRY_ROOT' => "telemetry",
		'STATUS_FILENAME' => "default.status.json",
		'LOG_FILENAME' => "telemetry.log",
		'MAX_RENDER_DAYS' => 30,
		'VERBOSE_FLAGS' => [],
		'STATUS_INTERVAL' => 2,
	];
	static $last_status = [];

	static $db = null;

	static function config($cfg) {
		$configfile = (array)(require "config.inc.php");
		self::$CFG = $configfile + $cfg + self::$CFG;
	}

	static function load_topics() {
		$topics = [];
		$files = glob(__DIR__."/topic-*.inc.php");
		foreach ($files as $file) {
			$topic_name = substr(basename($file),6,-8); // strip topic- and .inc.php
			$topic_data = include $file;
			if (!$topic_data) throw new Exception("Failed to parse topic file $file");
			$topics[$topic_name] = $topic_data;
		}
		self::$CFG['SCRAPE_TOPICS'] = $topics;
	}

	static function set_error_reporting() {
		error_reporting(E_ALL^E_WARNING^E_NOTICE);
		set_error_handler([__CLASS__,'write_error_to_status']);
		register_shutdown_function([__CLASS__,'on_shutdown']);
	}

	static function write_error_to_status($errno, $errstr, $errfile, $errline) {
		global $times;
		if (!($errno & error_reporting())) {
			//echo "$errno $errline:$errstr\n";
			return false;
		}
		self::status("CORE",['status'=>"ERROR",'error'=>['errno'=>$errno,'errstr'=>$errstr,'errfile'=>$errfile,'errline'=>$errline],'times'=>$times]);
		die("$errno $errfile:$errline $errstr\n");
	}

	static function on_shutdown() {
		$err=error_get_last();
		if ($err)
			self::write_error_to_status($err['type'],$err['message'],$err['file'],$err['line']);
	}

	static function status($tag,$data,$keep=false) {
		$filename = self::$CFG['TELEMETRY_ROOT']."/".self::$CFG['STATUS_FILENAME'];
		touch($filename);
		$F = fopen($filename, "r");
		if (!flock($F, LOCK_EX)) { fclose($F); throw new Exception("Cannot set status lock: $filename"); }
		$last_status = file_get_contents($filename) ?: '{}';
		$last_status_j = json_decode($last_status, true) ?: [];
		$last_status_j_t = $keep ? ($last_status_j[$tag] ?: []) : [];
		$last_status_j_t = array_replace_recursive($last_status_j_t,$data);
		$last_status_j[$tag] = $last_status_j_t;
		self::$last_status = $last_status_j;
		file_put_contents($filename,json_encode($last_status_j));
		flock($F, LOCK_UN);
		fclose($F);
	}
	static function &get_last_status($tag) {
		if (!self::$last_status[$tag]) self::$last_status[$tag] = [];
		return self::$last_status[$tag];
	}

	/**
	 * @return array [data_total,files,data_match]
	 */
	static function read_days($folder,$from=0,$to=9999999999) {
		$files = explode("\n",shell_exec("find $folder -name '*.json'"));
		$files = str_replace("$folder/","",$files);
	
		$result['data_total']=count($files);
		$result['files'] = array_values(array_filter($files,function($fn) use ($from,$to) { return $fn>=$from && $fn<=$to; })); // assumes JSON filenames are timestamps
		$result['data_match']=count($result['files']);

		return $result;
	}

	// use glob to find all matching files, allowing ** to recurse into all folders
	/** @deprecated */
	static function rglob__old($pat) {
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

	static function get_counts($flavour,$topic) {
		$def = self::$CFG['SCRAPE_TOPICS'][$topic] ?: null;
		if (!$def) return ['total'=>0,'matching'=>0];
		if ($def['output_mode']=="day") {
			$file_glob = self::cfgstr('DATA_PATH_DPMODE_DAY',['FLAVOUR'=>$flavour,'TOPIC'=>$topic,'DAY'=>"*"]);
			$files = glob($file_glob);
			foreach ($files as $file) {
				readfile($file);
				die();
			}
		}
		elseif ($def['output_mode']=="day_user") {
			$file_glob = self::cfgstr('DATA_PATH_DPMODE_DAY_USER',['FLAVOUR'=>$flavour,'TOPIC'=>$topic,'DAY'=>"*",'USER'=>"*.json"]);
			$files = glob($file_glob);
		}

		echo "Files for $flavour - $topic:\n";
		print_r($files);
		die();

	}
		
	static function date_add_dashes($d) {
		if (preg_match("/^(\\d{4})(\\d{2})(\\d{2})$/",$d,$m)) $d=$m[1]."-".$m[2]."-".$m[3]; // force YYYY-MM-DD
		if (preg_match("/^\d{4}-\d{2}-\d{2}$/",$d)) return $d;
		throw new Exception("Date format invalid: $d");
	}
	static function date_strip_dashes($d) {
		if (preg_match("/^(\\d{4})-(\\d{2})-(\\d{2})$/",$d,$m)) $d=$m[1].$m[2].$m[3]; // force YYYYMMDD
		if (preg_match("/^\\d{8}$/",$d)) return $d;
		throw new Exception("Date format invalid: $d");
	}

	/** unused
	 * @deprecated
	 */
	static function log_merged($s=null,$merge_tag=null) {
		static $i=0;
		static $merges_last = [];
		static $prev_merge_tag = null;
		$is_merge_tag_new = ($prev_merge_tag==$merge_tag);
		$prev_merge_tag=$merge_tag;
		if ($is_merge_tag_new) {
			echo "new tag $merge_tag\n";
			if (count($merges_last)>0) { //dump
				echo "count\n";
				if (count($merges_last)>2) self::log("...");
				foreach ($merges_last as $mergelast) self::log($mergelast);
				$merges_last=[];
				return;
			}
		} else {
			echo "old tag\n";
			$merges_last[$merge_tag]=$s;
			return;
		}
		echo "plain\n";
		if ($s) self::log($s);
		$i++; if ($i>100) die();
	}

	// utility functions

	static function log($s,$tag=null) {
		file_put_contents(
			self::$CFG['TELEMETRY_ROOT']."/".self::$CFG['LOG_FILENAME'],
			date("Y-m-d H:i:s").".".sprintf("%03d",explode(" ", microtime())[0]*1000)." [$tag] ".$s."\n",
			FILE_APPEND|LOCK_EX
		);
		if (function_exists('posix_isatty') ? posix_isatty(STDIN) : (php_sapi_name() === 'cli')) echo $s."\n";
	}

	static function vlog($s) {
		if (self::$CFG['verbose']) self::log($s);
	}

	static function vflog($flag, $message) {
		if (self::$CFG['verbose'] && in_array($flag, self::$CFG['VERBOSE_FLAGS'])) self::log("[$flag] $message");
	}

	static function repstr($str,$data=[]) {
		return str_replace(array_map(function($k) { return "<$k>"; },array_keys($data)),array_values($data),$str);
	}

	static function cfgstr($str,$data=[]) {
		return self::repstr(self::$CFG[$str], $data + self::$CFG);
	}

	/**
	 * Split a full path into "user/bnet--SavedVariables..." and "user/bnet" slug.
	 */
	static function split_filename($fullpath) {
		$filename_userfile = basename(dirname($fullpath))."/".basename($fullpath); // remains: user/bnet--SavedVariables--Zygor.....lua.gz
		$filename_slug = preg_replace("/--SavedVariables--ZygorGuidesViewer.*[\\.luagz]+/","",$filename_userfile); // remains: user/bnet
		return [$filename_userfile, $filename_slug];
	}

	static function db_connect() {
		$cfg = self::$CFG['DB'];
		self::$db = self::_connect_db($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
	}

	static function _connect_db($host,$user,$pass,$db) {
		$mysqli = new mysqli($host, $user, $pass, $db);
		if ($mysqli->connect_errno) {
			throw new Exception("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
		}
		$mysqli->set_charset("utf8mb4");
		return $mysqli;
	}

	static function db_disconnect($conn) {
		if ($conn) $conn->close();
	}

	static function flavnum($flavour) {
		$flavs = ['wow'=>1,'wow-classic'=>2,'wow-classic-tbc'=>3];
		$flavnum = $flavs[$flavour] ?: 0;
		if ($flavnum===0) throw new Exception("Unknown flavour '$flavour'");
		return $flavnum;
	}

	static function qesc($query,...$args) {
		return Zygor::qesc(self::$db, $query, ...$args);
	}

	static function db_get_svfile_mtimes($flavourfiles) {
		if (!count($flavourfiles)) return [];
		$q = self::qesc("SELECT file,mtime FROM sv_files WHERE file IN ({sa})", $flavourfiles);
		$r = self::$db->query($q);
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		$res = [];
		while ($row = $r->fetch_assoc()) $res[$row['file']] = $row['mtime'];
		return $res;
	}

	static function db_query_one($query) {
		$r = self::$db->query($query);
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		return $r->fetch_row()[0];
	}

	/**
	 * Save scraped data for a specific day and user/account.
	 */
	static function store_day_scrape($flavour,$day,$user,$acct, $daydata,$mtime, &$totals) {
		if (!$daydata) 
			return ++$totals['tmfiles_empty'];

		$flavnum = self::flavnum($flavour);
		$q = self::qesc("SELECT 1 FROM sv_scrapes WHERE flavnum={s} AND user={s} AND acct={s} AND day={d} LIMIT 1", $flavnum, $user, $acct, $day);
		$r = self::$db->query($q);
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		if ($r->num_rows) {
			// Entry already exists, skip it
		} else {
			// New entry, insert it
			$q = self::qesc("INSERT INTO sv_scrapes (flavnum,user,acct,day,data,mtime) VALUES ({s},{s},{s},{d},{s},{d})", $flavnum, $user, $acct, $day, json_encode($daydata), $mtime);
			$r = self::$db->query($q);
			if (!$r) throw new Exception("DB error: ".self::$db->error);
		}

		//self::vlog("Saving ".count($daydata)." scrapes for \x1b[33;1m$user @ $acct\x1b[0m");

		foreach ($daydata as $line) $totals['types'][$line['type']]++;
		$totals['tmfiles_written']++;
	}

	static function db_store_datapoints($flavour,$sv_file_id,$datapoints) {
		if (!count($datapoints)) return;
		$chunk_size = 100;
		$values = [];
		$flavnum = self::flavnum($flavour);
		foreach ($datapoints as $dp) {
			$time = intval($dp['time'] ?: 0); unset($dp['time']);
			$type = $dp['type'] ?: '?'; unset($dp['type']);
			if ($type=="ui") { $type="ui_".($dp['event'] ?: '?'); unset($dp['event']); }
			$values[] = self::qesc("({d},{d},{d},{s},{s})", $flavnum, $sv_file_id, $time, $type, json_encode($dp));
		}
		$chunks = array_chunk($values, $chunk_size);
		$inserted = 0;
		foreach ($chunks as $chunk) {
			$q = "INSERT INTO events (flavnum,file_id,time,type,data) VALUES ".join(",",$chunk);
			$r = self::$db->query($q);
			if (!$r) throw new Exception("DB error: ".self::$db->error);
			$inserted += self::$db->affected_rows;
		}
		return $inserted;
	}

	static function db_get_sv_file_data($flavourfile) {
		$q = self::qesc("SELECT * FROM sv_files WHERE file={s}  LIMIT 1  FOR UPDATE  NOWAIT", $flavourfile);
		$r = self::$db->query($q);
		if (!$r && self::$db->errno==3572) { // lock wait timeout
			return null;
		}
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		if ($r->num_rows) {
			$row = $r->fetch_assoc();
			return $row;
		} else {
			$q = self::qesc("INSERT INTO sv_files (file) VALUES ({s})", $flavourfile);
			$r = self::$db->query($q);
			if (!$r) throw new Exception("DB error: ".self::$db->error);
			$id = self::$db->insert_id;
			$q2 = self::qesc("SELECT * FROM sv_files WHERE id={d} LIMIT 1 FOR UPDATE", $id);
			$r2 = self::$db->query($q2);
			if (!$r2) throw new Exception("DB error: ".self::$db->error);
			if ($r2->num_rows) {
				$row2 = $r2->fetch_assoc();
				return $row2;
			}
		}
	}

	static function db_update_sv_file_times($sv_file_id,$mtime,$scrape_time,$last_event_time) {
		$q = self::qesc("UPDATE sv_files SET mtime={d}, scrape_time={d}, last_event_time={d} WHERE id={d}", $mtime, $scrape_time, $last_event_time, $sv_file_id);
		$r = self::$db->query($q);
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		return $r;
	}

}

class FileLockedException extends Exception {
	// Custom exception for file locking issues
}


/**
 * Set of utilities to comb through user-supplied SV files for telemetry data.
 * Each datapoint is extracted by a Lua script, has a "type" and "time" field.
 * Scrape: User data is grouped by day and saved to telemetry/<flavour>/_scrapes_/<day>/<user>@<acct>.json
 * Render: User data is read from the above files and processed for display.
 */
class TelemetryScrapeSVs extends Telemetry {
	static $tag = "SCRAPE";

	static function config($cfg=[],$sync_cfg=[]) {
		parent::config($cfg);

		self::$CFG = $cfg + (array)(@include "config-scrape.inc.php") + self::$CFG;

		// load sync config
		@include self::$CFG['SV_STORAGE_ROOT']."/config.inc.php"; // defines SYNC_CFG
		if (!$SYNC_CFG) throw new Exception("Failed to load sync config from ".self::$CFG['SV_STORAGE_ROOT']."/config.inc.php");
		self::$CFG['SV_STORAGE_DATA_PATH'] = self::cfgstr('SV_STORAGE_DATA_PATH',['SYNC_FOLDER'=>$SYNC_CFG['folder']]);

		if (self::$CFG['verbose']) {
			self::log(get_called_class()." config:");
			self::log(join("\n",array_map(function($k,$v) { if (is_array($v)) $v="[".join(",",$v)."]"; if ($v===TRUE) $v="Y"; if ($v===FALSE) $v="N"; return "\x1b[32m{$k}\x1b[0m=\x1b[33m{$v}\x1b[0m"; }, array_keys(self::$CFG), array_map(function($s) { return is_string($s) ? preg_replace("/(<.*?>)/","\x1b[35m$1\x1b[33m",$s) : $s; }, self::$CFG))));
		}
	}

	static function stat($data,$keep=false) {
		return parent::status(self::$tag,$data,$keep);
	}
	static function &get_last_status($tag=null) {
		return parent::$last_status[self::$tag];
	}
	static function log($s,$tag=null) {
		return parent::log($s,self::$tag);
	}

	static function find_files($path, $days_old=null, $filemask, $loud=false) {
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
	}

	/**
	 * Grab data from SVs, store into folder organized by day/useraccount
	 * @param string $flavour
	 */
	static function scrape_flavour($flavour) {
		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) throw new Exception("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

		self::db_connect();

		$topics = self::$CFG['SCRAPE_TOPICS'];
		$sync_path = self::cfgstr('SV_STORAGE_FLAVOUR_PATH',["FLAVOUR"=>$flavour]);

		self::stat(['status'=>"SCRAPING",'stage'=>1,'stageof'=>2,'flavour'=>$flavour,'progress'=>[],'time_started'=>time(),'time_started_hr'=>date("Y-m-d H:i:s")]);
		
		self::log("Starting scrape of flavour '\x1b[38;5;78m{$flavour}\x1b[0m' in \x1b[33;1m{$sync_path}\x1b[0m.");

		$days_old = intval(self::$CFG['TELEMETRY_FILE_AGE']/DAY)+1;
		self::log("Enumerating files $days_old days old matching ".self::$CFG['filemask']);

		
		$t1 = microtime(true);
		$files = self::find_files($sync_path, $days_old, self::$CFG['filemask'], true); // FINDING FILES. TAKES LONG.
		$t2 = microtime(true);
		self::vlog("Found ".count($files)." files in ".round($t2-$t1,2)."s");

		while ($files[0]=="") array_shift($files);
		//$files = str_replace($sync_path."/","",$files);

		self::stat(['files_total'=>count($files)],true);
		self::log(count($files)." files found.");


		if (!count($files)) {
			self::log("Nothing to do here.");
			return;
		}

		$metrics = [
			'languages'=>[],
		];

		$freshfiles = $files; //array_values(array_filter($files,function($f) { return NOW-filemtime($f)<=TELEMETRY_INTERVAL; }));

		$freshhashes = array_map(function($f) use ($flavour) { return substr(md5($flavour."/".$f),0,8); },$freshfiles);
		$counts = array_count_values($freshhashes);
		$dupes = array_filter($counts,function($c) { return $c>1; });
		if (count($dupes)) {
			die ("Warning: ".count($dupes)." duplicate files found (same name in different folders): ".join(", ",array_keys($dupes)).". They will be processed only once.");
		}

		self::stat(['status'=>"EXTRACTING",'files_fresh'=>count($freshfiles),'files_skipped'=>0,'tmfiles_skipped'=>0,'tmfiles_written'=>0,'tmfiles_last'=>"",'file_last'=>"",'not_files'=>0,'broken_lua'=>0]);
		//self::log(count($freshfiles)." of them are fresh enough (".intval(TELEMETRY_INTERVAL/DAY)." days).");

		//$telefolder = self::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour]);
		//$last_scrape_dates = (array)@json_decode(@file_get_contents($telefolder."/".self::$CFG['MTIMES_CACHE_FILENAME'])); // names relative to sync folder
		$slice=100; $qs=0;
		$last_mtimes = [];
		$t1 = microtime(true);
		for ($ffi=0;$ffi<count($freshfiles);$ffi+=$slice) {
			$batch = array_slice($freshfiles,$ffi,$slice);
			$batch = array_map(function($f) use ($flavour) { $f=self::split_filename($f)[1]; return $flavour."/".$f; }, $batch);  // flavour/user/bnet
			$last_mtimes_batch = self::db_get_svfile_mtimes($batch);
			$qs++;
			if ($last_mtimes_batch) $last_mtimes += $last_mtimes_batch;
		}
		$t2 = microtime(true);
		self::vlog("Loaded ".count($last_mtimes)." last scrape dates from DB in $qs queries and ".round($t2-$t1,2)."s");

		
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

		foreach ($freshfiles_to_process as $n=>$filename_full) {
			self::stat(['file_last'=>$filename_full],true);
			list (,$filename_slug) = self::split_filename($filename_full);
			$user = basename($filename_slug);
			$bnet = basename(dirname($filename_slug));
			$userfolder = dirname($filename_full);

			self::vlog("Scraping SV: \x1b[38;5;110m$user\x1b[0m/\x1b[38;5;116m$bnet\x1b[0m\x1b[30;1m--SavedVariables...\x1b[0m");

			try { // flock block
				$fl = fopen($userfolder, 'rb');
				if (!$fl || !flock($fl, LOCK_EX | LOCK_NB)) {
					fclose($fl);
					self::vlog(C_MTHD."Input folder locked: $userfolder".C_R);
					throw new FileLockedException("Input folder locked: $userfolder");
				}

				self::$db->begin_transaction();

				$flavourfile = $flavour."/".$filename_slug; // flavour/user/bnet
				$sv_file_data = self::db_get_sv_file_data($flavourfile);
				if (!$sv_file_data) {
					self::log("Cannot get sv_file_data for $filename_full. Locked?");					
					throw new FileLockedException("DB locked for $flavourfile");
				}

				$last_event_stored = $sv_file_data['last_event_time'] ?: 0;

				// ===============================================================

				$sv_raw = self::read_raw_sv($filename_full);
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
				self::vlog("Datapoints extracted by type: " . join(", ", array_map(function ($item, $key) { return "\x1b[38;5;72m$key\x1b[0m:$item"; }, array_values($counts), array_keys($counts))));

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

				$inserted = self::db_store_datapoints($flavour,$sv_file_data['id'],$extracted['datapoints']);
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
				self::update_progress($n,count($freshfiles_to_process),['totals'=>$totals],self::$CFG['verbose']);

				// obey limit
				if (isset(self::$CFG['limit']) && $n>=self::$CFG['limit']) {
					echo "Limit ".self::$CFG['limit']." hit, aborting.\n";
					break;
				}
			} catch (FileLockedException $e) {
				self::vlog("Input file locked, skipping: $filename_full");
				self::$db->rollback();
				continue;
			} catch (Exception $e) {
				self::log("ERROR processing $filename_full: " . $e->getMessage());
				throw $e;
			} finally {
				// unlock
				if (isset($fl)) { flock($fl, LOCK_UN); fclose($fl); }
				self::$db->commit();
			}
			
		}
		//self::write_intermediate_mtimes($flavour,$last_scrape_dates,true);
		$tot1 = array_filter($totals,function($v) { return is_numeric($v); });
		$tots = array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));
		self::log("Scrape of $flavour complete; ".implode(", ",$tots));

		if (count($totals['files_without_zgvs'])/(count($freshfiles_to_process)-$totals['files_skipped'])>0.5)
			self::log("Weird. Out of ".(count($freshfiles_to_process)-$totals['files_skipped'])." files read, ".count($totals['files_without_zgvs'])." had no ZGVs.");
	}

	/**
	 * Save scraped data for a specific day and user/account.
	 *
	 */
	/** @obsolete */
	static function save_day_scrape($flavour,$day,$user,$acct, $daydata, &$totals) {
		if (!$daydata) 
			return ++$totals['tmfiles_empty'];
		$scrape_folder = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
		mkdir($scrape_folder,0777,true);
		if (!is_dir($scrape_folder)) throw new Exception("Failed to create/access scrape folder at $scrape_folder");
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


	/**
	 * Combine each day's userfiles from telemetry/\<flavor\>/scraped/\<day\>/*.json into telemetry/\<flavor>\/\<metric\>/\<day\>.json
	 */
	static function render_days($flavour) {
		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) throw new Exception("Unsupported flavour '{$flavour}' (supported: ".join(", ",array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).")");

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
			self::update_progress($i,$totaldays);

			$new = self::render_day($flavour,$day);
			
			if (is_numeric($new) && $new>0) {
				$newfiles += $new;
				$ndays++;
			}

			self::update_progress($i,$totaldays);

			if ($ndays>=self::$CFG['MAX_RENDER_DAYS']) {
				self::log("\x1b[41;37;1m STOP \x1b[0m : Reached max render days (".self::$CFG['MAX_RENDER_DAYS'].").");
				break;	// #f80
			}
			//die("BOOOO $ndays");
		}

		self::log("Rendering of $flavour complete.");
	}

	static function render_day($flavour,$day) {
		/*
		$day_path = self::cfgstr('SCRAPES_PATH',['FLAVOUR'=>$flavour,'DAY'=>$day]);
		if (!is_dir($day_path)) {
			self::log("Scrape path does not exist: $day_path");
			return;												// #f00
		}
		*/
		
		try {
			$locked = self::db_query_one("SELECT GET_LOCK('telemetry_render_day_{$flavour}_{$day}', 0)");
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

			self::log("Rendering day \x1b[38;5;118m$day\x1b[0m:");

			//self::vlog("+ Input path: $day_path/*.json");

			$userfiles = self::find_files($day_path, self::$CFG['MAX_RENDER_HISTORY'], '*.json', true);

			$status['crunch_lastday_day']=$day;					// #0ff
			$status['crunch_lastday_users']=count($userfiles);  // #0ff

			//if (@file_exists($dayfile)) { $status['usedguide_days_existed']++; continue; }

			$userfile_mtimes = array_map("filemtime", $userfiles);
			$newest_userfile_date = max($userfile_mtimes);

			$DEFS = self::$CFG['SCRAPE_TOPICS'];

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

	static function update_progress($n,$total,$extra=[],$force=false) {
		static $time_last_status=0;
		static $n_last=0;
		static $speedbuffer=20;
		static $speeds=[]; if (count($speeds)==0) $speeds=array_fill(0,$speedbuffer,0);

		if (!self::get_last_status()['time_started']) self::stat(['time_started'=>time()],true);

		if ((time()-$time_last_status >= self::$CFG['STATUS_INTERVAL']) || $force) {
			$mitime = microtime(true);
			$time_elapsed = $mitime-self::get_last_status()['time_started'];
			$last_time = $mitime-$time_last_status;
			$last_progress = $n-$n_last;
			$speed = $last_progress/$last_time;
			$speeds[]=$speed; array_shift($speeds);
			$speed_avg=array_sum($speeds)/count($speeds);
			$remaining = $total-$n;
			$time_remaining_est = $remaining/$speed_avg;
	
			$bar_length = 10;

			$tot1=array_filter($extra['totals'] ?: [],function($v) { return is_numeric($v); });
			$tots=array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));

			$progress = [
				'progress'=>[
					'progress_raw'=>$n+1,
					'progress_total'=>$total,
					'progress_bar'=>str_repeat("#",floor($bar_length*($n+1)/$total)).str_repeat(" ",$bar_length-floor($bar_length*($n+1)/$total)),
					'progress_percent'=>floor(100*($n+1)/$total),
					'time_elapsed'=>$time_elapsed,
					'speed_fps'=>$speed_avg,
					'time_remaining'=>floor($time_remaining_est),
					'time_total_est_hr'=>date("Y-m-d H:i:s",time()+$time_remaining_est),
				],
			] + $extra;
			//print_r(self::get_last_status());
			self::stat($progress,true);
			//print_r(self::get_last_status());
			//die();
			echo sprintf(
				"Progress: [%s] %2d%% (%5d/%5d) - %ds elapsed, %ds remaining; totals: %s\n",
				$progress['progress']['progress_bar'],
				$progress['progress']['progress_percent'],
				$progress['progress']['progress_raw'],
				$progress['progress']['progress_total'],
				$progress['progress']['time_elapsed'],
				$progress['progress']['time_remaining'],
				implode(", ", $tots)
			);
			$time_last_status = time();
			$n_last=$n;
		}
	}

	static function write_intermediate_mtimes($flavour,$last_scrape_dates,$force=false) {
		static $time_last_mtimes=0;
		if ($force || time()-$time_last_mtimes >= self::$CFG['MTIMES_WRITE_INTERVAL']) {
			$mtimes_cache_filename = self::cfgstr('FLAVOUR_PATH',['FLAVOUR'=>$flavour])."/".self::$CFG['MTIMES_CACHE_FILENAME'];
			$f=file_put_contents($mtimes_cache_filename,json_encode($last_scrape_dates),LOCK_EX);
			if (!$f) throw new Exception("Cannot write mtimes cache");
			$time_last_mtimes = time();
		}
	}

	static function read_raw_sv($filename) {
		// read gzipped SV file
		if (!file_exists($filename)) throw new Exception("TelemetryScrapeSVs::read_raw_sv: File not found: $filename");
		$fp = gzopen($filename, 'rb');
		if (!$fp) throw new Exception("TelemetryScrapeSVs::read_raw_sv: Cannot open gzipped file: $filename");
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
	* @return array 
	*/
	static function extract_datapoints_with_lua($sv_raw,$flavour,$topic_defs) {
		$descriptorspec = [
			0 => ["pipe", "r"],  // stdin is a pipe that the child will read from
			1 => ["pipe", "w"],  // stdout is a pipe that the child will write to
			2 => ["pipe", "w"]  // NOPE:pipe // stderr is a file to write to
		];
		$process = proc_open(self::$CFG['LUA_PATH'], $descriptorspec, $pipes, __DIR__, []);
	
		
		$lua_head=<<<ENDLUA
		   if not %ZGVS_VAR% then print('{"status":"err","err":"no_zgvs"}') return end
		   json = require "JSON"
		   print('{"status":"ok","datapoints":[')
		   count=0
ENDLUA;

		$lua_extractors = "";
		foreach($topic_defs as $name=>$def) $lua_extractors .= $def['extraction_lua'];
		
		$lua_foot = <<<ENDLUA
		    print(']}')
ENDLUA;

		$lua = $lua_head . $lua_extractors . $lua_foot;
		$lua = preg_replace_callback("/%([A-Z_]+)%/",function($s) use ($flavour) { return self::$CFG['WOW_FLAVOUR_DATA'][$flavour][$s[1]]; },$lua);
		if (self::$CFG['debug_lua']) echo $lua;
		unset(self::$CFG['debug_lua']);
	
		
		fwrite($pipes[0],$sv_raw); // ZGVSV
		fwrite($pipes[0],$lua);
	
		fclose($pipes[0]); // Lua runs

		$datapoints = stream_get_contents($pipes[1])."\n";
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$errcode = proc_close($process);
   
		if ($errcode!=0) return ['status'=>"err",'err'=>"errcode_nonzero",'errcode'=>$errcode,'error'=>$stderr];
		if ($stderr) return ['status'=>"err",'err'=>"stderr_output",'error'=>$stderr];
		$arr = @json_decode($datapoints,true);
		//if (!is_array($arr)) return ['']
		if (!$arr) { return ['status'=>"err",'err'=>"bad_json_output",'json_err'=>json_last_error_msg(),'len'=>strlen($datapoints),'partial'=>substr($datapoints,0,6000)]; }
		return $arr;
   	}

	static function init() {
		self::set_error_reporting();
		self::self_tests();
	}

	static function self_tests() {
		self::test_paths();
		self::test_datapoints();
		try {
			self::db_connect();
			self::vlog("DB connection: PASS");
		} catch (Exception $e) {
			die("DB Connection to ".self::$CFG['DB']['host']." FAILED - ".$e->getMessage());
		}
		self::vlog("Self-tests: \x1b[48;5;70;30;1mPASS\x1b[0m");
	}

	static function test_paths() {
		self::vlog("Testing paths:");
		
		if (!is_dir(self::$CFG['SV_STORAGE_ROOT'])) die("Missing SV storage root: ".self::$CFG['SV_STORAGE_ROOT']."\n");
		self::vlog(" - Will read SVs in root of: \x1b[33m".self::$CFG['SV_STORAGE_ROOT']."\x1b[0m");

		if (!is_dir(self::$CFG['SV_STORAGE_DATA_PATH'])) die("Missing SV storage folder: ".self::$CFG['SV_STORAGE_DATA_PATH']."\n");
		self::vlog(" - Specifically SV Sync config says: \x1b[33m".self::$CFG['SV_STORAGE_DATA_PATH']."\x1b[0m");

		foreach (self::$CFG['WOW_FLAVOUR_DATA'] as $flav=>$data) {
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
		$test_dataps = self::extract_datapoints_with_lua("ZygorGuidesViewerSettings={char={bar={guidestephistory={foo={lasttime=12345}}}}}","wow",self::$CFG['SCRAPE_TOPICS']);
		if (!($test_dataps['status']=="ok" && $test_dataps['datapoints'][0]['type']=="usedguide" && $test_dataps['datapoints'][0]['time']==12345)) die("FAILED testing datapoint defs:\n".print_r($test_dataps,1)."\n");
	}

	static function split_data_by_types_days($datapoints) {
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

	// test group_ranges
	static function test_group_ranges() {
		$input = [1, 2, 3, 5, 6, 8, 9, 10];
		$expected = ["1-3", "5-6", "8-10"];
		$output = self::group_ranges($input);
		if ($output !== $expected) {
			die("FAILED testing group_ranges:\n".print_r($output,1)."\n");
		}
	}

	static function call_hooks($hook,$args) {
		//self::vlog("Hook: $hook calls starting.");
		foreach (self::$CFG['SCRAPE_TOPICS'] as $dp_name=>$dp_def)
			if ($dp_def[$hook] && $dp_def['skip']!==false) { self::vlog(" - Calling $hook for $dp_name"); call_user_func_array($dp_def[$hook], $args); }
		//self::vlog("Hook: $hook calls complete.");
	}

	static function qarrayesc($query,...$args) {
		return Zygor::qarrayesc(self::$db, $query, ...$args);
	}

	static function render_all($flavour) {
		$topics = self::$CFG['SCRAPE_TOPICS'];

		self::db_connect();

		foreach($topics as $name=>$topic) {
			foreach($topic["crunchers"] as $cname=>$cruncher) {
				$table = $cruncher["table"];
				$keys = $cruncher["keys"];
				// get starting point
				$countquery = self::qesc("SELECT max(id) as max FROM {$table}");
				$countrequest = self::$db->query($countquery);
				$countresult = $countrequest->fetch_array();

				$start = $countresult["max"];
				print("Processing {$cname} starting with index {$start}\n");

				// get new events
				$getquery = self::qesc("SELECT * FROM events where type={s} and id>{d}",$cname,$start);
				$getrequest = self::$db->query($getquery);

				print("Found ".strval($getrequest->num_rows)." records\n");


				$indedx = 0;
				while ($line = $getrequest->fetch_assoc()) {
					$index++;
					print("Processing ".strval($index)."/".strval($getrequest->num_rows)."\r");

					$values = $cruncher["function"]($line);

					$insertquery = self::qarrayesc("INSERT INTO {$table} ({keys}) VALUES ({values})",$values);
					$insertrequest = self::$db->query($insertquery);
					if ($insertrequest!=1) {
						print("\n");
						print_r($insertrequest);
						exit();
					};						
				};
			};
		};
	}
}
