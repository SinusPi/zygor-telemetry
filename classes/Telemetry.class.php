<?php
if (!defined('DAY')) define('DAY', 24 * 60 * 60);
if (!defined('NOW')) define('NOW', time());

//colors
if (!defined("C_MTHD")) define("C_MTHD","\x1b[38;5;134m");
if (!defined("C_R")) define("C_R","\x1b[0m");

class Telemetry {
	static $CFG = [
		'TELEMETRY_ROOT' => "telemetry",
		'LOG_FILENAME' => "telemetry.log",
		'MAX_DAYS' => false,
		'VERBOSE_FLAGS' => [],
		'STATUS_INTERVAL' => 2,
	];
	static $tag = "";
	static $last_statuses = [];

	static $db = null;

	static $DBG = [];

	static function config($cfg=[]) {
		$configfile = (array)(require "config.inc.php");
		self::$CFG = self::merge_configs(self::$CFG, $configfile, $cfg);

		// check if function _sub_config exists in child class
		if (method_exists(get_called_class(), '_sub_config')) {
			call_user_func([get_called_class(), '_sub_config']);			
		}

		if (!self::$CFG['DB']) {
			throw new Exception("No DB configuration found in Telemetry config.");
		}
		if (self::$CFG['verbose']) {
			self::dump_config();
		}
	}

	static function init() {
		self::set_error_reporting();
		try {
			self::$CFG['SCRAPE_TOPICS'] = self::load_topics();
		} catch (Exception $e) {
			die("Failed to load topics: ".$e->getMessage()."\n");
		}
		try {
			self::db_connect();
		} catch (Exception $e) {
			die("Failed to connect to database '".self::$CFG['DB']['db']."' on '".self::$CFG['DB']['host']."': ".$e->getMessage()."\n");
		}
	}

	static function load_topics() {
		$topics = [];
		foreach (glob("topic-*.inc.php") as $topic_file) {
			$topic_name = preg_replace("/^.*topic-(.*)\\.inc\\.php$/","$1",$topic_file);
			if (preg_match("/[^a-z0-9_]/i",$topic_name)) continue;
			$topic_data = self::get_file($topic_file);
			if (!$topic_data) continue;
			if ($topic_data['crunchers_load']) $topic_data['crunchers'] = self::load_topic_crunchers($topic_name); // if a topic has many crunchers for its subtypes
			$topics[$topic_name] = $topic_data;
		}

		if (!count($topics)) {
			throw new Exception("No telemetry topics found in topic-*.inc.php files!");
		}

		if (self::$CFG['verbose']) {
			$keys = array_keys($topics);
			foreach ($keys as $i=>$k) {
				$crunchers = $topics[$k]['crunchers'] ?: [];
				if ($crunchers) { $c=count($crunchers); $keys[$i] .= " (+ $c cruncher".($c==1 ? "" : "s").": ".implode(", ",array_column($crunchers,'name')).")"; }
			}
			self::vlog("Loaded ".count($topics)." telemetry topics: ".implode(", ", $keys).".");
		}

		return $topics;
	}

	static function load_topic_crunchers($topic_name) {
		$crunchers = [];
		foreach (glob("topic-{$topic_name}-*.inc.php") as $crunch_file) {
			$crunch = self::get_file($crunch_file);
			if (!is_array($crunch)) continue; // allow empty files
			if (!$crunch['name']) $crunch['name'] = preg_replace("/.*topic-{$topic_name}-([^.]+)\.inc\.php$/","$1",$crunch_file);
			$crunchers[] = $crunch;
		}
		return $crunchers;
	}

	static function get_file($filename) {
		try {
			$parse = token_get_all(file_get_contents($filename));
			$file = include $filename;
			if (!is_array($file)) return null;
			return $file;
		} catch (Exception $e) {
			throw new Exception("Failed to parse file $filename: ".$e->getMessage()."\n");
		}
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
		self::status(self::$tag,['status'=>"ERROR",'error'=>['errno'=>$errno,'errstr'=>$errstr,'errfile'=>$errfile,'errline'=>$errline],'times'=>$times]);
		die("$errno $errfile:$errline $errstr\n");
	}

