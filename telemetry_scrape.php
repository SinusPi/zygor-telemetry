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
 
require_once "Telemetry.class.php";

require_once "includes/VerboseException.class.php";
require_once "includes/shell.class.php";
require_once "includes/zygor.class.inc.php";

\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

$OPTS = \Zygor\Shell::better_getopt([
	['f:','flavour:',     ['wow','wow-classic','wow-classic-tbc']],
	['', 'maxrenderdays:',999999],
	['', 'debug',         false],
	['', 'debug-lua',     false],
	['', 'ignore-mtimes', false],
	['', 'start-day:',    null],
	['', 'limit:',        null],
	['', 'filemask:',     "*.lua*"],
	['', 'today-too',     false],
	['', 'verbose',       false],
	['', 'verboseflags:', []],
]);
$FLAVOURS = $OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_RENDER_DAYS"]=$OPTS['maxrenderdays'];

TelemetryScrapeSVs::config($OPTS);
TelemetryScrapeSVs::load_topics();
TelemetryScrapeSVs::init();

// PHASE ONE: SCRAPE

foreach ($FLAVOURS as $flav) TelemetryScrapeSVs::scrape_flavour($flav);

/*
$status['status']="WRITING";
status($status);
file_put_contents(TELEMETRY_FOLDER."/".date("telemetry--Y-m-d--H-i-s.json"),json_encode($metrics));
*/

// AND NOW PHASE TWO


//$result['usedguides']=&$usedguides;
//die(json_encode($result));

end:

TelemetryScrapeSVs::stat([
	'status'=>"DONE",
	'progress'=>[
		'time_total'=>time()-Telemetry::$last_status['time_started'],
		'progress_raw'=>null,
		'progress_total'=>$total,
		'progress_percent'=>100,
		'speed_fps'=>null,
		'time_remaining'=>null,
		'time_total_est_hr'=>null,
]
]);
