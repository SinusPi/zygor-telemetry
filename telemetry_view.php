<?php
$CFG = include(__DIR__."/config-view.inc.php");
?>
<html>
	<head>
		<link rel="stylesheet" href="/includes/jquery-ui-1.13.2.custom/jquery-ui.min.css">
		<script src="/includes/jquery-ui-1.13.2.custom/external/jquery/jquery.js"></script>
		<script src="/includes/jquery-ui-1.13.2.custom/jquery-ui.min.js"></script>
		<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
		<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
		<script src="/includes/jquery-ui-1.13.2.custom/jquery-ui.min.js"></script>
		<style>
			body { font-family:"Tahoma"; }
			#main { display:flex; gap:0.5em; }
			#left { width:15em; }

			.ui-datepicker { width:inherit !important; }
			.ui-datepicker th { padding:0 0; }
			.ui-datepicker td a { border:none !important; }
			.ui-datepicker a.ui-state-highlight { background:#f6f6f6 !important; outline:1px solid #007fff; color:#454545 !important; }
			.ui-datepicker a.ui-state-active { background:#007fff !important; color:white !important; }
			.ui-datepicker span.ui-state-default { border:none !important; }

			#charts {
				display:flex;
				flex-wrap:wrap;
				width:100%;
			}
			.overviewbox { width:25%; height:50vh; overflow:auto; }

			#searches_table { max-height:20em; }
			table.searches td.searchq { width:10em; overflow:hidden; overflow-wrap:anywhere;}
			table.searches tr.A td { color:#00A; }
			table.searches tr.H td { color:#B00; }

			.ui-state-active[data-flavor=wow],
			.ui-widget-content .ui-state-active,
			.ui-widget-header .ui-state-active,
			a.ui-button:active, .ui-button:active[data-flavor=wow],
			.ui-button.ui-state-active:hover[data-flavor=wow] {
				background-color:red;
			}
			.ui-button[data-flavor=wow] {
				background-color:#ffaaaa;
			}
			.ui-state-active[data-flavor=wow-classic],
			.ui-widget-content .ui-state-active,
			.ui-widget-header .ui-state-active,
			a.ui-button:active, .ui-button:active[data-flavor=wow-classic],
			.ui-button.ui-state-active:hover[data-flavor=wow-classic] {
				background-color:#0044ff;
			}
			.ui-button[data-flavor=wow-classic] {
				background-color:#aaccff;
			}
			.ui-state-active[data-flavor=wow-classic-tbc],
			.ui-widget-content .ui-state-active,
			.ui-widget-header .ui-state-active,
			a.ui-button:active, .ui-button:active[data-flavor=wow-classic-tbc],
			.ui-button.ui-state-active:hover[data-flavor=wow-classic-tbc] {
				background-color:#00eeff;
			}
			.ui-button[data-flavor=wow-classic-tbc] {
				background-color:#aaf8ff;
			}

			.lds-dual-ring {
				display: block;
				width: 80px;
				height: 80px;
				overflow: hidden;
				margin:10px;
			}
			.lds-dual-ring:after {
				content: " ";
				display: block;
				width: 64px;
				height: 64px;
				margin: 0px;
				border-radius: 50%;
				border: 6px solid #ddd;
				border-color: #ddd transparent #ddd transparent;
				animation: lds-rotate 1.2s linear infinite;
				overflow: hidden;
			}
			.lds-small-ring {
				display: inline-block;
				width: 18px;
				height: 18px;
				overflow: hidden;
				margin:0px;
			}
			.lds-small-ring:after {
				content: " ";
				display: inline-block;
				width: 0px;
				height: 0px;
				margin: 3px;
				border-radius: 100%;
				border: 6px solid #000;
				border-color: #06f #ddd #06f #ddd;
				animation: lds-rotate 1.2s linear infinite;
				overflow: hidden;
			}
			@keyframes lds-rotate {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}			
			

		</style>
	</head>
	<script>

		var CLASSIC_EXP = "MoP-Classic"

		var META={
			retail:{
				color:"red",
				color_classic:"#dd0066",
			},
			classic:{
				color:"#0044ff",
				color_old:"#0066ff",
			},
			old:{
				color:"#00eeff",
				color_retail:"#44bbee",
			},
		}
		
		function getDate(dir) {
			let date = $(`[data-datedir=${dir}]`).val()
			if (date.indexOf("/")>-1) date=date.substr(6,4)+date.substr(0,2)+date.substr(3,2) // convert mm-dd-yyyy to yyyy-mm-dd
			return date
		}

		//var INITING=true
		//$( "[data-datedir]" ).datepicker() //.change(e=>{$t=$(e.target);setDate($t.data("datedir"),$t.val())})
		//$( "[data-field]" ).change(e=>{$t=$(e.target);setField($t.data("field"),$t.val())})
		window.metrics = []

		function getDatev( element ) {
			return $(element).datepicker("getDate")
			var date;
			try {
				date = $.datepicker.parseDate( dateFormat, $(element).datepicker("getDate") );
			} catch( error ) {
				date = null;
			}
			return date;
		}

		var ENDPOINT = "telemetry_endpoint.php";
	</script>
	<body>
		<div id="main">
			<div id="left">
				From:
				<div data-picker id="date_from"></div>
				<br>
				To:
				<div data-picker id="date_to"></div>
				<input type="hidden" data-datedir="from"/>
				<input type="hidden" data-datedir="to"/>
			</div>

			<div id="charts">

				<div id="simples" class="overviewbox">
					<h2>User counts:</h2>
					<div id="ecb">Total Potential Users <sup title='users seen in 3 years before "to" date'>?</sup>: <span data-value=ecb></span></div>
					<div id="te">Total Elite <sup title='users having Elite status in the period'>?</sup>: <span data-value=te></span></div>
					<div>Installs total: <span data-value="inst-total"></span></div>
					<ol>
						<li>Retail <span data-value="inst-retail"></span>
						<li>WotLK <span data-value="inst-old"></span>
						<li>Classic <span data-value="inst-classic"></span>
					</ol>
					<script>
					{
						let thisScript=document.scripts[document.scripts.length-1]
						let $thisDiv
						async function run() {
							let $target,dfrom,dto

							dfrom = getDate("from")
							dto = getDate("to")
							dto_minus_3y = (parseInt(dto.substr(0,4))-3)+dto.substr(4)

							{
								const $target = $thisDiv.find("[data-value=ecb]")
								$target.empty().addClass("lds-small-ring")
								$.get(ENDPOINT+"?"+new URLSearchParams({
									metric:"users_seen",
									from:dto_minus_3y,
									to:dto,
								}))
									.then(data=>$target.html(data.members))
									.fail(err=>$target.html(`ERROR: ${err.status} "${err.responseText}"`))
									.always(d=>$target.removeClass("lds-small-ring"))
							}
						
							{
								const $target = $thisDiv.find("[data-value=te]")
								$target.empty().addClass("lds-small-ring")
								$.get(ENDPOINT+"?"+new URLSearchParams({
									metric:"currently_elite",
									from:dfrom,
									to:dto,
								}))
									.then(data=>$target.html(data.elites??"ERROR"))
									.fail(err=>$target.html(`ERROR: ${err.status} "${err.responseText}"`)+console.error(err))
									.always(d=>$target.removeClass("lds-small-ring"))
							}
						
							{
								const $targetr = $thisDiv.find("[data-value=inst-retail]")
								const $targetw = $thisDiv.find("[data-value=inst-old]")
								const $targetc = $thisDiv.find("[data-value=inst-classic]")
								const $targett = $thisDiv.find("[data-value=inst-total]")
								const $targets = $targetr.add($targetw).add($targetc).add($targett)
								$targets.empty().addClass("lds-small-ring")
								$.get(ENDPOINT+"?"+new URLSearchParams({
									metric:"multiflavors",
									from:dfrom,
									to:dto,
								}))
									.then(data=>{
										if (!data.multiflavors) throw {status:200,responseText:"BAD RESPONSE"}
										if (data.multiflavors.length===0) { console.log("Users multiflavors: ",data); throw {status:200,responseText:"NO DATA"}; }
										let dmf_b = data.multiflavors.b
										let dmf_e = data.multiflavors.e
										let dmf_b_t = dmf_b['Retail']+dmf_b[CLASSIC_EXP]+dmf_b['Classic']+2*(dmf_b['Retail+Classic']+dmf_b['Retail+OLD']+dmf_b['Classic+OLD'])+3*dmf_b['All']
										let dmf_e_t = dmf_e['Retail']+dmf_e[CLASSIC_EXP]+dmf_e['Classic']+2*(dmf_e['Retail+Classic']+dmf_e['Retail+OLD']+dmf_e['Classic+OLD'])+3*dmf_e['All']
										let r = dmf_b['Retail']+dmf_b['Retail+Classic']+dmf_b['Retail+OLD']+dmf_b['All'] + dmf_e['Retail']+dmf_e['Retail+Classic']+dmf_e['Retail+OLD']+dmf_e['All']
										let w = dmf_b['Classic+OLD']+dmf_b[CLASSIC_EXP]+dmf_b['Retail+OLD']+dmf_b['All'] + dmf_e['Classic+OLD']+dmf_e[CLASSIC_EXP]+dmf_e['Retail+OLD']+dmf_e['All']
										let c = dmf_b['Retail+Classic']+dmf_b['Classic']+dmf_b['Classic+OLD']+dmf_b['All'] + dmf_e['Retail+Classic']+dmf_e['Classic']+dmf_e['Classic+OLD']+dmf_e['All']
										$targetr.html(`(${r}) / (${parseInt(100*r/(dmf_b_t+dmf_e_t))}%)`)
										$targetw.html(`(${w}) / (${parseInt(100*w/(dmf_b_t+dmf_e_t))}%)`)
										$targetc.html(`(${c}) / (${parseInt(100*c/(dmf_b_t+dmf_e_t))}%)`)
										$targett.html(`${dmf_b_t+dmf_e_t}`)
									})
									.fail(err=>$targets.html(`ERROR: ${err.status}: ${err.responseText}`))
									.always(d=>$targets.removeClass("lds-small-ring"))
							}
						}
						window.metrics.push({run})
						$(()=>{
							$thisDiv=$(thisScript.parentElement)
						})
					}
					</script>
				</div>


				<div id="flavorsinstalled" class="overviewbox">
					<h2>Flavors installed:</h2>
					<form>
						<div>
							Mode:
							<?php $i=0; $name="mode"; foreach (['average'=>"Overview",'daily'=>"Daily",'weekly'=>"Weekly avg"] as $v=>$l): $i++; ?>
							<input type="radio" data-cbr name="<?=$name?>" id="cbf-<?=$name?>-<?=$i?>" value="<?=$v?>" <?=$i==1?"checked":""?>><label for="cbf-<?=$name?>-<?=$i?>"><?=$l?></label>
							<?php endforeach; ?>
						</div>
						<div>
							Member:
							<?php $i=0; $name="memb"; foreach (['b'=>"Basic",'e'=>"Elite"] as $v=>$l): $i++; ?>
							<input type="radio" data-cbr name="<?=$name?>" id="cbf-<?=$name?>-<?=$i?>" value="<?=$v?>" <?=$i==1?"checked":""?>><label for="cbf-<?=$name?>-<?=$i?>"><?=$l?></label>
							<?php endforeach; ?>
						</div>
					</form>
					<div class="desc"></div>
					<canvas id="chart_flavorsinstalled"></canvas>

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
							$( "#usedguide :input" ).change(runUsedGuide)
						})
					</script>

				</div>










				<div id="usedguide" class="overviewbox">
					<h2>Used guides:</h2>
					<form>
					Flavor:
					<?php $i=0; $name="flavour"; foreach (['wow'=>"Retail",'wow-classic'=>"Classic",'wow-classic-tbc'=>CLASSIC_EXP] as $v=>$l): $i++; ?>
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
						let CHART2
						let dmf
						function runFlavorsInstalled() {
							if (CHART2) CHART2.destroy()
							const ctx = document.getElementById('chart_flavorsinstalled');
							CHART2 = new Chart(ctx, {
								data: {
									labels: [],
									datasets: []
								},
								type: 'pie',
							})
							CHART2.options = {
								plugins: {
									tooltip: {
										callbacks: {
											title: (tt)=>{
												//tt[0].chart.setActiveElements([{datasetIndex:tt[0].datasetIndex,index:tt[0].dataIndex+1},{datasetIndex:tt[0].datasetIndex,index:tt[0].dataIndex}])
												let id = tt[0].datasetIndex+","+tt[0].dataIndex
												switch (id) {
													case "1,0": case "2,0": case "3,0": case "1,1": case "2,1": case "1,5": return "Retail only"
													case "3,1": return "Retail+Classic"
													case "3,2": return "Classic only"
													case "3,3": return "Classic+"+CLASSIC_EXP
													case "2,3": case "2,4": case "3,4": return CLASSIC_EXP+" only"
													case "2,5": case "3,5": return "Retail+"+CLASSIC_EXP
													case "5,0": return "All Flavors"
													default: return false
												}
											},
											label: (tt)=>{
												let id = tt.datasetIndex+","+tt.dataIndex
												let dmf=tt.chart.dmf
												let num_users = (()=>{switch (id) {
													case "1,0": case "2,0": case "3,0": case "1,1": case "2,1": case "1,5": return dmf['Retail']
													case "3,1": return dmf["Retail+Classic"]
													case "3,2": return dmf["Classic"]
													case "3,3": return dmf["Classic+OLD"]
													case "2,3": case "2,4": case "3,4": return dmf["OLD"]
													case "2,5": case "3,5": return dmf["Retail+OLD"]
													case "5,0": return dmf["All"]
													default: return false
												}})()
												if (num_users===false) return ""
												return `${num_users} users (${parseInt(num_users/tt.chart.total*100)}%)`
											}
										}
									},
									datalabels: {
										color: "black",
										textAlign: "center",
										formatter: (val,ctx)=>{
											return (ctx.dataset.label || ["Retail only","Retail+Classic","Classic only","Classic+"+CLASSIC_EXP,CLASSIC_EXP+" only","Retail+"+CLASSIC_EXP][ctx.dataIndex])+"\n"+val
										},
									}
								}
							}
							CHART2.data.datasets[0] = {
								label: '',
								data: [0,0,0,0,0,0,0],
								backgroundColor: [ META.retail.color, META.classic.color, META.old.color ],
								offset: [ 0, 10, 0, 10, 0, 10 ],
								borderWidth: 0,
								weight:0,
								datalabels:{display:false},
							}
							CHART2.data.datasets[1] = {
								label: "",
								data: [0,0,0,0,0,0],
								backgroundColor: [ META.retail.color, META.retail.color, "white", "white", "white", META.retail.color ],
								//offset: [ 0, 10, 0, 10, 0, 10 ],
								borderWidth: 0,
								weight:0.2,
								datalabels:{display:false}
							}
							CHART2.data.datasets[2] = {
								label: '',
								data: [0,0,0,0,0,0],
								backgroundColor: [ META.retail.color, META.retail.color, "white", META.old.color, META.old.color, META.old.color_retail ],
								//offset: [ 0, 1, 2, 3, 4, 5 ],
								borderWidth: 0,
								weight:0.2,
								datalabels:{display:false},
							}
							CHART2.data.datasets[3] = {
								label: '',
								data: [0,0,0,0,0,0],
								backgroundColor: [ META.retail.color, META.retail.color_classic, META.classic.color, META.classic.color_old, META.old.color, META.old.color_retail ],
								//offset: [ 0, 10, 0, 10, 0, 10 ],
								borderWidth: 0,
							}
							CHART2.data.datasets[4] = {
								data:[],
								weight: 0.05
							}
							CHART2.data.datasets[5] = {
								label: 'All',
								data: [0],
								backgroundColor: [ "gold" ],
								weight: 1,
								borderWidth: 0
							}

							let mode = $("#flavorsinstalled input[name=mode]:checked").val()
							$.get(ENDPOINT+"?"+new URLSearchParams({
								metric:["average","total"].includes(mode)?"multiflavors":"flavorsinstalled",
								from:getDate("from"),
								to:getDate("to"),
								mode:mode,
							}))
								.then(d=>showFlavorsInstalled(d))
								.fail(d=>console.error(d))
						}
						var lastFlavors=null
						function showFlavorsInstalled(data) {
							if (!data) data=lastFlavors; else lastFlavors=data
							if (data.mode=="average") {
								let memb = $("#flavorsinstalled input[name=memb]:checked").val()
								let dmf = data.multiflavors[memb]
								CHART2.dmf = dmf
								CHART2.total = dmf['Retail']+dmf['Retail+Classic']+dmf['Classic']+dmf['Classic+OLD']+dmf['OLD']+dmf['Retail+OLD']+dmf['All']
								let total_inst = dmf['Retail']+2*dmf['Retail+Classic']+dmf['Classic']+2*dmf['Classic+OLD']+dmf['OLD']+2*dmf['Retail+OLD']+3*dmf['All']
								CHART2.data.labels = [
									'Retail insts: '+(dmf['Retail']+dmf['Retail+Classic']+dmf['Retail+OLD']+dmf['All']),
									'Classic insts: '+(dmf['Retail+Classic']+dmf['Classic']+dmf['Classic+OLD']+dmf['All']),
									'WOTLK insts: '+(dmf['Classic+OLD']+dmf['OLD']+dmf['Retail+OLD']+dmf['All'])
								]
									
								CHART2.data.datasets[1].data=[dmf['Retail'],dmf['Retail+Classic'],dmf['Classic'],dmf['Classic+OLD'],dmf['OLD'],dmf['Retail+OLD']]
								CHART2.data.datasets[2].data=[dmf['Retail'],dmf['Retail+Classic'],dmf['Classic'],dmf['Classic+OLD'],dmf['OLD'],dmf['Retail+OLD']]
								CHART2.data.datasets[3].data=[dmf['Retail'],dmf['Retail+Classic'],dmf['Classic'],dmf['Classic+OLD'],dmf['OLD'],dmf['Retail+OLD']]
								CHART2.data.datasets[5].data=[dmf['All']]					

								CHART2.update()

								$("#flavorsinstalled .desc").html(`Values shown are daily averages over the period selected.<br>Users on average: <b>${CHART2.total}</b>.<br>Total installations: <b>${total_inst}</b>.`)
								//$("#flavorsinstalled #bottomline").show().html("Retail ")
							} else if (data.mode=="weekly" || data.mode=="daily") {
								let dfi=data.flavorsinstalled
								CHART2 = new Chart(ctx, {
									type: 'bar',
									data: {
										labels: Object.keys(dfi),
										datasets: [
											{
												label: 'Retail',
												data: Object.values(dfi).map(f=>f.ZygorGuidesViewer),
												backgroundColor: META.retail.color,
											},
											{
												label: 'Classic',
												data: Object.values(dfi).map(f=>f.ZygorGuidesViewerClassic),
												backgroundColor: META.classic.color,
											},
											{
												label: CLASSIC_EXP,
												data: Object.values(dfi).map(f=>f.ZygorGuidesViewerClassicTBC),
												backgroundColor: META.old.color,
											},
										],
									},
									options: {
										scales: {
											x: {
												//stacked:true
											},
											y: {
												//stacked:true
											}
										}
									}
								})
							}
						}
						window.metrics.push({run:runFlavorsInstalled,show:showFlavorsInstalled})
						$(e=>{
							$( "#flavorsinstalled :input[name=mode]" ).change(runFlavorsInstalled)
							$( "#flavorsinstalled :input[name=memb]" ).change(_=>showFlavorsInstalled())
						})
					</script>
				</div>








				<div id="searches" class="overviewbox">
					<h2>Search keywords:</h2>
					<form>
					Faction: <select name="faction" data-field="faction">
						<option value="">Any</option>
						<option value="A">Alliance</option>
						<option value="H">Horde</option>
					</select><br>
					Flavor: 
					<?php $i=0; $name="flavour"; foreach (['wow'=>"Retail",'wow-classic'=>"Classic",'wow-classic-tbc'=>"WotLK"] as $v=>$l): $i++; ?>
					<input type="radio" data-cbr name="<?=$name?>" id="cbs-<?=$name?>-<?=$i?>" value="<?=$v?>" <?=$i==1?"checked":""?>><label data-flavor="<?=$v?>" for="cbs-<?=$name?>-<?=$i?>"><?=$l?></label>
					<?php endforeach; ?>
					<br>
					Sort: <select data-field="sort">
						<option value="query" selected>Query</option>
						<option value="count">Popularity</option>
						<option value="results"># Results</option>
					</select><br>
					</form>
					<canvas id="chart_search"></canvas>
					<div id="searches_table" style="overflow-y:scroll;"></div>
					<script>
						let CHART3
						function runSearches() {
							if (CHART3) CHART3.destroy()
							let $t = $("#searches_table")
							$t.empty().addClass("lds-dual-ring")
							$.get(ENDPOINT+"?"+new URLSearchParams({
								metric:"search",
								from:getDate("from"),
								to:getDate("to"),
								limit:50,
								flavour:$("#searches input[name=flavour]:checked").val(),
								faction:$("#searches [data-field=faction]").val(),
								sort:$("#searches [data-field=sort]").val()
							}))
								.then(d=>showSearches(d))
								.fail(d=>console.error(d))
								.always(d=>$t.removeClass("lds-dual-ring"))
						}
						function showSearches(data) {
							console.log(data)
							if (data.metric!="search") return console.error("Wrong metric in showSearches:",data.metric)
							if (CHART3) CHART3.destroy()
							const ctx = document.getElementById('chart_search');
							if (false) {
								// search keywords are WAY too numerous to pie.
								CHART3 = new Chart(ctx, {
									type: 'pie',
									data: {
										labels: Object.keys(data.search),
										datasets: [{
											label: '#',
											data: Object.values(data.search),
											borderWidth: 1
										}]
									},
									options: {
									}
								})
							} else {
								$(ctx).hide()
								let $t = $("#searches_table")
								$t.empty()

								let t = "<table class='searches'>"
								t += "<tr><th>query<th>pop<th>results"
								for (d of data.search) {
									t += `<tr class="${d.f}"><td class='searchq'>${d.q}</td><td>${d.c}</td><td>${d.r}</td></tr>`
								}
								t += "</table>"
								$t.append(t)
							}
						}
						window.metrics.push({run:runSearches,show:showSearches})
						$(()=>{
							$( "#searches :input" ).change(runSearches)
						})
					</script>
				</div>

				<div id="users_created" class="overviewbox">
					<h2>Users created:</h2>
					<div class="result"></div>
					<script>
						let thisScript=document.scripts[document.scripts.length-1]
						let $thisDiv
						function runUsersCreated() {
							if (CHART3) CHART3.destroy()
							let $t = $thisDiv.find(".result")
							$t.empty().addClass("lds-dual-ring")
							$.get(ENDPOINT+"?"+new URLSearchParams({
								metric:"users_created",
								from:getDate("from"),
								to:getDate("to"),
							}))
								.then(d=>showUsersCreated(d))
								.fail(d=>console.error(d))
								.always(d=>$t.removeClass("lds-dual-ring"))
						}
						function showUsersCreated(data) {
							console.log(data)
							let $t = $thisDiv.find(".result")
							$t.html(data.users_created)
						}
						window.metrics.push({run:runUsersCreated,show:showUsersCreated})
						$(()=>{
							$thisDiv=$(thisScript.parentElement)
						})
					</script>
				</div>


				<div id="users_seen" class="overviewbox">
					<h2>Users seen: <sup title="Users connecting with the Client, having Elite status at the day of the connection.">?</sup></h2>
					<div class="result"></div>
					<script>
					{
						let thisScript=document.scripts[document.scripts.length-1]
						let $thisDiv,$t
						function runUsersSeen() {
							if (CHART3) CHART3.destroy()
							$t.empty().addClass("lds-dual-ring")
							$.get(ENDPOINT+"?"+new URLSearchParams({
								metric:"users_seen",
								elites:true,
								from:getDate("from"),
								to:getDate("to"),
							}))
								.then(d=>showUsersSeen(d))
								.fail(d=>console.error(d))
								.always(d=>$t.removeClass("lds-dual-ring"))
						}
						function showUsersSeen(data) {
							let $t = $thisDiv.find(".result")
							if (data.error) return $t.html(data.error)
							$t.html("Seen: "+(data.members)+"; elites: "+data.elites + "<br>" + data.dailies?.map(d=>d.day+": "+d.members+" ("+d.elites+" "+(parseInt(d.elites/d.members*100))+"% elite)").join("<br>"))
						}
						window.metrics.push({run:runUsersSeen,show:showUsersSeen})
						$(()=>{
							$thisDiv=$(thisScript.parentElement)
							$t = $thisDiv.find(".result")
						})
					}
					</script>
				</div>


				<div id="currently_elite" class="overviewbox">
					<h2>Elite Users: <sup title="Users having a subscription overlapping the date range.">?</sup></h2>
					<div class="result"></div>
					<script>
					{
						let thisScript=document.scripts[document.scripts.length-1]
						let $thisDiv
						function runCurrentlyElite() {
							if (CHART3) CHART3.destroy()
							let $t = $thisDiv.find(".result")
							$t.empty().addClass("lds-dual-ring")
							$.get(ENDPOINT+"?"+new URLSearchParams({
								metric:"currently_elite",
								from:getDate("from"),
								to:getDate("to"),
							}))
								.then(d=>showCurrentlyElite(d))
								.fail(d=>console.error(d))
								.always(d=>$t.removeClass("lds-dual-ring"))
						}
						function showCurrentlyElite(data) {
							let $t = $thisDiv.find(".result")
							$t.html("Elite: "+data.elites)
						}
						window.metrics.push({run:runCurrentlyElite,show:showCurrentlyElite})
						$(()=>{
							$thisDiv=$(thisScript.parentElement)
						})
					}
					</script>
				</div>


			</div>
		</div>
	




		<script>
			function runAll() {
				window.metrics.map(m=>m.run())
			}
			$(()=>{
				$("input[type=radio][data-cbr]").checkboxradio()

				Chart.register(ChartDataLabels)
				$( "[data-datedir]" ).datepicker() //.change(e=>{$t=$(e.target);setDate($t.data("datedir"),$t.val())})
				$( "[data-datedir=from]" ).datepicker("setDate","-7")
				$( "[data-datedir=to]" ).datepicker("setDate","now")
				$( "[data-datedir]").change(runAll)
				let
					from = $("#date_from").datepicker({altField:"[data-datedir=from]",altFormat:"yymmdd", changeYear:true, maxDate:"0",defaultDate:"-7"}).on( "change", function() {   to.datepicker( "option", "minDate", getDatev( this ) ); runAll() }),
					to   = $("#date_to")  .datepicker({altField:"[data-datedir=to]"  ,altFormat:"yymmdd", changeYear:true, minDate:"-7",maxDate:"0",defaultDate:"0"}).on( "change", function() { from.datepicker( "option", "maxDate", getDatev( this ) ); runAll() })

				runAll()
			})
			
		</script>

	</body>
</html>