	static function on_shutdown() {
		$err=error_get_last();
		if ($err)
			self::write_error_to_status($err['type'],$err['message'],$err['file'],$err['line']);
	}

	
	static function &get_status($tag,$force=false) {
		if ($force || !isset(self::$last_statuses[$tag])) {
			$r = self::db_qesc("SELECT status FROM status WHERE tag={s} LIMIT 1", $tag);
			if ($r) {
				$status = $r->fetch_row()[0];
				self::$last_statuses[$tag] = json_decode($status, true);
			}
		}
		if (!isset(self::$last_statuses[$tag]))
			self::$last_statuses[$tag] = [];
		return self::$last_statuses[$tag];
	}
	static function status($tag,$data,$keep=false) {
		if (!self::$db) return; // DB not connected, no status
		$last_status = $keep ? self::get_status($tag) : [];
		$last_status = array_replace_recursive($last_status,$data);

		self::$last_statuses[$tag] = $last_status;

		self::db_qesc("INSERT INTO status (tag,status) VALUES ({s},{s}) ON DUPLICATE KEY UPDATE status={s}", $tag, json_encode($last_status), json_encode($last_status));
	}
	static function test_status() {
		$testtag="TEST";
		self::status($testtag,['status'=>"TESTING1", 'foo'=>"bar"]);
		$status = self::get_status($testtag);
		if ($status['status']!="TESTING1" || $status['foo']!="bar") throw new Exception("Status test failed 1: ".print_r($status,true));

		self::status($testtag,['status'=>"TESTING2"], true);
		$status = self::get_status($testtag);
		if ($status['status']!="TESTING2" || $status['foo']!="bar") throw new Exception("Status test failed 2: ".print_r($status,true));

		self::db_qesc("DELETE FROM status WHERE tag={s}", $testtag);
	}
	
	static function stat($data,$keep=false) {
		return self::status(self::$tag,$data,$keep);
	}

