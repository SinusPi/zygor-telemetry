#!/usr/bin/php
<?php
ini_set("max_execution_time",3600);

/**
 * Various telemetry admin tasks
 */

define("DAY",86400);

require_once __DIR__ . "/loader.inc.php";
use Zygor\Telemetry\Telemetry;
use Zygor\Telemetry\TelemetryScrape;

require_once "includes/shell.class.php";
\Zygor\Shell::run_only_in_shell();

//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

Telemetry::config();
Telemetry::load_topics();

$OPTS = (array)\Zygor\Shell::better_getopt([
	['f:','flavour:',      array_keys(Telemetry::$CFG['WOW_FLAVOUR_DATA'])],
	['',  'maxdays:',      999999], // use to limit how far back to scrape, for debugging only
	['',  'ignore-mtimes', false], // that is: limit by maxdays... or even not at all
	['',  'start-day:',    null], // similar to maxdays, but explicit date
	['',  'end-day:',      null],
	['',  'limit:',        null], // stop after N files
	['',  'filemask:',     "*.lua*"], // use to maybe process very specific files only
	['',  'today-too',     false],
	['v', 'verbose',       false],
	['i:','input:',        $valid_inputs=array_keys(TelemetryScrape::list_scrapers())], // which sources to scrape (e.g. sv, packagerlog, etc.)
	['t:','topics:',	   $valid_topics=array_keys(Telemetry::$TOPICS)], // which topics to scrape (default all) - format: topic1,topic2 or topic1/* for all crunchers within a topic
	['',  'verboseflags:', []],
	['',  'sure',         false], // for maintenance tasks that are potentially destructive, require --sure to be passed as well
	['',  'do:',            ""],
	['',  'from:',          ""],
	['',  'to:',            ""],
]);
$FLAVOURS = $OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_DAYS"]=$OPTS['maxdays'];
$inputs = $OPTS['input'];
$topics = $OPTS['topics'];

Telemetry::startup($OPTS);

if ($OPTS['do']) {
	try {
		Telemetry::performMisc($OPTS['do'],$OPTS);
	} catch (Exception $e) {
		die("Error performing misc task '".$OPTS['do']."': ".$e->getMessage()."\n");
	}
	exit;
} else
	echo "Nothing to --do!\n";