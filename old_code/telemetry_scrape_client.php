<?php
define("LOG_FOLDER","logs");
define("TELEMETRY_FOLDER","telemetry");
define("MTIMES_CACHE_FILENAME","mtimes_cache.json");
define("DAY",86400);
define("STATUS_INTERVAL",1);
define("STATUS_FILENAME","telemetry_scrape_client.status.json");
define("SCRAPELOG_FILENAME","telemetry_scrape_client.log.jsons");
define("NOW",time()); // makes sure the day doesn't change while the script runs.
define("START_TELEMETRY","2015-12-01");
define("UNIQUEUSERS_TIME",7*DAY);

ini_set("max_execution_time",600);

$ZYGOR_CFG__DONT_LOAD_CONFIG=true;
require(__DIR__."/../includes/config.inc.php");

error_reporting(E_ALL^E_WARNING^E_NOTICE^E_DEPRECATED);
set_error_handler("write_error_to_status");
//pcntl_signal(SIGINT,function() { write_error_to_status(E_ERROR,"Terminated",__FILE__,__LINE__); die(); return true; });

$times=[];

status(['status'=>"LISTING",'times'=>$times=array_merge($times,['time_started'=>time(),'time_started_hr'=>date("Y-m-d H:i:s")])]);

$files = glob(__DIR__."/".LOG_FOLDER."/log-*-*-*.txt*");

$today = date("Y-m-d");

$meta = [];
$meta['files_total']=count($files);
$meta['time_started']=time();
$meta['time_started_hr']=date("Y-m-d H:i:s");

$metric_types = ['flavorsinstalled'=>[],'multiflavors'=>[],'uniqueusers'=>[]];

$OPTS = getopt("",["start-day:","overwrite"]);

$unique_users_day=[];

Zygor_Amember::connect();

