<?php
if (!defined('WEBHOME')) define("WEBHOME","/home/zygordata/www");

require_once($_SERVER['DOCUMENT_ROOT']?:"~/www")."/includes/Telemetry.class.php";
require_once($_SERVER['DOCUMENT_ROOT']."/includes/config.inc.php");
require_once(__DIR__."/config.inc.php");

header("Content-type: application/json");

define("STORAGE_FOLDER","storage");
define("TELEMETRY_FOLDER","telemetry");
define("WOW_FLAVOURS","wow,wow-classic,wow-classic-tbc",'wow-classic-tbc-anniv');

$from = $_REQUEST['from'];
$to = $_REQUEST['to'];
$flavour = $_REQUEST['flavour'] ?: $_REQUEST['flavor'];
if (!in_array($flavour,explode(",",WOW_FLAVOURS))) $flavour=next(explode(",",WOW_FLAVOURS));

$metric = $_REQUEST['metric'];

$result['metric']=$metric;

// Parts still use old format, parts will connect to db...

try {
	if ($metric=="usedguide") {
		$from = Telemetry::date_strip_dashes($from);
		$to = Telemetry::date_strip_dashes($to);
		
		$folder = STORAGE_FOLDER."/".TELEMETRY_FOLDER."/".$flavour."/usedguide";
		$days = Telemetry::read_days($folder,$from,$to);
		$files = $days['files'];
		$result['flavour']=$flavour;
		$result['data_total']=$days['data_total'];
		$result['data_match']=$days['data_match'];

		$usedguides = [];
		foreach ($files as $fn) {
			$guides = @json_decode(@file_get_contents($folder."/".$fn));
			if (!$guides) continue;
			foreach ($guides as $guide=>$count) {
				if ($_REQUEST['grouptypes']) {
					$folders = explode("/",$guide);
					$usedguides[$folders[0]]+=$count;
				} else 
					$usedguides[$guide]+=$count;
			}
		}
		if ($_REQUEST['type']) $usedguides = array_filter($usedguides,function($val,$key) { return strpos($key,$_REQUEST['type'])===0; },ARRAY_FILTER_USE_BOTH);
		if ($_REQUEST['find']) $usedguides = array_filter($usedguides,function($val,$key) { return strpos($key,$_REQUEST['find'])!==FALSE; },ARRAY_FILTER_USE_BOTH);
		if ($_REQUEST['sort']=="name") ksort($usedguides); else arsort($usedguides);
		if ($_REQUEST['limit']) $usedguides = array_slice($usedguides,0,intval($_REQUEST['limit']));
		$result['usedguides']=&$usedguides;

		die(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
	}

	elseif ($metric=="flavorsinstalled") {
		// force YYYY-MM-DD because Sinus sucks at consistency
		$from = Telemetry::date_add_dashes($from);
		$to = Telemetry::date_add_dashes($to);
		
		$folder="updater/telemetry/flavorsinstalled";
		$days = Telemetry::read_days($folder,$from,$to);
		$files = $days['files'];
		$result['data_total']=$days['data_total'];
		$result['data_match']=$days['data_match'];
		
		sort($files);

		$mode = $_REQUEST['mode'] ?: "daily";

		$result['mode']=$mode;

		$flavors = [];
		foreach ($files as $fn) {
			$flavs = @json_decode(@file_get_contents($folder."/".$fn)); if (!$flavs) continue;
			$fn = str_replace(".json","",$fn);
			if ($mode=="total")
				foreach ($flavs as $flav=>$count) $flavors[$flav]+=$count;
			elseif ($mode=="daily")
				$flavors[$fn] = $flavs;
			elseif ($mode=="weekly") {
				$day = strtotime($fn);
				$dayow = date("w",$day);
				$sunday = $day-86400*$dayow;
				$sunday_ymd = date("Y-m-d",$sunday);
				foreach ($flavs as $flav=>$count) $flavors[$sunday_ymd][$flav]+=$count;
			}
		}
		//if ($_REQUEST['find']) $usedguides = array_filter($usedguides,function($val,$key) { return strpos($key,$_REQUEST['find'])!==FALSE; },ARRAY_FILTER_USE_BOTH);
		//if ($_REQUEST['sort']=="name") ksort($usedguides); else arsort($usedguides);
		//if ($_REQUEST['limit']) $flavors = array_slice($flavors,0,intval($_REQUEST['limit']));
		$result['flavorsinstalled']=&$flavors;

		die(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

	}

	elseif ($metric=="multiflavors") {
		// force YYYY-MM-DD because Sinus sucks at consistency
		$from = Telemetry::date_add_dashes($from);
		$to = Telemetry::date_add_dashes($to);
		
		$folder="updater/telemetry/multiflavors";
		$days = Telemetry::read_days($folder,$from,$to);
		$files = $days['files'];
		$result['data_total']=$days['data_total'];
		$result['data_match']=$days['data_match'];
		
		sort($files);

		$mode = $_REQUEST['mode'] ?: "average";

		$result['mode']=$mode;

		$flavors = [];
		foreach ($files as $fn) {
			$e_flavs = @json_decode(@file_get_contents($folder."/".$fn),true); if (!$e_flavs) continue;
			if (!$e_flavs['b']) $e_flavs=['b'=>$e_flavs,'e'=>[]];
			$day = str_replace(".json","",$fn);
			if ($mode=="total")
				foreach ($flavs as $flav=>$count) $flavors[$flav]+=$count; // unused
			elseif ($mode=="average")
				foreach ($e_flavs as $elite=>&$flavs) foreach ($flavs as $flav=>$count) $flavors[$elite][$flav]+=$count; // <=============
			elseif ($mode=="daily")
				$flavors[$day] = $flavs;
			elseif ($mode=="weekly") {
				$dayt = strtotime($day);
				$dayow = date("w",$dayt);
				$sunday = $dayt-86400*$dayow;
				$sunday_ymd = date("Y-m-d",$sunday);
				foreach ($flavs as $flav=>$count) $flavors[$sunday_ymd][$flav]+=$count;
			}
		}
		if ($mode=="average") foreach ($flavors as $elite=>&$flavs) foreach ($flavs as $flav=>$count) $flavs[$flav] = intval($flavs[$flav]/count($files));
		//if ($_REQUEST['find']) $usedguides = array_filter($usedguides,function($val,$key) { return strpos($key,$_REQUEST['find'])!==FALSE; },ARRAY_FILTER_USE_BOTH);
		//if ($_REQUEST['sort']=="name") ksort($usedguides); else arsort($usedguides);
		//if ($_REQUEST['limit']) $flavors = array_slice($flavors,0,intval($_REQUEST['limit']));
		$result['multiflavors']=&$flavors;

		die(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

	}

	elseif ($metric=="search") {
		$from = Telemetry::date_strip_dashes($from);
		$to = Telemetry::date_strip_dashes($to);

		$folder = STORAGE_FOLDER."/".TELEMETRY_FOLDER."/".$flavour."/search";
		$days = Telemetry::read_days($folder,$from,$to);
		$files = $days['files'];
		$result['data_total']=$days['data_total'];
		$result['data_match']=$days['data_match'];

		$all_searches = [];
		foreach ($files as $fn) {
			$searches = @json_decode(@file_get_contents($folder."/".$fn),true);  if (!$searches) continue;
			foreach ($searches as $searchkey=>$count) {
				//$result['debug'][]="$searchkey = $count";
				unset($query);
				if (preg_match("#(.*)=([0-9]*)/(.)#",$searchkey,$m)) list ($_,$query,$qresults,$faction)=$m;
				if ($_REQUEST['faction'] && $_REQUEST['faction']!=$faction) continue;
				if ($query)
					$all_searches[]=['q'=>$query,'c'=>$count,'r'=>$qresults,'f'=>substr($faction,0,1)];
			}
		}
		usort($all_searches,function($a,$b) {
			if ($_REQUEST['sort']=="query") return strcmp($a['q'],$b['q']);
			elseif ($_REQUEST['sort']=="count") return $b['c']-$a['c'];
			elseif ($_REQUEST['sort']=="results") return $b['r']-$a['r'];
		});
		$result['search']=&$all_searches;
		die(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
	}

	elseif ($metric=="ui") {
		$from = Telemetry::date_strip_dashes($from);
		$to = Telemetry::date_strip_dashes($to);

		$folder = STORAGE_FOLDER."/".TELEMETRY_FOLDER."/".$flavour."/ui";
		$days = Telemetry::read_days($folder,$from,$to);

		$files = $days['files'];
		$result['data_total']=$days['data_total'];
		$result['data_match']=$days['data_match'];

		$all_users = [];
		foreach ($files as $fn) {
			list ($day,$user) = explode("/",str_replace(".json","",$fn));
			$ui = @json_decode(@file_get_contents($folder."/".$fn),true);  if (!$ui) continue;
			$all_users[$user] = array_merge((array)$all_users[$user],$ui);
		}

		if ($_REQUEST['raw']) {
			foreach ($all_users as $user=>$ui) {
				echo "\n\n".$user."\n\n";
				foreach ($ui as $u) {
					echo " - ".date("Y-m-d H:i:s",$u['time']).": ".$u['event'];
					unset($u['time'],$u['event']);
					echo ": ".json_encode($u);
					echo "\n";
				}
			}
			die();
		}

		$partiers=0;

		$is_elite_check = intval($_REQUEST['elite_check']);

		if ($is_elite_check) {
			Zygor_Amember::connect(); $sql = &Zygor_Amember::$sql;
			$q = mysqli_query($sql,"SELECT
				DISTINCT(m.`login`) AS 'login'
				FROM `amember_payments` AS p, `amember_members` as m
				WHERE p.`product_id`=211
				AND p.`completed`=1
				AND p.member_id=m.member_id
				AND NOT (p.`begin_date`>CAST('$to' AS DATE))
				AND NOT (p.`expire_date`<CAST('$from' AS DATE))
			");
			$error = mysqli_error($sql);
			if ($error) die(json_encode(['error'=>$error]));
			while ($r = mysqli_fetch_assoc($q)) {
				$elites[strtolower($r['login'])]=true;
			}
		}


		$metrics = [
			[
				'SHARE_STATE'=>function($e,&$tmp) {
					if ($e['state']=="party") { $tmp['party']=true; }
					elseif ($e['state']=="master") { $tmp['master']=true; }
					elseif ($e['state']=="slave") { $tmp['slave']=true; }
					elseif ($e['state']=="solo") { $tmp['solo']=true; }
					else { $tmp['partystate']=$e['state']; }
				},
				'outcome'=>function($tmp,&$result) {
					if ($tmp['party']) $result['share']['party']++;
					if ($tmp['master']) $result['share']['share_masters']++;
					if ($tmp['slave']) $result['share']['share_slaves']++;
					if ($tmp['partystate']) $result['share']['_'.$tmp['partystate']]++;
				},
				'descs'=>[
					//'partiers'=>"played in a party at all",
				],
			],
			[
				'STEPS_COMPLETED'=>function($e,&$tmp) { $tmp['steps_completed']+=$e['num']; },
				'outcome'=>function($tmp,&$result) {
					if ($tmp['steps_completed']==0) $result['completers']['none']++;
					elseif ($tmp['steps_completed']<10) $result['completers']['<10']++;
					elseif ($tmp['steps_completed']<50) $result['completers']['<50']++;
					else $result['completers']['>=50']++;
				},
				'descs'=>[
					'completers.none'=>"never completed a step",
					'completers.<10'=>"completed under 10 steps",
					'completers.<50'=>"completed under 50 steps",
					'completers.>=50'=>"completed over 50 steps",
				],
			],
			[
				'WINDOW_STATE'=>function($e,&$tmp) { if ($e['state']==false) $tmp['hidden']++; else $tmp['shown']++; },
				'outcome'=>function($tmp,&$result) {
					if ($tmp['shown']>0 && $tmp['hidden']==0) $tmp['state_shown']='always';
					elseif ($tmp['hidden']>0 && $tmp['shown']==0) $tmp['state_shown']='never';
					elseif ($tmp['shown']==0 && $tmp['hidden']==0) $tmp['state_shown']='zeros';
					elseif ($tmp['shown']>=$tmp['hidden']) $tmp['state_shown']='usually';
					elseif ($tmp['shown']< $tmp['hidden']) $tmp['state_shown']='rarely';
					else $tmp['state_shown']="wtf";
					$result['shown'][$tmp['state_shown']]++;
				},
				'descs'=>[
					'shown.always'=>"window always shown",
					'shown.never'=>"window never shown",
					'shown.usually'=>"window shown more/same than hidden",
					'shown.rarely'=>"window hidden more than shown",
					'shown.zeros'=>"window state never recorded?",
				],
			],
			[
				'CLICKED_GOAL'=>function($e,&$tmp) { if ($e['button']=="LeftButton") $tmp['goalclicks']++; else $tmp['goalrclicks']++; },
				'outcome'=>function($tmp,&$result) {
					if ($tmp['goalclicks']>0) $result['goalclickers']['left']++;
					if ($tmp['goalrclicks']>0) $result['goalclickers']['right']++;
				},
				'descs'=>[
					'goalclickers.left'=>"leftclicked goals at all",
					'goalclickers.right'=>"rightclicked goals at all",
				],
			],
			[
				'CLICKED_PROGRESSBAR'=>function($e,&$tmp) { $tmp['progclicks']++; },
				'outcome'=>function($tmp,&$result) {
					if ($tmp['progclicks']>0) $result['progressclickers']++;
				},
				'descs'=>[
					'progressclickers'=>"clicked progress bar at all" 
				],
			],
			[
				'GUIDES_LOADED'=>function($e,&$tmp) {
					$count = count($e['tabs']);
					$tmp['maxtabs']=max($tmp['maxtabs'],$count);
					
					$guide = $e['current']['title'];
					$tablist = implode(",",array_column($e['tabs'],'title'));
					if ($tmp['last_guide']!=$guide && $tmp['last_tablist']==$tablist) $tmp['tabswitches']++;
					$tmp['last_tablist']=$tablist;
					$tmp['last_guide']=$guide;
				},
				'outcome'=>function($tmp,&$result) {
					if ($tmp['maxtabs']==1) $result['maxtabs']['1']++;
					elseif ($tmp['maxtabs']==2) $result['maxtabs']['2']++;
					elseif ($tmp['maxtabs']==3) $result['maxtabs']['3']++;
					elseif ($tmp['maxtabs']<=5) $result['maxtabs']['4-5']++;
					elseif ($tmp['maxtabs']<=10) $result['maxtabs']['6-10']++;
					else $result['maxtabs']['over 10']++;
					ksort($result['maxtabs']);
					if ($tmp['tabswitches']>=3) $result['tab_swappers']++;
				},
				'descs'=>[
					'tab_swappers'=>"3+ guide changes while tabs remain" 
				],
			],
			[
				'outcome'=>function($tmp,&$result) {
					if ($tmp['steps_completed']==0) {
						$result['0steps']['maxtabs'][($tmp['maxtabs']?:0)]++;
						$result['0steps']['tabswitches'][min(20,($tmp['tabswitches']?:0))]++;
						$result['0steps']['shown'][$tmp['state_shown']?:"?"]++;
					}
				}
			]
		];

		$result['usercount']=count(array_keys($all_users));
		if ($is_elite_check) {
			$result['elite']=[];
			$result['basic']=[];
		} else {
			$result['all_users']=[];
		}

		foreach ($all_users as $user=>$uis) {
			list($login,$acct)=explode("@",$user);
			$elite_state = ($is_elite_check ? ($elites[$login]?"elite":"basic") : "all_users");
			$result[$elite_state]['usercount']++;
			$tmp=[];
			foreach ($uis as $event) {
				foreach ($metrics as $m=>$met) {
					$handler=$met[$event['event']];
					if ($handler) $handler($event,$tmp);
				}
			}
			foreach ($metrics as $m=>$met) $met['outcome']($tmp,$result[$elite_state]);
		}

		foreach ($metrics as $m=>$met) $result['descriptions']=array_merge((array)$result['descriptions'],$met['descs']);

		ksort($result['all_users']['0steps']['maxtabs']);

		//$result['all_users']=&$all_users;
		die(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
	}

	elseif ($metric=="users_created") {
		$from = Telemetry::date_strip_dashes($from);
		$to = Telemetry::date_strip_dashes($to);

		Zygor_Amember::connect(); $sql = &Zygor_Amember::$sql;

		$q = mysqli_query($sql,"SELECT count(`member_id`) FROM amember_members WHERE added BETWEEN CAST('$from' AS DATE) AND CAST('$to' AS DATE)");
		$error = mysqli_error($sql);
		if ($error) die(json_encode(['error'=>$error]));
		$r = mysqli_fetch_row($q);
		die(json_encode(['users_created'=>intval($r[0])]));
	}

	elseif ($metric=="users_seen") {
		$from = Telemetry::date_add_dashes($from);
		$to = Telemetry::date_add_dashes($to);

		Zygor_Amember::connect(); $sql = &Zygor_Amember::$sql;

		if ($_REQUEST['daily']) {
			$q = $sql->query("SELECT
				s.`day`,
				SUM((SELECT SUM(p.`payment_id`) FROM `amember_payments` p WHERE p.`member_id`=s.`member_id` AND p.`product_id`=211 AND p.`completed`=1 AND p.`begin_date`<=s.`day` AND p.`expire_date`>=s.`day`)>0) AS elites,
				COUNT(`member_id`) AS members
				FROM `amember_members_seenday` AS s
				WHERE s.`day` BETWEEN CAST('$from' AS DATE) AND CAST('$to' AS DATE)
				GROUP BY s.`day`
			");
			$error = $sql->error;
			if ($error) die(json_encode(['error'=>$error]));
			while ($rdaily = $q->fetch_assoc()) {
				$rdaily['members']=intval($rdaily['members']);
				$rdaily['elites']=intval($rdaily['elites']);
				$rdailies[] = $rdaily;
			}
		}
				
		$and_maybe_elites = ($_REQUEST['elites']
		 ? ", COUNT(DISTINCT (SELECT MAX(p.`member_id`) FROM `amember_payments` p WHERE p.`member_id`=s.`member_id` AND p.`product_id`=211 AND p.`completed`=1 AND p.`begin_date`<=s.day AND p.`expire_date`>=s.`day`)) AS elites"
		 : "");
		$q = $sql->query(
			"SELECT
			COUNT(DISTINCT member_id) AS members
			$and_maybe_elites
			FROM `amember_members_seenday` AS s
			WHERE s.`day` BETWEEN CAST('$from' AS DATE) AND CAST('$to' AS DATE)
			");
		$error = $sql->error;
		if ($error) die(json_encode(['error'=>$error]));
		$total = $q->fetch_assoc();
		

		/*
		//$q = mysqli_query($sql,"SELECT COUNT(DISTINCT s.`member_id`) AS members FROM `amember_members_seenday` AS s LEFT JOIN `amember_payments` AS p ON m.member_id=p.member_id WHERE p.product_id=211 AND s.day BETWEEN '$from' AND '$to' AND m.last_elite_state=1");
		$q = mysqli_query($sql,"SELECT COUNT(DISTINCT s.`member_id`) AS members FROM `amember_members_seenday` AS s LEFT JOIN `amember_members_meta` AS m ON m.member_id=s.member_id WHERE s.day BETWEEN '$from' AND '$to' AND m.last_elite_state=1");
		$error = mysqli_error($sql);
		if ($error) die(json_encode(['error'=>$error]));
		$r1 = mysqli_fetch_assoc($q);
		*/

		die(json_encode(['from'=>$from,'to'=>$to,'members'=>intval($total['members']),'elites'=>intval($total['elites']),'dailies'=>$rdailies]));
	}
	elseif ($metric=="currently_elite") {
		$from = Telemetry::date_add_dashes($from);
		$to = Telemetry::date_add_dashes($to);

		Zygor_Amember::connect(); $sql = &Zygor_Amember::$sql;

		//$q = mysqli_query($sql,"SELECT count(m.`member_id`) as 'currently_elite' FROM `amember_members` AS m,`amember_payments` AS p WHERE m.member_id=p.member_id AND p.product_id=211 AND p.completed=1 AND p.expire_date>='$to'");
		$q = $sql->query("SELECT
			COUNT(p.`member_id`) AS 'elites'
			FROM `amember_payments` AS p
			WHERE p.`product_id`=211
			 AND p.`completed`=1
			 AND NOT (p.`begin_date`>CAST('$to' AS DATE)) AND NOT (p.`expire_date`<CAST('$from' AS DATE))
		");
		$error = $sql->error;
		if ($error) die(json_encode(['error'=>$error]));
		$r = $q->fetch_assoc();
		die(json_encode($r));
	}

	elseif ($metric=="never_loggedin") {
		$from = Telemetry::date_add_dashes($from);
		$to = Telemetry::date_add_dashes($to);

		Zygor_Amember::connect(); $sql = &Zygor_Amember::$sql;

		//$q = mysqli_query($sql,"SELECT count(m.`member_id`) as 'currently_elite' FROM `amember_members` AS m,`amember_payments` AS p WHERE m.member_id=p.member_id AND p.product_id=211 AND p.completed=1 AND p.expire_date>='$to'");
		$q = $sql->query("SELECT *
		FROM `amember_members` AS m
		WHERE m.`added` BETWEEN (CAST('$from' AS DATE)) AND (CAST('$to' AS DATE))
		 AND NOT EXISTS (SELECT s.`member_id` FROM amember_members_seenday AS s WHERE s.`member_id`=m.`member_id`)
		");
		$error = $sql->error;
		if ($error) die(json_encode(['error'=>$error]));
		$r = $q->fetch_assoc();
		die(json_encode($r));
	}

} catch (Exception $e) {
	die(json_encode(['error'=>$e->getMessage()]));
}