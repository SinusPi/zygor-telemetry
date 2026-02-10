<?php
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
			'action' => "insert",
			'table' => "usedguide",
			'table_schema' => "
				CREATE TABLE `<TABLE>` (
					`event_id` int(11) NOT NULL,
					`flavnum` int(1) NOT NULL,
					`time` int(11) NOT NULL,
					`guide` varchar(30) NOT NULL,
					`type` varchar(10) NOT NULL,
					PRIMARY KEY (`event_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
				"
			// without 'action' and maybe 'table' this cruncher just collects data in $mydata which isn't used anyway
		]
	],
	'endpoint'=>[
		'queryfunc'=>function($from,$to,$flavour) {
			
			$where = TelemetryEndpoint::get_where_from_to_flavour($from,$to,$flavour);
			if ($_REQUEST['type']) $where[] = "type='".Telemetry::$db->real_escape_string($_REQUEST['type'])."'";
			if ($_REQUEST['find']) $where[] = "guide LIKE '%".Telemetry::$db->real_escape_string($_REQUEST['find'])."%'";
			$order = ($_REQUEST['sort']=="name") ? "guide ASC" : "count DESC";
			$limit = ($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 100000;

			$query = TelemetryEndpoint::db_qesc("SELECT COUNT(*) AS count, guide FROM usedguide WHERE ".join(" AND ",$where)." GROUP BY guide  ORDER BY $order  LIMIT $limit");
			$result = $query->fetch_all(MYSQLI_ASSOC);

			return array_combine(array_column($result, 'guide'), array_column($result, 'count'));
		}
	],
	'view'=>[
		'title'=>"Used Guides",
		'class'=>"overviewbox",
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
];
