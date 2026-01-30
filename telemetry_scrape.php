<?php
ini_set("max_execution_time",3600);

/**
 * Extracts telemetry data from SV files using Lua scripts.
 * Usage:
 * php telemetry_scrape_svs.php -f <flavour> [--norender] [--maxrenderdays <days>] [--debug-lua] 
 * [--ignore-mtimes] [--start-day <YYYYMMDD>] [--limit <number>] [--filemask <mask>] 
 * [--rendermask <mask>] [--debug] [--today-too] [--verbose]
 */

define("DAY",86400);
 
require_once "classes/Telemetry.class.php";

require_once "includes/VerboseException.class.php";
require_once "includes/shell.class.php";
require_once "includes/zygor.class.inc.php";

\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

Telemetry::config();

$OPTS = \Zygor\Shell::better_getopt([
	['f:','flavour:',      array_keys(Telemetry::$CFG['WOW_FLAVOUR_DATA'])],
	['',  'maxdays:',      999999], // use to limit how far back to scrape, for debugging only
	['',  'ignore-mtimes', false], // that is: limit by maxdays... or even not at all
	['',  'start-day:',    null], // similar to maxdays, but explicit date
	['',  'limit:',        null], // stop after N files
	['',  'debug',         false],
	['',  'debug-lua',     false],
	['',  'filemask:',     "*.lua*"], // use to maybe process very specific files only
	['',  'today-too',     false],
	['v', 'verbose',       false],
	['',  'verboseflags:', []],
]);
$FLAVOURS = $OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_DAYS"]=$OPTS['maxdays'];

TelemetryScrapeSVs::config($OPTS);
TelemetryScrapeSVs::init();

// PHASE ONE: SCRAPE

foreach ($FLAVOURS as $flav) TelemetryScrapeSVs::scrape($flav);

/*
$status['status']="WRITING";
status($status);
file_put_contents(TELEMETRY_FOLDER."/".date("telemetry--Y-m-d--H-i-s.json"),json_encode($metrics));
*/

// AND NOW PHASE TWO


//$result['usedguides']=&$usedguides;
//die(json_encode($result));

end:
