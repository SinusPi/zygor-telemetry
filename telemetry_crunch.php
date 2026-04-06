#!/usr/bin/php
<?php
ini_set("max_execution_time",3600);

/**
 * Crunches telemetry data in DB
 * Usage:
 * php telemetry_crunch.php -f <flavour> [--norender] [--maxrenderdays <days>] [--debug-lua] 
 * [--ignore-mtimes] [--start-day <YYYYMMDD>] [--limit <number>] [--filemask <mask>] 
 * [--rendermask <mask>] [--debug] [--today-too] [--verbose]
 */

define("DAY",86400);

require_once __DIR__ . "/loader.inc.php";

require_once "includes/shell.class.php";
\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

Telemetry::startup();

// build array of crunchers in format "topic/crunchername" for better_getopt validation
$valid_crunchers = array_merge(...array_values(array_map(
	function($topic) {
		return array_map(
			function($cruncher,$idx) use ($topic) { return $topic->name."/".($cruncher->name?:($idx+1)); },
			$topic->crunchers, array_keys($topic->crunchers)
		);
	}, Telemetry::$TOPICS)));

$OPTS = \Zygor\Shell::better_getopt([
	['f:','flavour:',      array_keys(Telemetry::$CFG['WOW_FLAVOUR_DATA'])],
	['',  'maxdays:',      999999], // use to limit how far back to crunch, for debugging only
	['',  'start-day:',    null], // similar to maxdays, but explicit date
	['',  'today-too',     false],
	['',  'limit:',        null], // stop after N events
	['',  'debug',         false],
	['',  'debug-lua',     false],
	['v', 'verbose',       false],
	['c:','crunchers:',    $valid_crunchers], // which crunchers to run (format: topic/crunchername or topic/number), default all
	['',  'verboseflags:', []],
]);
$FLAVOURS = (array)$OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_DAYS"]=$OPTS['maxdays'];

$crunchers = (array)$OPTS['crunchers'];
if (array_diff($crunchers,$valid_crunchers)) {
	throw new ErrorException("Invalid cruncher specified (".implode(",",$crunchers)."). Valid crunchers are: ".implode(", ",$valid_crunchers));
}

Telemetry::startup($OPTS);
Telemetry::dump_config();

foreach ($FLAVOURS as $flav) TelemetryCrunch::crunch_flavour($flav,$crunchers);



//$result['usedguides']=&$usedguides;
//die(json_encode($result));

end:
