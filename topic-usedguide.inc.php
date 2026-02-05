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
			'function'=>function($line,&$alldata,&$mydata) {
				$mydata[$line['guide']]++;
			},
			// without 'action' and maybe 'table' this cruncher just collects data in $mydata which isn't used anyway
		]
	],
	'endpoint'=>[

	],
	'view'=>[
		'title'=>"Used Guides",
		'printer'=>function() {
			ob_start();
			?>
				<form>
				Flavor:
				<?php $i=0; $name="flavour"; foreach (['wow'=>"Retail",'wow-classic'=>"Classic",'wow-classic-tbc'=>"MoP"] as $v=>$l): $i++; ?>
				<input type="radio" data-cbr name="<?=$name?>" id="cbug-<?=$name?>-<?=$i?>" value="<?=$v?>" <?=$i==1?"checked":""?>><label data-flavor="<?=$v?>" for="cbug-<?=$name?>-<?=$i?>"><?=$l?></label>
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
					function runUsedGuide() {
						if (CHART1) CHART1.destroy()
						$.get(ENDPOINT+"?"+new URLSearchParams({
							metric:"usedguide",
							from:getDate("from"),
							to:getDate("to"),
							limit:50,
							flavour:$("#usedguide input[name=flavour]:checked").val(),
							grouptypes:$("#usedguide [data-field=grouptypes]").val(),
							type:$("#usedguide [data-field=type]").val(),
							find:$("#usedguide [data-field=find]").val(),
							...$("#usedguide [data-field=meta]").data("meta")
						}))
							.then(d=>showUsedGuide(d))
							.fail(d=>console.error(d))
					}
					function showUsedGuide(data) {
						console.log(data)
						if (data.metric!="usedguide") return console.error("Wrong metric in showUsedGuide:",data.metric)
						if (CHART1) CHART1.destroy()
						const ctx = document.getElementById('chart_usedguide');
						CHART1 = new Chart(ctx, {
							type: 'pie',
							data: {
								labels: Object.keys(data.usedguides).map(s=>`${s}: ${data.usedguides[s]}`),
								datasets: [{
									label: '#',
									data: Object.values(data.usedguides),
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
