<?php
ini_set("max_execution_time",3600);

/**
 * Extracts telemetry data from SV files using Lua scripts.
 * Usage:
 * php telemetry_scrape_svs.php -f <flavour> [--norender] [--maxrenderdays <days>] [--debug-lua] 
 * [--ignore-mtimes] [--start-day <YYYYMMDD>] [--limit <number>] [--filemask <mask>] 
 * [--rendermask <mask>] [--debug] [--today-too] [--verbose]
 */

define("WEBHOME","/home/zygordata/www");
define("DAY",86400);
 
require ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/Telemetry.class.php";
require ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/VerboseException.class.php";
require ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/shell.class.php";
require __DIR__."/config.inc.php";

\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

$OPTS = \Zygor\Shell::better_getopt([
	['f:','flavour:',   	array_keys($TELEMETRY_CFG['WOW_FLAVOUR_DATA'])],
	['t:','topics:',  		array_keys($TELEMETRY_SCRAPE_TOPICS)],
	['', 'verbose',       false],
	['', 'verboseflags:', []],
]);
$FLAVOURS = $OPTS['flavour']?:[];
$OPTS['VERBOSE_FLAGS'] = array_filter(explode(",", $OPTS['verboseflags'] ?: ""));
$OPTS['SCRAPE_TOPICS'] = $TELEMETRY_SCRAPE_TOPICS;

Telemetry::config($TELEMETRY_CFG + $OPTS);

TelemetryScrapeSVs::set_error_reporting();

foreach ($FLAVOURS as $flav) {
	foreach ($OPTS['topics']?:[] as $dp_name) {
		$counts = Telemetry::get_counts($flav, $dp_name);
		echo $dp_name.":\n";
		print_r($counts);
	}
}