foreach($files as $n=>$filename) {
	//$f = fopen("php://filter/read=bzip2.decompress/resource=".urlencode($filename),"r");
	//$f = fopen("php://filter/resource=".urlencode($filename),"r");
	unset($day);
	if (preg_match("/-(\d\d-\d\d-\d\d)\\./",$filename,$m)) $day="20".$m[1];
	if (!$day) continue;
	if (isset($OPTS['start-day']) && $OPTS['start-day']>$day) continue;

	if ($day<=START_TELEMETRY) continue;
	if ($day>=$today) continue;
	$daytime = strtotime($day);

	$all=true;
	foreach ($metric_types as $type=>&$mdata) {
		$mdata['dayfile'] = __DIR__."/".TELEMETRY_FOLDER."/".$type."/".$day.".json";
		if (!file_exists($mdata['dayfile'])) $all=false;
	} unset($mdata);
	if ($OPTS['overwrite']!==FALSE && $all) { $meta['files_skipped']++; echo "All files for $day present.\n"; continue; }

	echo $filename."\n";
	$meta['files_processed']++;

	$metrics = [];
	$premetrics = [];
	$unique_users_today = [];

	if (strpos($filename,".bz2")!==FALSE) $f = bzopen($filename,"r");
	else $f = fopen($filename,"r");

	while (($s=fgets($f))!==FALSE) {
		if (preg_match("/--- (?:COMP|LICENSE) : pkg (?:files[^\/]*\/)?(ZygorGuidesViewer[a-zA-Z]*), user=.*? #([\\d]+).*elite=(NO|YES)/",$s,$m)) {
			$flavour=$m[1];
			$elite = ['YES'=>"e",'NO'=>'b'][$m[3]];
			$user=intval($m[2]);
			$premetrics['flavorsinstalled'][$elite][$flavour][$user]=true;
			$unique_users_day[$daytime][$user]=$elite;
			$unique_users_today[$user]=$elite;
		} elseif (preg_match("/--- (QUERY) : pkg (?:files[^\/]*\/)?(ZygorGuidesViewer[a-zA-Z]*), user=.*? #([\\d]+).*elite=(NO|YES)/",$s,$m)) {
			$flavour = $m[2];
			$user = intval($m[3]);
			$elite = ['YES'=>"e",'NO'=>'b'][$m[4]];
			$unique_users_today[$user]=$elite;
		} elseif (preg_match("/--- (?:COMP|LICENSE) : user=.*? #([\\d]+).*elite=(NO|YES)/",$s,$m)) {
			// old logs
			$flavour="ZygorGuidesViewer";
			$user=intval($m[1]);
			$elite = ['YES'=>"e",'NO'=>'b'][$m[2]];
			$premetrics['flavorsinstalled'][$elite][$flavour][$user]=true;
			$unique_users_day[$daytime][$user]=$elite;
			$unique_users_today[$user]=$elite;
		} elseif (preg_match("/--- (?:COMP|LICENSE) : user=.*? #([\\d]+)/",$s,$m)) {
			// really old logs
			$flavour="ZygorGuidesViewer";
			$user=intval($m[1]);
			$elite = 'b';
			$premetrics['flavorsinstalled'][$elite][$flavour][$user]=true;
			$unique_users_day[$daytime][$user]=$elite;
			$unique_users_today[$user]=$elite;
		}
	}
	if (strpos($filename,".bz2")!==FALSE) bzclose($f); else fclose($f);

	$users_flavors=[]; // who has 1, 2, 3?
	foreach ($premetrics['flavorsinstalled'] as $elite=>&$flavs) {
		foreach ($flavs as $flav=>&$users) {
			foreach ($users as $user=>$val)
				$users_flavors[$elite][$user][$flav]=true;
			$metrics['flavorsinstalled'][$flav] = count($users);
		}
	}
	unset($users);
	unset($premetrics);
	//echo "$filename: ".print_r($metrics,1)."\n";

	//print_r($users_flavors);

	$flavbits = [
		'ZygorGuidesViewer'				  =>['flav'=>"Retail",  'licchar'=>"r",'bit'=>1],
		'ZygorGuidesViewerClassic'        =>['flav'=>"Classic", 'licchar'=>"c",'bit'=>2],
		'ZygorGuidesViewerClassicTBC'     =>['flav'=>"MOP",     'licchar'=>"w",'bit'=>4],
		'ZygorGuidesViewerClassicTBCAnniv'=>['flav'=>"TBCAnniv",'licchar'=>"a",'bit'=>8]
	];
	
	$seen_day=0;
	foreach ($users_flavors as $elite=>&$userflavs) {
		echo "..........";
		$user_num=0;
		foreach ($userflavs as $user=>$flavors) {
			$seenmarked = Zygor_Amember::mark_user_as_seen($user,$daytime);
			if ($seenmarked && $elite=="e") Zygor_Amember::mark_user_elite($user,$elite=="e"?1:0);
			
			$bit=0; $list = [];
			foreach ($flavbits as $flavname=>&$flavdata) {
				 if ($flavors[$flavname]) { $bit|=$flavdata['bit']; Zygor_Amember::mark_user_licensed($user,$flavdata['licchar'],$daytime); $list[]=$flavdata['flav']; }
			}
			$metrics['multiflavors'][$elite][join("+",$list)]++;
			$metrics['multiflavors'][$elite][$bit]++;

			$seen_day++;
			$user_num++;
			if ($seen_day%100==0) echo "\x0d".str_repeat("=",11*$user_num/count($userflavs));
		}
		echo "\x0d\e[K";
	}
	echo "Seen: $seen_day\n";

	$unique_users_week = ['e'=>[],'b'=>[]];
	foreach ($unique_users_day as $dt=>&$users) {
		if ($daytime-$dt<UNIQUEUSERS_TIME) {
			foreach ($users as $user=>$elite)
				$unique_users_week[$elite][$user]=true;
		}
	}; unset($users);
	$metrics['uniqueusers']=array_map("count",$unique_users_week);

	/*
	$seenmarked=0;
	foreach ($unique_users_today as $user=>$elite) {
		$seenmarked += Zygor_Amember::mark_user_as_seen($user,$daytime);
		echo "seenmarked $seenmarked\n";
	}
	echo "Seen marked: $seenmarked\n";
	*/

	// ========= SAVE

	foreach ($metric_types as $type=>&$mdata) {
		$data = &$metrics[$type];
		if (file_exists($mdata['dayfile'])) continue;
		echo $mdata['dayfile']."\n";
		file_put_contents($mdata['dayfile'],json_encode($data));
	} unset($mdata);
	$meta['newest_file']=$filename;

}

file_put_contents(__DIR__."/".SCRAPELOG_FILENAME,json_encode($meta)."\n",FILE_APPEND|LOCK_EX);

status([
	'status'=>"IDLE",
	'files_total'=>count($files),
	'times'=>$times=array_merge($times,['time_elapsed'=>time()-$times['time_started']]),
	'metrics'=>$metrics,
	'meta'=>$meta
]);




function status($data) {
	file_put_contents(__DIR__."/".STATUS_FILENAME,json_encode($data));
}

function write_error_to_status($errno, $errstr, $errfile, $errline) {
	global $times;
	if (!($errno & error_reporting())) return;
	status(['status'=>"ERROR",'errno'=>$errno,'errstr'=>$errstr,'errfile'=>$errfile,'errline'=>$errline,'times'=>$times]);
	die("$errno $errfile:$errline $errstr\n");
}
