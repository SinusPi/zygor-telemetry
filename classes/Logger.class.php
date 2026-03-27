<?php

/**
 * Logger utility class for telemetry operations.
 * Handles logging to file and console output with optional tagging.
 */
class Logger {
	static $last_tag = "TELEMETRY";

	static function log($s,$tag=null) {
		$tag = $tag ?: self::$last_tag;
		self::$last_tag = $tag;
		if (Telemetry::$CFG['LOG_FILENAME']) {
			// log to file
			file_put_contents(
				Telemetry::$CFG['TELEMETRY_ROOT']."/".Telemetry::$CFG['LOG_FILENAME'],
				date("Y-m-d H:i:s").".".sprintf("%03d",explode(" ", microtime())[0]*1000)." [$tag] ".$s."\n",
				FILE_APPEND|LOCK_EX
			);
		}
		if (function_exists('posix_isatty') ? posix_isatty(STDIN) : (php_sapi_name() === 'cli')) echo $s."\n";
	}

	static function vlog($s) {
		if (Telemetry::$CFG['verbose']) self::log($s);
	}

	static function vflog($flag, $message) {
		if (Telemetry::$CFG['verbose'] && in_array($flag, Telemetry::$CFG['VERBOSE_FLAGS'])) self::log("[$flag] $message");
	}
}
