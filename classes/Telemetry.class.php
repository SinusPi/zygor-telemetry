<?php
if (!defined('DAY')) define('DAY', 24 * 60 * 60);
if (!defined('NOW')) define('NOW', time());

//colors
if (!defined("C_MTHD")) define("C_MTHD","\x1b[38;5;134m");
if (!defined("C_R")) define("C_R","\x1b[0m");

class Telemetry {
	static $CFG_defaults = [
		'TELEMETRY_ROOT' => "telemetry",
		'LOG_FILENAME' => "telemetry.log",
		'MAX_DAYS' => false,
		'VERBOSE_FLAGS' => [],
		'STATUS_INTERVAL' => 2,
	];
	/** @var Config */
	static $CFG;
	
	static $tag = "";

	static $TOPICS = []; // loaded from topic-*.inc.php files, see load_topics()

	/** @var TelemetryDB */
	static $db = null;

	static $DBG = [];
	
	static function config($opts=[]) {
		self::$CFG = new Config();
		self::$CFG->add(self::$CFG_defaults,0,"base default");

		$configfile = (array)(require "config.inc.php");
		self::$CFG->add($configfile,1,"config file");

		if (is_array($opts)) self::$CFG->add($opts,100,"runtime options");
	}

	static function init() {
		self::set_error_reporting();
	}

	/* Init, config, connect..
	 * This is called at the start of all telemetry scripts, and will load all topic definitions and connect to the database.
	 * After this, self::$CFG and self::$db are available for use in all scripts.
	 * This should only fail in totally unrecoverable scenarios. All scraper- or cruncher-related errors should be handled
	 * within the respective scraper/cruncher and not cause the whole script to fail, so that other scrapers/crunchers can
	 * continue working and partial data can still be collected.
	 */


	static function startup() {
		static::init(); // overridable
		static::init_scrapers();

		static::config(); // overridable
		
		self::load_topics();

		static::db_startup();
		static::self_tests(); // overridable
	}

	static function self_tests() {
	}

	static function load_topics() {
		$topics = [];
		foreach (glob("topic-*.inc.php") as $topic_file) {
			$topic_name = preg_replace("/^.*topic-(.*)\\.inc\\.php$/","$1",$topic_file);
			if (preg_match("/[^a-z0-9_]/i",$topic_name)) continue;
			$topic_data = self::safely_load_php($topic_file);
			if (!$topic_data) continue;
			if ($topic_data['crunchers_load']) $topic_data['crunchers'] = self::load_topic_crunchers($topic_name); // if a topic has many crunchers for its subtypes
			$topic_data = array_merge([
				'name' => $topic_name,
				'event' => $topic_name, // default event name is the same as topic name, can be overridden in topic-*.inc.php
			], $topic_data);
			$topics[$topic_name] = $topic_data;

		}

		if (self::$CFG['verbose']) {
			$dot = function($reset=false) { static $s=0; if ($reset) $s=-1; return $s++ ? ", ":" - "; };
			Logger::vlog("Loaded ".count($topics)." telemetry topics:\n".implode("\n",array_map(function($t) use ($dot) {
				$cc = count($t['crunchers']);
				$r = "* ";
				$r .= C_MTHD.$t['name'].C_R;
				$dot(true);
				if ($t['scraper']) $r .= $dot()."scraping: ".($t['scraper']['input'] ?: "???");
				if ($cc==0) $r .= $dot()."not crunched";
				elseif ($cc==1) $r .= $dot()."crunched";
				else $r .= $dot()."$cc crunchers: ".($c=count($t['crunchers']))." (".implode(", ",array_column($t['crunchers'],'name')).")";
				return $r;
			}, $topics)));
		}

		self::$TOPICS = $topics;

		return $topics;
	}

	static function load_topic_crunchers($topic_name) {
		$crunchers = [];
		foreach (glob("topic-{$topic_name}-*.inc.php") as $crunch_file) {
			$crunch = self::safely_load_php($crunch_file);
			if (!is_array($crunch)) continue; // allow empty files
			if (!$crunch['name']) $crunch['name'] = preg_replace("/.*topic-{$topic_name}-([^.]+)\.inc\.php$/","$1",$crunch_file);
			$crunch = array_merge([
				'name' => $crunch['name'] ?: "unknown",
				'input' => "event",
				'eventtype' => $topic_name,
			], $crunch);
			$crunchers[] = $crunch;
		}
		return $crunchers;
	}

