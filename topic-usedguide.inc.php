<?php
use \Zygor\Telemetry\Telemetry;
use \Zygor\Telemetry\Logger;

return [
	'scraper'=>[
		'input'=>"sv",
		'extraction_lua'=><<<ENDLUA
			if %ZGVS_VAR%.char then
				for char,d in pairs(%ZGVS_VAR%.char) do
					if d.guidestephistory then
						for guide,gd in pairs(d.guidestephistory) do
							if count>0 then print(",") end
							print(('{"type":"usedguide","time":%d,"guide":\"%s\"}'):format(gd.lasttime or 0,guide:gsub('"','\''):gsub('\\\\','/')))
							count=count+1
						end
					end
				end
			end
ENDLUA
		,
		//'output'=>"events" // everything goes into events, this is just a visual reminder
	],
	'crunchers'=>[
		[
			'table' => "usedguide",
			'table_schema' => [
				'1'=>"CREATE TABLE `<TABLE>` (
					`event_id` int(11) NOT NULL,
					`flavnum` int(1) NOT NULL,
					`time` int(11) NOT NULL,
					`guide` varchar(30) NOT NULL,
					`type` varchar(10) NOT NULL,
					PRIMARY KEY (`event_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
				",
				'1>2' =>"ALTER TABLE `<TABLE>` ADD INDEX `time` (`time`)",
				'2>3' =>"ALTER TABLE `<TABLE>` ADD INDEX `flavnum_time` (`flavnum`,`time`)",
				'3>4' =>"ALTER TABLE `<TABLE>` CHANGE `type` `type` ENUM('LEVELING','LOREMASTER','ACHIEVEMENTS','TITLES','PETSMOUNTS','GOLD','REPUTATIONS','DAILIES','DUNGEONS','GEAR','SHARED','PROFESSIONS','EVENTS') DEFAULT NULL",
				'4>5' =>"ALTER TABLE `<TABLE>` CHANGE `guide` `guide` varchar(100) NOT NULL",
			],
			'action' => "insert", // run once per source event, output goes into the specified table
			'function' => function($line) {
				$unpacked = json_decode($line["data"], true);
				$line = $unpacked + $line;

				$values = [
					"event_id" => $line["id"],
					"flavnum" => $line["flavnum"],
					"time" => $line["time"],

					"guide" => $line["guide"],
					"type" => substr($line["guide"],0,strpos($line["guide"],"/")),
				];

				return $values;
			},
		],

		[
			// on each run, this cruncher will tally up the usedguide events of the previous day into a separate table,
			// grouped by flavor and guide type. This allows for quick retrieval of daily stats without having to query
			// the raw events table which can be huge.
			'name'=>"daily used guide tallies",
			'table' => "usedguide_daily",
			'table_schema' => [
				'1'=>"CREATE TABLE `<TABLE>` (
					`day` DATE NOT NULL DEFAULT '1970-01-01',
					`flavnum` int(1) NOT NULL,
					`type` varchar(12) NOT NULL ENUM('LEVELING','LOREMASTER','ACHIEVEMENTS','TITLES','PETSMOUNTS','GOLD','REPUTATIONS','DAILIES','DUNGEONS','GEAR','SHARED','PROFESSIONS','EVENTS') DEFAULT NULL,
					`guide` varchar(100) NOT NULL,
					`count` unsigned smallint NOT NULL DEFAULT 0,
					PRIMARY KEY (`day`,`flavnum`,`type`)

				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
				",
			],
			'action' => "run", // just run the code once per execution
			'function' => function() {
				$newest_id = Telemetry::$db->query_one("SELECT MAX(`event_id`) FROM `usedguide`");
				$batch_size = 1000;
				do {
					Telemetry::$db->begin_transaction();
					$last_id = Telemetry::db_get_var("cruncher_usedguide_daily_last_id",0);
					$last_id_batch = $last_id + $batch_size;
					if ($last_id >= $newest_id) break; // nothing new to process
					Telemetry::$db->query(
						"INSERT INTO usedguide_daily (`day`, `flavnum`, `type`, `guide`, `count`)
							SELECT DAY(FROM_UNIXTIME(time)), flavnum, type, guide, count(*) as count
								FROM usedguide
								WHERE event_id > $last_id 
								  AND event_id <= $last_id_batch
								GROUP BY flavnum, guide

							ON DUPLICATE KEY UPDATE count = count + VALUES(count)
								"
					);
					$last_id = $last_id_batch;
					Telemetry::db_set_var("cruncher_usedguide_daily_last_id", $last_id);
					Telemetry::$db->commit();
					Logger::vlog("Cruncher 'daily used guide tallies' processed events up to ID $last_id (".($newest_id - $last_id)." left)");
				} while ($last_id_batch < $newest_id);
			},
			// without 'action' and maybe 'table' this cruncher just collects data in $mydata which isn't used anyway
		]
	],
	'views'=>[
		[
			'name'=>"most used guide types",
			'description'=>"Shows the most used guides in the selected time period and flavor. You can filter by guide type (leveling, dailies, achievements, etc) and search for specific guide names.",
			'parameters'=>[
				'from'=>['type'=>"date",'optional'=>false,'default'=>"2020-01-01"],
				'to'=>['type'=>"date",'optional'=>false,'default'=>"now"],
				'type'=>['type'=>"select",'options'=>["LEVELING","DAILIES","ACHIEVEMENTS","TITLES","REPUTATION","PETS","MOUNTS","PROFESSIONS"],'optional'=>true,'default'=>""],
			],
			'query'=>function($from,$to,$flavour) {
				$where = TelemetryEndpoint::get_where_from_to_flavour($from,$to,$flavour);
				if ($_REQUEST['type']) $where[] = "type='".Telemetry::$db->escape($_REQUEST['type'])."'";
				if ($_REQUEST['find']) $where[] = "guide LIKE '%".Telemetry::$db->escape($_REQUEST['find'])."%'";
				$order = ($_REQUEST['sort']=="name") ? "guide ASC" : "count DESC";
				$limit = ($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 100000;

				$query = Telemetry::$db->query("SELECT COUNT(*) AS count, guide FROM usedguide WHERE ".join(" AND ",$where)." GROUP BY guide  ORDER BY $order  LIMIT $limit");
				$result = $query->fetch_all(MYSQLI_ASSOC);

				return array_combine(array_column($result, 'guide'), array_column($result, 'count'));
			},
			'display'=>"table"
		],
		[
			'name'=>"old used guides code",
			'description'=>"This is the old code for used guides",
			'parameters'=>[],
			'query'=>function() {
				return [];
			},
			'class'=>"overviewbox",
			'display'=>"printer",
			'printer'=>function() {
				ob_start();
				$name = "usedguide";
				?>
					<form>
					Flavor:
					<?php $i=0; $label="flavour"; foreach (['wow'=>"Retail",'wow-classic'=>"Classic",'wow-classic-tbc'=>"MoP"] as $v=>$l): $i++; ?>
					<input type="radio" data-cbr name="<?=$label?>" id="cbug-<?=$label?>-<?=$i?>" value="<?=$v?>" <?=$i==1?"checked":""?>><label data-flavor="<?=$v?>" for="cbug-<?=$label?>-<?=$i?>"><?=$l?></label>
					<?php endforeach; ?>
					<br>
					Group types: <select data-field="grouptypes">
						<option value="" selected>No</option>
						<option value="1">Yes</option>
					</select><br>
					Type: <select data-field="type">
						<option value="" selected>Any</option>
						<option value="LEVELING">Leveling</option>
						<option value="DAILIES">Dailies</option>
						<option value="ACHIEVEMENTS">Achievements</option>
						<option value="TITLES">Titles</option>
						<option value="REPUTATION">Reputation</option>
					</select><br>
					Find: <input type="text" data-field="find" data-formetric="usedguide">
					</form>
					<canvas id="chart_usedguide"></canvas>

					<script>
						let CHART1
						let DIV_ID = "#topic-<?= $name ?>"
						function runUsedGuide() {
							if (CHART1) CHART1.destroy()
							$.get(ENDPOINT+"?"+new URLSearchParams({
								topic:"usedguide",
								from:getDate("from"),
								to:getDate("to"),
								limit:50,
								flavour:$(`${DIV_ID} input[name=flavour]:checked`).val(),
								grouptypes:$(`${DIV_ID} [data-field=grouptypes]`).val(),
								type:$(`${DIV_ID} [data-field=type]`).val(),
								find:$(`${DIV_ID} [data-field=find]`).val(),
								...$(`${DIV_ID} [data-field=meta]`).data("meta")
							}))
								.then(d=>showUsedGuide(d))
								.fail(d=>console.error(d))
						}
						function showUsedGuide(data) {
							console.log(data)
							if (CHART1) CHART1.destroy()
							const ctx = document.getElementById('chart_usedguide');
							CHART1 = new Chart(ctx, {
								type: 'pie',
								data: {
									labels: Object.keys(data.data).map(s=>`${s}: ${data.data[s]}`),
									datasets: [{
										label: '#',
										data: Object.values(data.data),
										borderWidth: 1
									}]
								},
								options: {
								}
							})
						}
						window.metrics.push({run:runUsedGuide,show:showUsedGuide})
						$(()=>{
							$("#usedguide :input").change(runUsedGuide)
						})
					</script>
				<?php
				return ob_get_clean();
			}
		]
	]
];
