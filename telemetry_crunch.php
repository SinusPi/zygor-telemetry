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
 
require_once "classes/Telemetry.class.php";

require_once "includes/VerboseException.class.php";
require_once "includes/shell.class.php";
require_once "includes/zygor.class.inc.php";

\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

$OPTS = \Zygor\Shell::better_getopt([
	['f:','flavour:',     ['wow','wow-classic','wow-classic-tbc','wow-classic-tbc-anniv']],
	['',  'maxdays:',      999999], // use to limit how far back to crunch, for debugging only
	['',  'start-day:',    null], // similar to maxdays, but explicit date
	['',  'today-too',     false],
	['',  'limit:',        null], // stop after N events
	['',  'debug',         false],
	['',  'debug-lua',     false],
	['v', 'verbose',       false],
	['',  'verboseflags:', []],
]);
$FLAVOURS = $OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_DAYS"]=$OPTS['maxdays'];

TelemetryCrunch::config($OPTS);
TelemetryCrunch::init();

foreach ($FLAVOURS as $flav) TelemetryCrunch::crunch_flavour($flav);



//$result['usedguides']=&$usedguides;
//die(json_encode($result));

end:
