#!/usr/bin/php
<?php
ini_set("max_execution_time",3600);

use Zygor\Telemetry\Telemetry;
use Zygor\Telemetry\TelemetryScrape;
use Zygor\Telemetry\Logger;

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

$verboseflags = [ 
	"showconfig" => "Show loaded config at startup",
	"querylog" => "Log DB queries",
];
$OPTS = (array)\Zygor\Shell::better_getopt([
	['f:', 'flavour:',      array_keys(Telemetry::$CFG['WOW_FLAVOUR_DATA'])],
	['i:', 'input:',        $valid_inputs=array_keys(TelemetryScrape::list_scrapers())], // which sources to scrape (e.g. sv, packagerlog, etc.)
	['t:', 'topics:',	    $valid_topics=array_keys(Telemetry::$TOPICS)], // which topics to scrape (default all) - format: topic1,topic2 or topic1/* for all crunchers within a topic
	['',   'maxdays:',      999999], // use to limit how far back to scrape, for debugging only
	['',   'ignore-mtimes', false], // that is: limit by maxdays... or even not at all
	['',   'start-day:',    null], // similar to maxdays, but explicit date
	['',   'end-day:',      null],
	['',   'limit:',        null], // stop after N files
	['',   'debug',         false],
	['',   'debug-lua',     false],
	['',   'filemask:',     "*.lua*"], // use to maybe process very specific files only
	['',   'today-too',     false],
	['v::','verbose::',     false],
	['',   'maintenance::', false],
	['',   'sure',          false], // for maintenance tasks that are potentially destructive, require --sure to be passed as well
	['',   'onlycount',     false], // for maintenance tasks that only count files without processing them
	['p',  'progress',      false], // whether to show progress bar (enforces pre-count)
	['',   'dedupe',        false], // whether to dedupe events immediately after scraping
	['',   'use-dirty',     false], // process 'dirty' files only, skip grepping
	['',   'help',          false],
]);
if (substr($OPTS['start-day'],0,1)=="-") $OPTS['start-day']=date("Ymd",strtotime($OPTS['start-day']." days"));
$OPTS["MAX_DAYS"]=$OPTS['maxdays'];

if ($OPTS['help']) {
	echo "Usage: php telemetry_scrape.php [options]\n\n";
	echo "Options:\n";
	echo "  -f, --flavour <flavour>      Which WoW flavour(s) to scrape (comma-separated or multiple -f). Default is all. Valid flavours: ".implode(", ",array_keys(Telemetry::$CFG['WOW_FLAVOUR_DATA']))."\n";
	echo "  -i, --input <source>         Which source(s) to scrape (comma-separated or multiple -i). Valid sources: ".implode(", ",$valid_inputs)."\n";
	echo "  -t, --topics <list>          Which topics to scrape (format: topic1,topic2 or topic1/* for all crunchers within a topic). Default is all. Valid topics: ".implode(", ",$valid_topics)."\n";
	echo "      --maxdays <days>         Limit scraping to files from the last N days (default: no limit)\n";
	echo "      --start-day <YYYY-MM-DD> Limit scraping to files from after this date (overrides maxdays)\n";
	echo "      --end-day <YYYY-MM-DD>   Limit scraping to files from before this date\n";
	echo "      --filemask <mask>        Only process files matching this mask (e.g. *.lua)\n";
	echo "      --today-too              Include today's files as well (normally excluded since they might still be modified)\n";
	echo "      --ignore-mtimes          Don't check file mtimes, just rely on date in filename and maxdays/start-day/end-day filters\n";
	echo "      --maintenance <task>     Perform maintenance task (e.g. 'cleanup_files') instead of scraping. Use with --sure to actually perform the task, or with --onlycount to just count how many files would be affected.\n";
	echo "      --sure                   Required to actually perform maintenance tasks that are potentially destructive (e.g. deleting files).\n";
	echo "      --onlycount              For maintenance tasks, only count how many files would be affected without performing the action.\n";
	echo "      --progress               Show progress bar (enforces pre-counting of files to process)\n";
	echo "      --dedupe                 Whether to dedupe events immediately after scraping (default: false)\n";
	echo "      --use-dirty              Whether to process 'dirty' files only, skipping the grep step (default: false)\n";
	echo "      --debug                  Enable debug mode (more verbose logging, maybe other effects depending on scrapers)\n";
	echo "      --debug-lua              Enable Lua debugging in scrapers that support it (if applicable)\n";
	echo "  -v, --verbose [flag,flag...] Enable verbose logging; valid flags: ".implode(", ",array_keys($verboseflags)).". If no flags specified, use all.\n";
	exit(0);
}

Telemetry::verify_verbose_flags($OPTS['verbose'], $verboseflags);
Telemetry::startup($OPTS);
if ($OPTS['verbose']['showconfig']) Telemetry::dump_config();

TelemetryScrape::startup();

$flavours = $OPTS['flavour'];
$inputs = $OPTS['input'];
$topics = $OPTS['topics'];

$SUMMARY = [
	'events_scraped' => 0,
	'files_scraped' => 0,
];

try {
	if (array_diff($inputs,$valid_inputs)) throw new ErrorException("Invalid input type specified (".implode(",",$inputs)."). Valid types are: ".implode(", ",$valid_inputs));
	if (array_diff($topics,$valid_topics)) throw new ErrorException("Invalid topic specified (".implode(",",$topics)."). Valid topics are: ".implode(", ",$valid_topics));
	
	if ($OPTS['maintenance']) {
		TelemetryScrape::perform_maintenance($OPTS['maintenance'],$OPTS);
		exit;
	}

	// let's get scraping
	$scrapers = TelemetryScrape::list_scrapers();
	foreach ($scrapers as $input => $scraper) {
		if (!in_array($input, $inputs)) {
			echo "*** Scraping source: $input - not selected.\n";
			continue;
		}
		$scraper = $scrapers[$input] ?: null;
		if (!$scraper)
			throw new ErrorException("No scraper found for input type '$input'. Skipping.");
		
		$scraper['class']::run($flavours,$topics);
		
		echo "*** Done scraping source: $input.\n";
	}

	Logger::log("Scrape run completed: inputs=".implode(",",$inputs)."; topics=".implode(",",$topics)."; flavours=".implode(",",$flavours).". Got ".$SUMMARY['events_scraped']." events.",null,"MAIN");
	
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
