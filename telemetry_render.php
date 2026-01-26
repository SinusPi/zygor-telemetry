<?php
ini_set("max_execution_time",3600);

/**
 * Extracts telemetry data from SV files using Lua scripts.
 * Usage:
 * php telemetry_scrape_svs.php -f <flavour> [--norender] [--maxrenderdays <days>] [--debug-lua] 
 * [--ignore-mtimes] [--start-day <YYYYMMDD>] [--limit <number>] [--filemask <mask>] 
 * [--rendermask <mask>] [--debug] [--today-too] [--verbose]
 */

if (!defined('WEBHOME')) define("WEBHOME","/home/zygordata/www");
define("DAY",86400);
 
require_once ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/Telemetry.class.php";
require_once ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/VerboseException.class.php";
require_once ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/shell.class.php";
require_once ($_SERVER['DOCUMENT_ROOT']?:WEBHOME)."/includes/zygor.class.inc.php";
require_once __DIR__."/config.inc.php";

\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

$OPTS = \Zygor\Shell::better_getopt([
	['f:','flavour:',     array_keys($TELEMETRY_CFG['WOW_FLAVOUR_DATA'])],
	['', 'norender',      false],
	['', 'maxrenderdays:',999999],
	['', 'debug',         false],
	['', 'debug-lua',     false],
	['', 'ignore-mtimes', false],
	['', 'start-day:',    null],
	['', 'limit:',        null],
	['', 'filemask:',     "*.lua*"],
	['', 'rendermask:',   null],
	['', 'today-too',     false],
	['', 'verbose',       false],
	['', 'verboseflags:', []],
]);
$FLAVOURS = $OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_RENDER_DAYS"]=$OPTS['maxrenderdays'];

TelemetryScrapeSVs::config($TELEMETRY_CFG + $TELEMETRY_SCRAPE_CFG + $OPTS + ['SCRAPE_TOPICS'=>$TELEMETRY_SCRAPE_TOPICS]);

TelemetryScrapeSVs::init();

TelemetryScrapeSVs::crunch();
