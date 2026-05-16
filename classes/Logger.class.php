<?php
namespace Zygor\Telemetry;

/**
 * Logger utility class for telemetry operations.
 * Handles logging to file and console output with optional tagging.
 */
class Logger {
	static $tag = "TELEMETRY";

	static $verbose = [];
	static $log_path = null;

	static function init($cfg) {
		self::$verbose = $cfg['verbose'] ?: [];
		self::$tag = $cfg['tag'] ?: "TELEMETRY";
		self::$log_path = $cfg['log_path'] ?: null;
		self::setup_db();
	}

	static function log($s,$tag=null, $level='INFO') {
		$tag = $tag ?: self::$tag;
		self::$tag = $tag;
		if (self::$log_path) {
			// log to file
			file_put_contents(
				self::$log_path,
				date("Y-m-d H:i:s").".".sprintf("%03d",explode(" ", microtime())[0]*1000)." [$tag] <$level> ".$s."\n",
				FILE_APPEND|LOCK_EX
			);
		}
		Telemetry::$db->query("INSERT INTO log (`time`, `level`, `tag`, `message`) VALUES (NOW(3), {s}, {s}, {s})", $level, $tag, $s);
		if (function_exists('posix_isatty') ? posix_isatty(STDIN) : (php_sapi_name() === 'cli')) echo $s."\n";
	}

	static function vlog($s, $level='INFO') {
		if (self::$verbose) self::log($s, self::$tag, $level);
	}

	static function vflog($flag, $message, $level='INFO') {
		if (self::$verbose && in_array($flag, self::$verbose)) self::log("[$flag] <$level> $message", self::$tag, $level);
	}

	// set up DB schema for logging
	static function setup_db() {
		if (!Telemetry::$db) return; // DB not yet connected; will be called again after db_startup()
		$table = "log";
		$schema = [
			"1" => "CREATE TABLE IF NOT EXISTS `$table` (
				`id` bigint unsigned NOT NULL AUTO_INCREMENT,
				`time` datetime(3) NOT NULL,
				`level` ENUM('MAIN','INFO','WARNING','ERROR','DEBUG') NOT NULL DEFAULT 'INFO',
				`tag` varchar(50) NOT NULL,
				`message` text NOT NULL,
				PRIMARY KEY (`id`),
				KEY `time_idx` (`time`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
		];
		$result = (new SchemaManager(Telemetry::$db->conn))->manageTable($table, $schema);
		if ($result['error']) {
			throw new \Exception("Failed to set up log table: " . $result['error']);
		}
	}
}