	static function safely_load_php($filename) {
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
		TelemetryStatus::status(self::$tag,['status'=>"ERROR",'error'=>['errno'=>$errno,'errstr'=>$errstr,'errfile'=>$errfile,'errline'=>$errline],'times'=>$times]);
		die("$errno $errfile:$errline $errstr\n");
	}

	static function on_shutdown() {
		$err=error_get_last();
		if ($err)
			self::write_error_to_status($err['type'],$err['message'],$err['file'],$err['line']);
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

	static function get_counts($flavour,$topic) {
		$def = self::$CFG['TOPICS'][$topic] ?: null;
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
	static function parse_date($datestr) {
		// try to parse as YYYYMMDD or YYYY-MM-DD
		if (preg_match("/^(\d{4})(-?)(\d{2})(-?)(\d{2})$/", $datestr, $m)) {
			return strtotime("{$m[1]}-{$m[3]}-{$m[5]}");
		}
		// try to parse as timestamp
		if (is_numeric($datestr)) {
			return intval($datestr);
		}
		throw new Exception("Invalid date format: $datestr");
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
				if (count($merges_last)>2) Logger::log("...");
				foreach ($merges_last as $mergelast) Logger::log($mergelast);
				$merges_last=[];
				return;
			}
		} else {
			echo "old tag\n";
			$merges_last[$merge_tag]=$s;
			return;
		}
		echo "plain\n";
		if ($s) Logger::log($s);
		$i++; if ($i>100) die();
	}

