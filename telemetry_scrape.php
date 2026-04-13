#!/usr/bin/php
<?php
ini_set("max_execution_time",3600);

/**
 * Extracts telemetry data from SV files using Lua scripts.
 * Usage:
 * php telemetry_scrape_svs.php -f <flavour> [--norender] [--maxrenderdays <days>] [--debug-lua] 
 * [--ignore-mtimes] [--start-day <YYYYMMDD>] [--limit <number>] [--filemask <mask>] 
 * [--rendermask <mask>] [--debug] [--today-too] [--verbose]
 */

/* Initialize Telemetry, add command-line options, run scrapers for specified sources (SVs, Packager Logs, etc.) */

define("DAY",86400);

require_once __DIR__ . "/loader.inc.php";

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
	['',  'debug',         false],
	['',  'debug-lua',     false],
	['',  'filemask:',     "*.lua*"], // use to maybe process very specific files only
	['',  'today-too',     false],
	['v', 'verbose',       false],
	['i:','input:',        $valid_inputs=array_keys(TelemetryScrape::list_scrapers())], // which sources to scrape (e.g. sv, packagerlog, etc.)
	['t:','topics:',	   $valid_topics=array_keys(Telemetry::$TOPICS)], // which topics to scrape (default all) - format: topic1,topic2 or topic1/* for all crunchers within a topic
	['',  'verboseflags:', []],
	['',  'maintenance::', false],
	['',  'sure',          false], // for maintenance tasks that are potentially destructive, require --sure to be passed as well
	['',  'onlycount',     false], // for maintenance tasks that only count files without processing them
]);
$FLAVOURS = $OPTS['f'];
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_DAYS"]=$OPTS['maxdays'];

Telemetry::startup($OPTS);
Telemetry::dump_config();
TelemetryScrape::startup();

$inputs = $OPTS['input'];
$topics = $OPTS['topics'];

try {
	if (array_diff($inputs,$valid_inputs)) throw new ErrorException("Invalid input type specified (".implode(",",$inputs)."). Valid types are: ".implode(", ",$valid_inputs));
	if (array_diff($topics,$valid_topics)) throw new ErrorException("Invalid topic specified (".implode(",",$topics)."). Valid topics are: ".implode(", ",$valid_topics));
	
	if ($OPTS['maintenance']) {
		TelemetryScrape::perform_maintenance($OPTS['maintenance'],$OPTS);
		exit;
	}

	// let's get scraping
	
	if (in_array("sv",$inputs)) {
		try	{
			echo "*** Scraping source: SVs\n";
			foreach ($FLAVOURS as $flav) TelemetryScrapeSVs::scrape($flav,$topics);
			echo "*** Done scraping SVs.\n";
		} catch (MinorError $e) {
			echo "Failed: ".$e->getMessage()."\n";
		}
	} else {
		echo "*** Skipping SV scraping.\n";
	}
	if (in_array("packagerlog",$inputs)) {
		try {
			echo "*** Scraping source: Packager Logs\n";
			TelemetryScrapePackagerLog::scrape($topics);
		} catch (MinorError $e) {
			echo "Failed: ".$e->getMessage()."\n";
		}
	} else {
		echo "*** Skipping Packager Log scraping.\n";
	}
} catch (ErrorException $e) {
	echo "ERROR: ".$e->getMessage()."\n";
}

/*
$status['status']="WRITING";
status($status);
file_put_contents(TELEMETRY_FOLDER."/".date("telemetry--Y-m-d--H-i-s.json"),json_encode($metrics));
*/

// AND NOW PHASE TWO


//$result['usedguides']=&$usedguides;
//die(json_encode($result));

end:
