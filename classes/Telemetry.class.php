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
		'BATCH_SIZE' => 20,
	];
	/** @var Config */
	static $CFG;
	
	static $tag = "";

	static $json = false;

	/** @var Topic[] */
	static $TOPICS = []; // loaded from topic-*.inc.php files, see load_topics()

	/** @var TelemetryDB */
	static $db = null;

	static $config_errors = [];

	static $DBG = [];

	private static $is_ready = false;

	static function config($opts=[]) {
		self::$CFG = new Config();
		self::$CFG->add(self::$CFG_defaults,0,"base default");

		$configfile = (array)(require "config.inc.php");
		self::$CFG->add($configfile,1,"config file");

		if ($opts) self::$CFG->add($opts,100,"runtime options");
	}

	static function init() {
		self::set_error_reporting();
		self::$json = (php_sapi_name() !== 'cli');
	}

	/* Init, config, connect..
	 * This is called at the start of all telemetry scripts, and will load all topic definitions and connect to the database.
	 * After this, self::$CFG and self::$db are available for use in all scripts.
	 * This should only fail in totally unrecoverable scenarios. All scraper- or cruncher-related errors should be handled
	 * within the respective scraper/cruncher and not cause the whole script to fail, so that other scrapers/crunchers can
	 * continue working and partial data can still be collected.
	 * This does NOT initialize scrapers or crunchers.
	 */
	static function startup($opts=[]) {
		self::init();
		self::config($opts);

		Logger::init([
			'log_path'=>self::$CFG['TELEMETRY_ROOT']."/".self::$CFG['LOG_FILENAME'],
			'verbose'=>self::$CFG['verbose'],
			'verbose_flags'=>self::$CFG['verbose_flags']]
		);

		self::load_topics();
		self::dump_topics();

		self::db_startup();

		self::self_tests();

		self::$is_ready=true;
	}

	static function self_tests() {
		TelemetryScrape::self_tests();
	}

	static function is_ready() {
		return self::$is_ready;
	}

	/**
	 * Get a topic by name
	 * @param string $topicName Topic name
	 * @return Topic|null Topic object or null if not found
	 */
	static function getTopic($topicName) {
		return isset(self::$TOPICS[$topicName]) ? self::$TOPICS[$topicName] : null;
	}

	/**
	 * Check if a topic exists
	 * @param string $topicName Topic name
	 * @return bool True if topic exists
	 */
	static function hasTopic($topicName) {
		return isset(self::$TOPICS[$topicName]);
	}

	static function load_topics() {
		foreach (glob("topic-*.inc.php") as $topic_file) {
			$topic_name = preg_replace("/^.*topic-(.*)\\.inc\\.php$/","$1",$topic_file);
			if (preg_match("/[^a-z0-9_]/i",$topic_name)) continue;
			$topic_data = FileTools::safely_load_php($topic_file);
			if (!$topic_data) continue;
			if ($topic_data['crunchers_load']) $topic_data['crunchers'] = self::load_topic_crunchers($topic_name); // if a topic has many crunchers for its subtypes
			
			self::$TOPICS[$topic_name] = new Topic($topic_name, $topic_data);
		}
		return self::$TOPICS;
	}

	static function dump_topics() {
		if (!self::$CFG['verbose']) return;
		
		$dot = function($reset=false) { static $s=0; if ($reset) $s=-1; return $s++ ? ", ":" - "; };
		Logger::vlog("Loaded ".count(self::$TOPICS)." telemetry topics:\n".implode("\n",array_map(function($t) use ($dot) {
			/** @var Topic $t */
			$cc = count($t->crunchers);
			$r = "* ";
			$r .= C_MTHD.$t->name.C_R;
			$dot(true);
			if ($t->scraper) $r .= $dot()."scraping: ".(isset($t->scraper['input']) ? $t->scraper['input'] : "???");
			if ($cc==0) $r .= $dot()."not crunched";
			elseif ($cc==1) $r .= $dot()."crunched";
			else {
				$cruncher_names = array_map(function($c) { /** @var Cruncher $c */ return $c->name; }, $t->crunchers);
				$r .= $dot()."$cc crunchers: ".($c=count($t->crunchers))." (".implode(", ", $cruncher_names).")";
			}
			return $r;
		}, self::$TOPICS)));
	}

	static function load_topic_crunchers($topic_name) {
		$crunchers = [];
		foreach (glob("topic-{$topic_name}-*.inc.php") as $crunch_file) {
			$crunch = FileTools::safely_load_php($crunch_file);
			if (!is_array($crunch)) continue; // allow empty files
			if (!$crunch['name']) $crunch['name'] = preg_replace("/.*topic-{$topic_name}-([^.]+)\.inc\.php$/","$1",$crunch_file);
			$crunch = array_merge([
				'name' => $crunch['name'],
				'input' => "event",
				'eventtype' => $crunch['eventtype'],
			], $crunch);
			$crunchers[] = $crunch;
		}
		return $crunchers;
	}

	// error handling

	static function set_error_reporting() {
		error_reporting(E_ALL^E_WARNING^E_NOTICE);
		set_error_handler([__CLASS__,'error_handler']);
		set_exception_handler([__CLASS__,'exception_handler']);
		register_shutdown_function([__CLASS__,'on_shutdown']);
	}

	static function exception_handler($e) {
		$error_data = ['success'=>false,'status'=>"EXCEPTION",'type'=>get_class($e),'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine(),'trace'=>$e->getTraceAsString()];
		self::graceful_die($error_data);
	}

	static function error_handler($errno, $errstr, $errfile, $errline) {
		global $times;
		if (!($errno & error_reporting())) {
			//echo "$errno $errline:$errstr\n";
			return false;
		}
		$error_data = ['success'=>false,'status'=>"ERROR",'errno'=>$errno,'errstr'=>$errstr,'errfile'=>$errfile,'errline'=>$errline,'times'=>$times];
		self::graceful_die($error_data);
	}

	static function graceful_die($data) {
		TelemetryStatus::status(self::$tag,$data);
		Logger::log("Terminating with status: ".json_encode($data));
		self::die_json($data);
	}

	static function die_json($data) {
		if (isset($data['httpcode'])) http_response_code($data['httpcode']);
		if (self::$json) {
			header('Content-Type: application/json');
			die(json_encode($data));
		} else {
			die(print_r($data,true));
		}
	}

	static function on_shutdown() {
		$err=error_get_last();
		if ($err)
			self::error_handler($err['type'],$err['message'],$err['file'],$err['line']);
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
		$def = isset(self::$TOPICS[$topic]) ? self::$TOPICS[$topic] : null;
		if (!$def) return array('total'=>0,'matching'=>0);
		$output_mode = $def->get('output_mode');
		if ($output_mode=="day") {
			$file_glob = self::cfgstr('DATA_PATH_DPMODE_DAY',array('FLAVOUR'=>$flavour,'TOPIC'=>$topic,'DAY'=>"*"));
			$files = glob($file_glob);
			foreach ($files as $file) {
				readfile($file);
				die();
			}
		}
		elseif ($output_mode=="day_user") {
			$file_glob = self::cfgstr('DATA_PATH_DPMODE_DAY_USER',array('FLAVOUR'=>$flavour,'TOPIC'=>$topic,'DAY'=>"*",'USER'=>"*.json"));
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
		return self::repstr(self::$CFG[$str], $data + self::$CFG->get());
	}

	/**
	 * Split a full path into "user/bnet--SavedVariables..." and "user/bnet" slug.
	 */
	static function split_filename($fullpath) {
		$filename_userfile = basename(dirname($fullpath))."/".basename($fullpath); // remains: user/bnet--SavedVariables--Zygor.....lua.gz
		$filename_userbnet = preg_replace("/--SavedVariables--ZygorGuidesViewer.*[\\.luagz]+/","",$filename_userfile); // remains: user/bnet
		return [$filename_userfile, $filename_userbnet];
	}

	private static function flavnum_one($flavour) {
		$fd = self::$CFG['WOW_FLAVOUR_DATA'];
		$flavnum = $fd[$flavour]['num'] ?: 0;
		if ($flavnum===0) throw new ErrorException("Unknown flavour '$flavour', known are: ".implode(", ", array_keys($fd)).".");
		return $flavnum;
	}
	static function flavnum($flavour) {
		if (is_string($flavour)) return self::flavnum_one($flavour);
		elseif (is_array($flavour)) return array_map([__CLASS__,'flavnum_one'], $flavour);
		else throw new ErrorException("Invalid flavour format: ".print_r($flavour,true));
	}

	static function is_linux() {
		return stripos(php_uname(), 'linux') !== false;
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
		} catch (Exception $e) {
			throw new ErrorException("Failed to connect to database '".$cfg['db']."' on '".$cfg['host']."': ".$e->getMessage()."\n");
		}
		try {
			self::db_create_tables();
		} catch (Exception $e) {
			throw new ErrorException("Failed to create or migrate database tables: ".$e->getMessage()."\n");
			self::$db->disconnect();
			self::$db = null;
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
		self::db_create_vars_table();
	}

	static function db_create_status_table() {
		$result = (new SchemaManager(self::$db->conn))->manageTable("status", [
			'1' => "CREATE TABLE `status` (
					`tag` char(20) NOT NULL,
					`status` varchar(200) DEFAULT NULL,
					`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					UNIQUE KEY `tag` (`tag`)
				)
				ENGINE=InnoDB
				DEFAULT CHARSET=latin1
				COLLATE=latin1_swedish_ci
				COMMENT='current status of telemetry processing jobs';
			"]);
		if ($result && $result['status'] === 'migrated') Logger::vlog("DB: 'status' table created or migrated to version ".$result['target_version']);
	}

	static function db_create_files_table() {
		$result = (new SchemaManager(self::$db->conn))->manageTable("files", [
			'1' => "CREATE TABLE `files` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`slugname` varchar(255) NOT NULL, -- may not be an exact filename, may even be virtual, just unique
					`filetype` char(2) NOT NULL, -- 'sv','pl'; will govern slug-to-fullpath logic, etc.
					UNIQUE KEY `id` (`id`),
					UNIQUE KEY `slugname` (`slugname`)
				) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci
				COMMENT='list of all parsed files, for bookkeeping and reference from events';
			",
			'1>2' => "ALTER TABLE `files` ADD COLUMN `flavnum` int(1) NULL AFTER `filetype`",
			]);
		if ($result && $result['status'] === 'migrated') Logger::vlog("DB: 'files' table created or migrated to version ".$result['target_version']);
	}

	static function db_create_events_table() {
		$result = (new SchemaManager(self::$db->conn))->manageTable("events", [
			// v1 reset point: initial events table schema
			"1" => "CREATE TABLE `events` (
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
			)",
			
			// v1>2: Add subtype column after type
			"1>2" => "ALTER TABLE `events` ADD COLUMN `subtype` char(40) DEFAULT NULL AFTER `type`",
			
			// v2 reset point: complete redesigned schema with subtype column
			"2" => "CREATE TABLE `events` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`flavnum` int(1) NOT NULL,
				`file_id` int(11),
				`time` int(10) NOT NULL,
				`type` char(40) NOT NULL,
				`subtype` char(40) DEFAULT NULL,
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
			)",
		]);
		if ($result && $result['status'] === 'migrated') Logger::vlog("DB: 'events' table created or migrated to version ".$result['target_version']);
	}

	static function db_create_vars_table() {
		$result = (new SchemaManager(self::$db->conn))->manageTable("vars", [
			// v1 reset point: initial vars table schema
			"1" => "CREATE TABLE `vars` (
				`var` char(40) NOT NULL,
				`value` varchar(2048) NOT NULL,
				UNIQUE KEY `var` (`var`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_swedish_ci"
		]);
		if ($result && $result['status'] === 'migrated') Logger::vlog("DB: 'vars' table created or migrated to version ".$result['target_version']);
	}

	static function db_get_var($var) {
		$q = self::$db->query("SELECT `value` FROM `vars` WHERE `var` = {s}", $var);
		$row = $q->fetch_array();
		return $row ? $row[0] : null;
	}

	static function db_set_var($var, $value) {
		return self::$db->query("INSERT INTO `vars` (`var`, `value`) VALUES ({s}, {s}) ON DUPLICATE KEY UPDATE `value` = {s}", $var, $value, $value);
	}

	static function call_hooks($hook,$args) {
		//self::vlog("Hook: $hook calls starting.");
		foreach (self::$TOPICS as $dp_name=>$topic) {
			/** @var Topic $topic */
			$hook_func = $topic->get($hook);
			if ($hook_func && !$topic->skip) { 
				Logger::vlog(" - Calling $hook for $dp_name"); 
				call_user_func_array($hook_func, $args); 
			}
		}
		//self::vlog("Hook: $hook calls complete.");
	}

	static function dump_config() {
		$cfg = self::$CFG->get();
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

	static function startup_classes() {
		TelemetryScrape::startup();
	}

	/**
	 * Helper to group consecutive date values into ranges, e.g. [20240101, 20240102, 20240103, 20240105] => ["20240101-20240103", "20240105"]
	 */
	static function group_date_ranges($arr) {
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

	static function dt($time) {
		if (!$time) return "N/A";
		return date("Y-m-d H:i:s", $time);
	}

	static function performMisc($task,$OPTS) {
		switch ($task) {
			case "dedupe-events":
				return self::doDedupeEvents($OPTS);
			default:
				throw new Exception("Unknown misc task: $task; available: dedupe-events");
		}
	}

	static function doDedupeEvents($OPTS) {
		/* Events can get duplicated if a scraper/cruncher is run multiple times on the same file, e.g. due to crashes or manual re-runs.
		 * Search range should be limited (by file_id) as there could be thousands of events and comparing all of them would consume a lot of memory.
		 */
		if (!$OPTS['from'] || !$OPTS['to']) {
			echo "Calculating file IDs for error response... :P\r";
			$q = self::$db->query("SELECT MAX(`file_id`) FROM `events`");
			if (!$q) throw new Exception("Database query failed: ".self::$db->error());
			$max_file_id = $q->fetch_array()[0];
			throw new Exception("dedupe-events needs a file id range, --from=1 --to=$max_file_id.");
		}
		$flavnum = self::flavnum($OPTS['flavour']);
		self::$db->query_mode = MYSQLI_USE_RESULT;
		$q = self::$db->query("SELECT `id`,`file_id`, `time`, `type`,`subtype`,`data` 
		  FROM `events`
		  WHERE
		    `flavnum` IN ({da})
		    AND `type` IN ({sa})
		    AND `file_id` BETWEEN {d} AND {d}
		  ORDER BY `file_id` ASC, `time` ASC",
		  $flavnum,
		  $OPTS['topics'],
		  intval($OPTS['from']), intval($OPTS['to']));
		if (!$q) throw new Exception("Database query failed: ".self::$db->error());

		$seen = [];
		$dupes = [];
		$last_id = null;
		$progress = 0;
		while ($row = $q->fetch_assoc()) {
			// files are ordered; new file_id = reset hashes
			if ($last_id != $row['file_id']) {
				$last_id = $row['file_id'];
				$seen = [];
			}
			$data_str = $row['time'] . "|" . $row['type'] . "|" . $row['subtype'] . "|" . $row['data'];
			$hash = md5($data_str);
			if (isset($seen[$hash])) {
				$dupes[] = $row['id'];
				$seen[$hash]++;
				if ($seen[$hash]==2)
					Logger::vlog("Dupe: id={$row['id']} file_id={$row['file_id']} time={$row['time']} type={$row['type']} subtype={$row['subtype']} data=".substr($row['data'],0,100));
			} else {
				$seen[$hash]=1;
			}
			$progress++;
			if ($progress % 1000 == 0) {
				echo "Checked $progress events, found ".count($dupes)." duplicates so far...\n";
			}
		}
		$q->free();

		if (count($dupes) > 0) {
			if (!$OPTS['sure']) {
				echo "Found ".count($dupes)." duplicate events. Run with --sure to actually delete them.\n";
				return;
			} else {
				// batches of 100
				$batch_size = 100;
				$deleted = 0;
				echo "Deleting ".count($dupes)." duplicate events in batches of $batch_size...\n";
				for ($i = 0; $i < count($dupes); $i += $batch_size) {
					$batch = array_slice($dupes, $i, $batch_size);
					self::$db->query("DELETE FROM `events` WHERE `id` IN (" . join(',',$batch) . ")");
					$deleted += self::$db->affected_rows();
					echo ".";
				}
				echo "\nDeduplication complete. Removed ".$deleted." duplicate events.\n";
			}
		} else {
			echo "Checked $progress events, found no duplicates.\n";
		}
	}
}



/// these stay here for now

class FileLockedException extends Exception {
	// Custom exception for file locking issues
}

class MinorError extends Exception {
	// Custom exception for error messages
}

class SkipException extends Exception {
	// just a skip, not an error
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

class ConfigException extends Exception {
	// Custom exception for configuration errors
}