	// utility functions

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
		$filename_userbnet = preg_replace("/--SavedVariables--ZygorGuidesViewer.*[\\.luagz]+/","",$filename_userfile); // remains: user/bnet
		return [$filename_userfile, $filename_userbnet];
	}

	static function flavnum($flavour) {
		$fd = self::$CFG['WOW_FLAVOUR_DATA'];
		$flavnum = $fd[$flavour]['num'] ?: 0;
		if ($flavnum===0) throw new ErrorException("Unknown flavour '$flavour', known are: ".implode(", ", array_keys($fd)).".");
		return $flavnum;
	}


	static function filter_gen($iterable, $callback) {
		foreach ($iterable as $key => $value) {
			if ($callback($value, $key)) {
				yield $key => $value;
			}
		}
	}


	/**
	 * Initialize TelemetryDB and connect to the database.
	 * After this, self::$db is available for all database operations.
	 */
	static function db_startup() {
		if (isset(self::$db)) return self::$db; // already connected
		$cfg = self::$CFG['DB']?:null;
		if (!$cfg) throw new ErrorException("No DB configuration found in Telemetry config.");

		try {
			self::$db = new TelemetryDB();
			self::$db->connect($cfg);

			static::db_create_tables();
		} catch (Exception $e) {
			throw new ErrorException("Failed to connect to database '".$cfg['db']."' on '".$cfg['host']."': ".$e->getMessage()."\n");
		}
		return self::$db;
	}


	// =======================================================================
	// Schema creation methods
	// =======================================================================

	static function db_create_tables() {
		self::db_create_status_table();
		self::db_create_files_table();
		self::db_create_events_table();
	}

	static function db_create_status_table() {
		self::$db->query("SHOW CREATE TABLE status;");
		if (self::$db->error()) {
			$schema_sql = "CREATE TABLE `status` (
					`tag` char(20) NOT NULL,
					`status` varchar(200) DEFAULT NULL,
					`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					UNIQUE KEY `tag` (`tag`)
				)
				ENGINE=InnoDB
				DEFAULT CHARSET=latin1
				COLLATE=latin1_swedish_ci
				COMMENT='current status of telemetry processing jobs';
			";
			self::$db->query($schema_sql);
			if (self::$db->error())
				throw new Exception("Failed to create table `status`: " . self::$db->error());
			return true;
		}
		return false;
	}

	static function db_create_files_table() {
		self::$db->query("SHOW CREATE TABLE files;");
		if (self::$db->error()) {
			$schema_sql = "CREATE TABLE `files` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`slugname` varchar(255) NOT NULL, -- may not be an exact filename, may even be virtual, just unique
					`filetype` char(2) NOT NULL, -- 'sv','pl'; will govern slug-to-fullpath logic, etc.
					UNIQUE KEY `id` (`id`),
					UNIQUE KEY `slugname` (`slugname`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
				COMMENT='list of all parsed files, for bookkeeping and reference from events';
			";
			self::$db->query($schema_sql);
			if (self::$db->error())
				throw new Exception("Failed to create table `files`: " . self::$db->error());
			return true;
		}
		return false;
	}

	static function db_create_events_table() {
		self::$db->query("SHOW CREATE TABLE events;");
		if (self::$db->error()) {
			$schema_sql = "CREATE TABLE `events` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`flavnum` int(1) NOT NULL,
					`file_id` int(11),
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
			if (self::$db->error())
				throw new Exception("Failed to create table `events`: " . self::$db->error());
			return true;
		}
		return false;
	}



	static function call_hooks($hook,$args) {
		//self::vlog("Hook: $hook calls starting.");
		foreach (self::$CFG['TOPICS'] as $dp_name=>$dp_def)
			if ($dp_def[$hook] && $dp_def['skip']!==false) { Logger::vlog(" - Calling $hook for $dp_name"); call_user_func_array($dp_def[$hook], $args); }
		//self::vlog("Hook: $hook calls complete.");
	}

	static function dump_config() {
		$cfg = self::$CFG;
		if (!$cfg) return; // No config loaded
		Logger::log(get_called_class()." config:");
		if (isset($cfg['DB']['pass'])) $cfg['DB']['pass']="****"; // hide password
		Logger::log(join("\n",array_map(function($k,$v) { /* key=value */
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

	/**
	 * Load all dependent class files from the classes directory.
	 */
	static function init_scrapers() {
		TelemetryScrape::init_scrapers();
	}

}



/// these stay here for now

class FileLockedException extends Exception {
	// Custom exception for file locking issues
}

class MinorError extends Exception {
	// Custom exception for error messages
}

class File {
	public $id;
	public $fullpath;
	public $slug;
	public $topics;
	public $newest_scrape_time;
	public $any_fresh;

	public $mtime; // not stored
	
	public function __construct($id, $fileslug) {
		$this->id = $id;
		$this->slug = $fileslug;
	}

}

class TelemetryStatus {
	static $last_statuses = [];
	static $last_tag = "";

	static function &get_status($tag,$force=false) {
		if ($force || !isset(self::$last_statuses[$tag]) && $last = Telemetry::$db->get_status($tag))
			self::$last_statuses[$tag] = $last;
		if (!isset(self::$last_statuses[$tag]))
			self::$last_statuses[$tag] = [];
		return self::$last_statuses[$tag];
	}
	static function status($tag,$data,$keep=false) {
		if (!Telemetry::$db) return; // DB not connected, no status
		$last_status = $keep ? self::get_status($tag) : [];
		$last_status = array_replace_recursive($last_status,$data);

		self::$last_tag = $tag;
		self::$last_statuses[$tag] = $last_status;

		Telemetry::$db->set_status($tag, $last_status);
	}
	static function test_status() {
		$testtag="TEST";
		self::status($testtag,['status'=>"TESTING1", 'foo'=>"bar"]);
		$status = self::get_status($testtag);
		if ($status['status']!="TESTING1" || $status['foo']!="bar") throw new Exception("Status test failed 1: ".print_r($status,true));

		self::status($testtag,['status'=>"TESTING2"], true);
		$status = self::get_status($testtag);
		if ($status['status']!="TESTING2" || $status['foo']!="bar") throw new Exception("Status test failed 2: ".print_r($status,true));

		Telemetry::$db->delete_status($testtag);
	}

	static function stat($data,$keep=false) {
		return self::status(self::$last_tag,$data,$keep);
	}

	static function update_progress($tag,$n,$total,$extra=[],$force=false) {
		static $time_last_status=0;
		static $n_last=0;
		static $speedbuffer=20;
		static $speeds=[]; if (count($speeds)==0) $speeds=array_fill(0,$speedbuffer,0);

		if (!self::get_status($tag)['time_started']) self::status($tag,['time_started'=>time()],true);

		if ((time()-$time_last_status >= Telemetry::$CFG['STATUS_INTERVAL']) || $force) {
			$mitime = microtime(true);
			$time_elapsed = $mitime-TelemetryStatus::get_status($tag)['time_started'];
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

}
