<?php
namespace Zygor;

class Shell {
	static $last_cmd;

	static function format_shell_options($opts) {
		//error_log(print_r($opts,1));
		$opts_strings = array_map(function($key,$val) {
			if (substr($key,0,1)=="-") $s=$key; else $s = "--".$key;
			if ($val===true) return $s;
			elseif ($val===false || $val===null) return false;
			elseif (is_numeric($val)) return $s . " " . $val;
			else return $s . " " . escapeshellarg($val);
		},array_keys($opts),array_values($opts));
		$opts_strings = array_filter($opts_strings);
		return implode(" ",$opts_strings);
	}
	
	static function run($command,$args=null,$more="") {
		$descriptorspec = array(
			0 => array("pipe", "r"), // STDIN
			1 => array("pipe", "w"), // STDOUT
			2 => array("pipe", "w"), // STDERR
		);
		if (is_array($args)) $args=self::format_shell_options($args);
		if ($args) $command .= " ".$args;
		if ($more) $command .= " ".$more;
		//error_log("SHELL: $command");
		$proc = proc_open($command, $descriptorspec, $pipes);
		self::$last_cmd=$command;
		if (!is_resource($proc)) throw new \Exception();

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		$return = proc_close($proc);
		return [$return,$stdout,$stderr];
	}

	/**
	 * Improved getopt.
	 * $OPTS = \Zygor\Shell::better_getopt([
	 *  // short, long, default
	 *	['a:','arraystuff:',     [1,2,'3']],
	 *  ['', 'verbose',      false],
	 *  ['', 'somenumber:',999999],
	 *  ]);
	 */

	static function better_getopt($opts) {
		$shorts = [];
		$longs = [];
		$defaults = [];
		$same = [];
		foreach ($opts as $opt) {
			list ($short,$long,$default) = $opt;
			if ($short) $shorts .= $short;
			if ($long) $longs[] = $long;
			$short = str_replace(":","",$short); $long = str_replace(":","",$long);
			if ($short) $defaults[$short] = $default;
			if ($long) $defaults[$long] = $default;
			if ($short && $long) { $same[$long] = $short; }
		}
		
		$result_opts = getopt($shorts,$longs); //

		foreach ($same as $long=>$short) {
			if (isset($result_opts[$long])) $result_opts[$short] = $result_opts[$long];
			elseif (isset($result_opts[$short])) $result_opts[$long] = $result_opts[$short];
		}

		foreach ($defaults as $key => $def) {
			if ($def===FALSE && $result_opts[$key]===FALSE) $result_opts[$key]=TRUE; // fix getopt stupid behavior
			if (isset($def) && !isset($result_opts[$key])) $result_opts[$key] = $def;
			if (is_array($def) && is_string($result_opts[$key])) $result_opts[$key] = explode(",", $result_opts[$key] ?: "");
		}
		return $result_opts;
	}

	static function run_only_in_shell() {
		if (isset($_SERVER['REMOTE_ADDR'])) {
			echo "Do not run remotely. CLI only.";
			http_response_code(400); // Bad Request
			exit(1);
		}
	}
}