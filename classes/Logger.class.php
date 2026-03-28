<?php

/**
 * Logger utility class for telemetry operations.
 * Handles logging to file and console output with optional tagging.
 */
class Logger {
	static $tag = "TELEMETRY";

	static $verbose = false;
	static $verbose_flags = [];
	static $log_path = null;

	static function init($cfg) {
		self::$verbose = $cfg['verbose'] ?: false;
		self::$tag = $cfg['tag'] ?: "TELEMETRY";
		self::$verbose_flags = $cfg['verbose_flags'] ?: [];
		self::$log_path = $cfg['log_path'] ?: null;
	}

	static function log($s,$tag=null) {
		$tag = $tag ?: self::$tag;
		self::$tag = $tag;
		if (self::$log_path) {
			// log to file
			file_put_contents(
				self::$log_path,
				date("Y-m-d H:i:s").".".sprintf("%03d",explode(" ", microtime())[0]*1000)." [$tag] ".$s."\n",
				FILE_APPEND|LOCK_EX
			);
		}
		if (function_exists('posix_isatty') ? posix_isatty(STDIN) : (php_sapi_name() === 'cli')) echo $s."\n";
	}

	static function vlog($s) {
		if (self::$verbose) self::log($s);
	}

	static function vflog($flag, $message) {
		if (self::$verbose && in_array($flag, self::$verbose_flags)) self::log("[$flag] $message");
	}
}