	/**
	 * @deprecated
	 * @return array [data_total,files,data_match]
	 */
	static function __read_days($folder,$from=0,$to=9999999999) {
		$files = explode("\n",shell_exec("find $folder -name '*.json'"));
		$files = str_replace("$folder/","",$files);
	
		$result['data_total']=count($files);
		$result['files'] = array_values(array_filter($files,function($fn) use ($from,$to) { return $fn>=$from && $fn<=$to; })); // assumes JSON filenames are timestamps
		$result['data_match']=count($result['files']);

		return $result;
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
	static function __log_merged($s=null,$merge_tag=null) {
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
		$tag = $tag ?: (self::$tag ?: "TELEMETRY");
		if (self::$CFG['LOG_FILENAME']) {
			// log to file
			file_put_contents(
				self::$CFG['TELEMETRY_ROOT']."/".self::$CFG['LOG_FILENAME'],
				date("Y-m-d H:i:s").".".sprintf("%03d",explode(" ", microtime())[0]*1000)." [$tag] ".$s."\n",
				FILE_APPEND|LOCK_EX
			);
		}
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

	static function flavnum($flavour) {
		$flavnum = self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num'] ?: 0;
		if ($flavnum===0) throw new Exception("Unknown flavour '$flavour', known are: ".implode(", ", array_keys(self::$CFG['WOW_FLAVOUR_DATA'])).".");
		return $flavnum;
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

	static function qesc($query,...$args) {
		return Zygor::qesc(self::$db, $query, ...$args);
	}

	static function qarrayesc($query,...$args) {
		return Zygor::qarrayesc(self::$db, $query, ...$args);
	}

	static function db_qesc($query,...$args) {
		$query = self::qesc($query, ...$args);
		return self::$db->query($query);
	}

	static function db_query_one($query) {
		$r = self::$db->query($query);
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		return $r->fetch_row()[0];
	}

	static function db_create() {
		self::$db->query("SHOW CREATE TABLE status;");
		if (self::$db->error) {
			$schema_sql = "
				CREATE TABLE `status` (
					`tag` char(20) NOT NULL,
					`status` varchar(200) DEFAULT NULL,
					UNIQUE KEY `tag` (`tag`)
				)
				ENGINE=InnoDB
				DEFAULT CHARSET=latin1
				COLLATE=latin1_swedish_ci
				COMMENT='current status of telemetry processing jobs';
			";
			self::$db->query($schema_sql);
			if (self::$db->error) 
				throw new Exception("Failed to create table `status`: ".self::$db->error);
		}
		self::$db->query("SHOW CREATE TABLE events;");
		if (self::$db->error) {
			$schema_sql = "
				CREATE TABLE `events` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`flavnum` int(1) NOT NULL,
					`file_id` int(11) NOT NULL,
					`time` int(10) NOT NULL,
					`type` char(40) NOT NULL,
					`data` text NOT NULL,
					UNIQUE KEY `id` (`id`,`flavnum`) USING BTREE,
					KEY `type` (`type`) USING BTREE,
					KEY `time` (`time`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
				PARTITION BY RANGE (`flavnum`) (
					PARTITION `p_wtf` VALUES LESS THAN (1) ENGINE = InnoDB,
					PARTITION `p_wow` VALUES LESS THAN (2) ENGINE = InnoDB,
					PARTITION `p_wowclassic` VALUES LESS THAN (3) ENGINE = InnoDB,
					PARTITION `p_wowclassictbc` VALUES LESS THAN (4) ENGINE = InnoDB,
					PARTITION `p_wowclassictbcanniv` VALUES LESS THAN (5) ENGINE = InnoDB
				)
			"; // will need manual adjustment for more flavours :(
			self::$db->query($schema_sql);
			if (self::$db->error) 
				throw new Exception("Failed to create table `events`: ".self::$db->error);
		}
	}

	/**
	 * Save scraped data for a specific day and user/account.
	 * @deprecated
	 */
	static function __store_day_scrape($flavour,$day,$user,$acct, $daydata,$mtime, &$totals) {
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

	static function db_get_svfile_mtimes($flavourfiles) {
		if (!count($flavourfiles)) return [];
		$q = self::qesc("SELECT file,mtime FROM sv_files WHERE file IN ({sa})", $flavourfiles);
		$r = self::$db->query($q);
		if (!$r) throw new Exception("DB error: ".self::$db->error);
		$res = [];
		while ($row = $r->fetch_assoc()) $res[$row['file']] = $row['mtime'];
		return $res;
	}

	static function call_hooks($hook,$args) {
		//self::vlog("Hook: $hook calls starting.");
		foreach (self::$CFG['SCRAPE_TOPICS'] as $dp_name=>$dp_def)
			if ($dp_def[$hook] && $dp_def['skip']!==false) { self::vlog(" - Calling $hook for $dp_name"); call_user_func_array($dp_def[$hook], $args); }
		//self::vlog("Hook: $hook calls complete.");
	}

	static function update_progress($tag,$n,$total,$extra=[],$force=false) {
		static $time_last_status=0;
		static $n_last=0;
		static $speedbuffer=20;
		static $speeds=[]; if (count($speeds)==0) $speeds=array_fill(0,$speedbuffer,0);

		if (!self::get_status($tag)['time_started']) self::status($tag,['time_started'=>time()],true);

		if ((time()-$time_last_status >= self::$CFG['STATUS_INTERVAL']) || $force) {
			$mitime = microtime(true);
			$time_elapsed = $mitime-self::get_status($tag)['time_started'];
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
			self::status($tag,$progress,true);
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

	static function dump_config() {
		$cfg = self::$CFG;
		if (!$cfg) return; // No config loaded
		self::log(get_called_class()." config:");
		if (isset($cfg['DB']['pass'])) $cfg['DB']['pass']="****"; // hide password
		self::log(join("\n",array_map(function($k,$v) { /* key=value */
			if (is_array($v)) $v="[".join(",",$v)."]";
			elseif ($v===TRUE) $v="Y";
			elseif ($v===FALSE) $v="N";
			return "\x1b[32m{$k}\x1b[0m=\x1b[33m{$v}\x1b[0m";
		},
		array_keys($cfg),
		array_map(function($s) { /* colorize <placeholders> */
			return is_string($s) ? preg_replace("/(<.*?>)/","\x1b[35m$1\x1b[33m",$s) : $s;
		}, $cfg))));
	}

	static function db_lock($lock) {
		$lock = "'scrape/".self::$db->real_escape_string($lock)."'";
		//self::vlog(microtime(true)." DB lock '$lock' attempting...");
		$result = self::db_query_one("SELECT GET_LOCK($lock, 1);");
		//self::vlog(microtime(true)." DB lock '$lock' result: ".$result);
		return $result;
	}

	static function db_unlock($lock) {
		$lock = "'scrape/".self::$db->real_escape_string($lock)."'";
		//self::vlog(microtime(true)." DB lock '$lock' releasing...");
		$result = self::db_query_one("SELECT RELEASE_LOCK($lock);");
		//self::vlog(microtime(true)." DB lock '$lock' released: ".$result);
		return $result;
	}

	static function merge_configs($base,...$overrides) {
		foreach ($overrides as $override) {
			foreach ($override as $k=>$v) {
				if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
					$base[$k] = self::merge_configs($base[$k], $v);
				} else {
					$base[$k] = $v;
				}
			}
		}
		return $base;
	}
}

class FileLockedException extends Exception {
	// Custom exception for file locking issues
}

require_once __DIR__."/TelemetryScrapeSVs.class.php";
require_once __DIR__."/TelemetryCrunch.class.php";
