<?php
// Admin panel for telemetry management
$FLAVORS = [
	"wow" => "Retail",
	"wow-classic" => "Classic",
	"wow-classic-tbc" => "Classic MOP",
	"wow-classic-tbc-anniv" => "TBC Anniv"
];

$DATES = [
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"The Burning Crusade", 'date' => "January 16, 2007"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Wrath of the Lich King", 'date' => "November 13, 2008"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Cataclysm", 'date' => "December 7, 2010"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Mists of Pandaria", 'date' => "September 25, 2012"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Warlords of Draenor", 'date' => "November 13, 2014"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Legion", 'date' => "August 30, 2016"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Battle for Azeroth", 'date' => "August 13, 2018"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Shadowlands", 'date' => "November 23, 2020"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Dragonflight", 'date' => "November 28, 2022"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"The War Within", 'date' => "August 26, 2024"],
	[ 'type'=>"expansion", 'flavor'=>"wow", 'name'=>"Midnight", 'date' => "March 2, 2026"],

	[ 'type'=>"classic", 'flavor'=>"wow-classic", 'name'=>"Molten Core, Onyxia, Maraudon", 'phase'=> "Phase 1", 'date' => "December 12, 2024"],
	[ 'type'=>"classic", 'flavor'=>"wow-classic", 'name'=>"Dire Maul, Azuregos, Kazzak, Honor System, PvP Rank Rewards, , Alterac Valley, Warsong Gulch", 'phase'=> "Phase 2", 'date' => "January 9, 2025"],
	[ 'type'=>"classic", 'flavor'=>"wow-classic", 'name'=>"Blackwing Lair, Darkmoon Faire, Arathi Basin", 'phase'=> "Phase 3", 'date' => "March 20th, 2025"],
	[ 'type'=>"classic", 'flavor'=>"wow-classic", 'name'=>"Zul'Gurub, Green Dragons", 'phase'=> "Phase 4", 'date' => "May 1st, 2025"],
	[ 'type'=>"classic", 'flavor'=>"wow-classic", 'name'=>"AQ War Effort, AQ Raids, Tier 0.5, Loot Revamp", 'phase'=> "Phase 5", 'date' => "July 10th, 2025"],
	[ 'type'=>"classic", 'flavor'=>"wow-classic", 'name'=>"Naxxramas, Scourge Invasion, World PvP in Silithus/EPL", 'phase'=> "Phase 6", 'date' => "October 2nd, 2025"],

	[ 'type'=>"som", 'name'=>"Molten Core, Onyxia, Maraudon, PvP Honor System and Battlegrounds", 'phase'=> "Phase 1", 'date' => "16 November, 2021"],
	[ 'type'=>"som", 'name'=>"Dire Maul, Azuregos, Kazzak", 'phase'=> "Phase 2", 'date' => "16 December, 2021"],
	[ 'type'=>"som", 'name'=>"Blackwing Lair, Darkmoon Faire, Darkmoon deck drops begin", 'phase'=> "Phase 3", 'date' => "10 February, 2022"],
	[ 'type'=>"som", 'name'=>"Zul'Gurub, Dragons of Nightmare", 'phase'=> "Phase 4", 'date' => "3 March, 2022"],
	[ 'type'=>"som", 'name'=>"Ahn'Qiraj War Effort begins, Ahn'Qiraj raids within 30 days as the war effort dictates", 'phase'=> "Phase 5", 'date' => "21 April, 2022"],
	[ 'type'=>"som", 'name'=>"Naxxramas, Scourge Invasion", 'phase'=> "Phase 6", 'date' => "28 July, 2022"],

	[ 'type'=>"tbc-anniv", 'flavor'=>"wow-classic-tbc-anniv", 'name'=>"Leveling Updates, Draenei and Blood Elves, Jewelcrafting", 'phase'=>"Pre Patch", 'date' => "January 13th, 2026"],
	[ 'type'=>"tbc-anniv", 'flavor'=>"wow-classic-tbc-anniv", 'name'=>"Level Cap increased, New Dungeons, Heroic Dungeons, Karazhan, Gruul's Lair, and Magtheridon, Outland Zones", 'phase'=>"Phase 1", 'date' => "February 5th, 2026"],
	[ 'type'=>"tbc-anniv", 'flavor'=>"wow-classic-tbc-anniv", 'name'=>"Serpentshrine Cavern, Tempest Keep Raids, Profession Updates", 'phase'=>"Phase 2", 'date' => "May 14th, 2026"],
	[ 'type'=>"tbc-anniv", 'flavor'=>"wow-classic-tbc-anniv", 'name'=>"Mount Hyjal, and Black Temple Raids", 'phase'=>"Phase 3", 'date' => "Summer, 2026"],
	[ 'type'=>"tbc-anniv", 'flavor'=>"wow-classic-tbc-anniv", 'name'=>"Zul'Aman", 'phase'=>"Phase 4", 'date' => "Autumn, 2026"],
	[ 'type'=>"tbc-anniv", 'flavor'=>"wow-classic-tbc-anniv", 'name'=>"Magister's Terrace (Dungeon) Sunwell Plateau (Raid) Isle of Quel'Dan Daily Quest Hub", 'phase'=>"Phase 5", 'date' => "Autumn, 2026"],


];
?>
<!DOCTYPE html>
<html>
<head>
	<title>Telemetry Admin</title>
	<script src="includes/jquery-ui-1.13.2.custom/external/jquery/jquery.js"></script>
	<link rel="stylesheet" href="admin.css">
</head>
<body>
	<div class="container">
		<h1>Telemetry Administration</h1>
		<h2>Available Topics</h2>
		<div class="flavor-legend">
			<?php foreach ($FLAVORS as $slug => $label): ?>
				<span class="flavor-legend-item" data-flavor="<?= $slug ?>"><?= $label ?></span>
			<?php endforeach; ?>
		</div>
		<div id="topics-container">
			<table>
				<thead>
					<tr>
						<th>Topic Name</th>
						<th>Scraper</th>
						<th>Crunchers</th>
						<th>Endpoint</th>
						<th>View</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>

		<h2>Available Sources</h2>
		<div id="sources-container">
			<table>
				<thead>
					<tr>
						<th>Source</th>
						<th>Description</th>
						<th>Topics</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>

		<h2>Process Status</h2>
		<div id="status-container">
			<table>
				<thead>
					<tr>
						<th>Process Tag</th>
						<th>Updated At</th>
						<th>Component</th>
						<th>Value</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>

		<h2>Changelog</h2>
		<div id="git-logs-container">
			<table>
				<thead>
					<tr>
						<th>Hash</th>
						<th>Author</th>
						<th>Date</th>
						<th>Message</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>

	</div>

	<script>
		window.gitLogsOffset = 0;
		<?php
			$dates_map = [];
			foreach ($DATES as $d)
				$dates_map[date('Y-m-d', strtotime($d['date']))] = $d;
		?>;
		window.IMPORTANT_DATES = <?= json_encode($dates_map) ?>;

		$(function() {
			loadStatus();
			loadTopics();
			loadSources();
			loadGitLogs();
		});

		function loadData(doParam, containerSelector, responseKey, displayFunction) {
			$.ajax({
				url: 'telemetry_endpoint.php',
				type: 'GET',
				data: { do: doParam },
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						displayFunction(response[responseKey]);
					} else {
						showError('Failed to load data: ' + response.message, containerSelector);
					}
				},
				error: function(xhr, status, error) {
						var errorMsg = 'Error loading data';
						try {
							var response = JSON.parse(xhr.responseText);
							if (response.error) {
								errorMsg = 'Error: ' + response.error + 
									(response.errcode ? ' (' + response.errcode + ')' : '');
							}
						} catch (e) {
							if (e instanceof SyntaxError) {
								errorMsg += ': ' + xhr.responseText;
							} else if (xhr.status) {
								// If response is not JSON, use generic error message
								errorMsg += ' (HTTP ' + xhr.status + ': ' + xhr.statusText + ')';
							}
						}
						showError(errorMsg, containerSelector);
					},
				complete: function() {
					$(`#${containerSelector}`).removeClass('loading');
				},
				beforeSend: function() {
					$(`#${containerSelector}`).addClass('loading').find("tbody").html(`<tr><td colspan="4" style="text-align: center;">Loading ${responseKey}...</td></tr>`);
				}
			});
		}

		function loadStatus() {
			loadData('get_status', 'status-container', 'statuses', displayStatus);
		}

		function loadTopics() {
			loadData('list_topics', 'topics-container', 'topics', displayTopics);
		}

		function loadSources() {
			loadData('list_sources', 'sources-container', 'sources', displaySources);
		}

		function loadGitLogs() {
			window.gitLogsOffset = 0;
			$.ajax({
				url: 'git_logs.php',
				type: 'GET',
				data: { limit: 15, offset: 0 },
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						displayGitLogs(response.commits, true);
						window.gitLogsOffset = response.offset + response.count;
					} else {
						showError('Failed to load commits: ' + response.error, 'git-logs-container');
					}
				},
				error: function(xhr, status, error) {
					showError('Error loading commits: ' + error, 'git-logs-container');
				},
				beforeSend: function() {
					$('#git-logs-container table tbody').empty().html('<tr><td colspan="4" style="text-align: center;">Loading commits...</td></tr>');
				}
			});
		}

		function loadMoreGitLogs() {
			$.ajax({
				url: 'git_logs.php',
				type: 'GET',
				data: { limit: 20, offset: window.gitLogsOffset },
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						displayGitLogs(response.commits, false);
						window.gitLogsOffset = response.offset + response.count;
					} else {
						alert('Failed to load more commits: ' + response.error);
					}
				},
				error: function(xhr, status, error) {
					alert('Error loading more commits: ' + error);
				}
			});
		}

		function displayStatus(statuses) {
			var tbody = $('#status-container table tbody').empty();
			var statusRowTemplate = document.querySelector('#status-row-template');
			var statusDataTemplate = document.querySelector('#status-data-row-template');
			
			if (statuses.length === 0) {
				tbody.append('<tr><td colspan="4" style="text-align: center;">No status records available</td></tr>');
			} else {
				$.each(statuses, function(idx, status) {
					var data = status.data || {};
					var keys = Object.keys(data);
					
					if (keys.length === 0) {
						// No data case
						var noDataRow = statusRowTemplate.content.cloneNode(true);
						$(noDataRow).find('[data-field="tag"]').attr('rowspan', '1').text(status.tag);
						$(noDataRow).find('[data-field="updated_at"]').attr('rowspan', '1').text(formatDateISO(status.updated_at));
						$(noDataRow).find('[data-field="key"]').attr('colspan', '2').css('color', '#999').html('<em>No data</em>');
						tbody.append(noDataRow);
					} else {
						// First row with rowspan
						var firstKey = keys[0];
						var firstValue = formatStatusValue(data[firstKey]);
						var mainRow = statusRowTemplate.content.cloneNode(true);
						$(mainRow).find('[data-field="tag"]').attr('rowspan', keys.length).text(status.tag);
						$(mainRow).find('[data-field="updated_at"]').attr('rowspan', keys.length).text(formatDateISO(status.updated_at));
						$(mainRow).find('[data-field="key"]').text(firstKey);
						$(mainRow).find('[data-field="value"]').html(firstValue);
						tbody.append(mainRow);
						
						// Additional rows for remaining data
						for (var i = 1; i < keys.length; i++) {
							var key = keys[i];
							var value = formatStatusValue(data[key]);
							var dataRow = statusDataTemplate.content.cloneNode(true);
							$(dataRow).find('[data-field="key"]').text(key);
							$(dataRow).find('[data-field="value"]').html(value);
							tbody.append(dataRow);
						}
					}
				});
			}
		}

		function formatStatusValue(value) {
			if (value === null || value === undefined) {
				return '<em>null</em>';
			}
			if (typeof value === 'object') {
				return '<code>' + escapeHtml(JSON.stringify(value, null, 2)) + '</code>';
			}
			if (typeof value === 'boolean') {
				return '<strong>' + (value ? '✓ true' : '✗ false') + '</strong>';
			}
			if (typeof value === 'string') {
				// Check if it looks like a number
				if (!isNaN(value) && value !== '') {
					return '<code>' + escapeHtml(value) + '</code>';
				}
				return escapeHtml(value);
			}
			return escapeHtml(String(value));
		}

		function displayTopics(topics) {
			var tbody = $('#topics-container table tbody').empty();
			var rowTemplate = document.querySelector('#topic-row-template');
			var crunTemplate = document.querySelector('#cruncher-row-template');
			
			if (Object.keys(topics).length === 0) {
				tbody.append('<tr><td colspan="6" style="text-align: center;">No topics available</td></tr>');
			} else {
				const flavors = ["wow","wow-classic","wow-classic-tbc","wow-classic-tbc-anniv"];
				$.each(topics, function(name, topic) {
					var row = rowTemplate.content.cloneNode(true);
					$(row).find('[data-field="name"]').text(topic.name);
					$(row).find('[data-field="scraper"]').html(topic.scraper ? escapeHtml(topic.scraper) : '<em>N/A</em>');
					$(row).find('[data-field="crunchers"]').text(topic.crunchers > 0 ? topic.crunchers : '—').toggleClass('disabled', topic.crunchers === 0);
					$(row).find('[data-field="endpoint"]').text(topic.endpoint ? '✓' : '—').toggleClass('disabled', !topic.endpoint);
					$(row).find('[data-field="view"]').text(topic.view ? '✓' : '—').toggleClass('disabled', !topic.view);
					const daymapActions = flavors.map((flavor) =>
						$('<a href="#" class="action-link" data-flavor="' + flavor + '">◼</a> ')
							.on('click', function(e) {
								e.preventDefault();
								showDaymap(topic.name, null, new Date().getFullYear(), $(this).data('flavor'));
							})
					);
					$(row).find('[data-field="actions"]').append(daymapActions);
					tbody.append(row);
					
					// Add sub-rows for each cruncher
					if (topic.crunchers_list && topic.crunchers_list.length > 0) {
						// Add header row for crunchers batch
						var headerTemplate = document.querySelector('#cruncher-header-template');
						tbody.append(headerTemplate.content.cloneNode(true));
						
						$.each(topic.crunchers_list, function(idx, cruncher) {
							var crunRow = crunTemplate.content.cloneNode(true);
							$(crunRow).find('[data-field="label"]').text('↳ ' + (cruncher.name || (idx+1)));
							$(crunRow).find('[data-field="input"]').text(cruncher.input=="event" ? `Event '${cruncher.eventtype}'` : escapeHtml(cruncher.input));
							$(crunRow).find('[data-field="table"]').html(cruncher.table ? escapeHtml(cruncher.table) : '<em>N/A</em>');
							$(crunRow).find('[data-field="actions"]')
								.on('click', function() { showDaymap(topic.name, cruncher.name || (idx+1)); })
								.attr('title', `Show daymap for ${topic.name} - ${cruncher.name || ('Cruncher #' + (idx+1))}`)
								.text('daymap');
							tbody.append(crunRow);
						});
					}
				});
			}
		}

		function displaySources(sources) {
			var tbody = $('#sources-container table tbody').empty();
			var template = document.querySelector('#source-row-template');
			
			if (Object.keys(sources).length === 0) {
				tbody.append('<tr><td colspan="4" style="text-align: center;">No sources available</td></tr>');
			} else {
				$.each(sources, function(key, source) {
					var row = template.content.cloneNode(true);
					$(row).find('[data-field="label"]').text(source.label);
					$(row).find('[data-field="description"]').text(source.description);
					
					var topicsSpan = $(row).find('[data-field="topics"]');
					topicsSpan.text(source.topics);
					if (source.topics_list && source.topics_list.length > 0) {
						topicsSpan.attr('title', source.topics_list.join(', '));
					}
					
					var statusBadge = $(row).find('[data-field="status-badge"]');
					if (source.status === true) {
						statusBadge.text('✓ Configured').removeClass('disabled error');
					} else {
						statusBadge.text('✗ Error').addClass('error').removeClass('disabled');
					}
					
					var statusPaths = $(row).find('[data-field="status-details"]');
					if (source.status === true && source.source_paths && source.source_paths.length > 0) {
						statusPaths.html('<code>' + source.source_paths.map(function(p) { return escapeHtml(p); }).join('<br>') + '</code>');
					} else if (source.status === false && source.error) {
						statusPaths.html('<code class="error">' + escapeHtml(source.error) + '</code>');
					}
					
					tbody.append(row);
				});
			}
		}

		function buildCommitRows(commits) {
			var tbody = $('<tbody></tbody>');
			var template = document.querySelector('#commit-row-template');
			
			$.each(commits, function(idx, commit) {
				var row = template.content.cloneNode(true);
				$(row).find('[data-field="hash"]').text(commit.hash.substring(0, 7));
				$(row).find('[data-field="author-email"]')
					.attr('title', commit.email)
					.text(commit.author);
				$(row).find('[data-field="date"]').text(formatDateISO(commit.date));
				
				var fullMsg = commit.message;
				if (commit.message_body) {
					fullMsg += '\n' + commit.message_body;
				}
				$(row).find('[data-field="message"]')
					.attr('title', fullMsg)
					.text(commit.message);
				
				if (commit.message_body) {
					$(row).find('[data-field="message-body"]')
						.attr('title', fullMsg)
						.html(escapeHtml(commit.message_body).replace(/\n/g, '<br>'));
					$(row).find('.commit-body').show();
				} else {
					$(row).find('.commit-body').hide();
				}
				
				tbody.append(row);
			});
			
			return tbody.html();
		}

		function displayGitLogs(commits, isInitial) {
			if (isInitial === undefined) isInitial = true;
			
			var tbody = $('#git-logs-container table tbody');
			
			if (isInitial) {
				// Clear and repopulate on first load
				tbody.empty();
				
				if (commits.length === 0) {
					tbody.append('<tr><td colspan="4" style="text-align: center;">No commits available</td></tr>');
				} else {
					var rows = buildCommitRows(commits);
					tbody.append(rows);
				}
				
				tbody.append('<tr class="git-logs-more-row"><td colspan="4" style="text-align: center; padding: 8px;"><button onclick="loadMoreGitLogs()" class="more-button">More...</button></td></tr>');
			} else {
				// Append more rows before the "More..." button
				var rows = buildCommitRows(commits);
				tbody.find('tr.git-logs-more-row').before(rows);
			}
		}

		function showError(message, container) {
			var containerId = container || 'topics-container';
			$('#' + containerId).html('<div class="error">' + escapeHtml(message) + '</div>');
		}

		function escapeHtml(text) {
			var map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, function(m) { return map[m]; });
		}

		function formatDateISO(isoString) {
			if (!isoString) return 'N/A';
			try {
				var date = new Date(isoString);
				var year = date.getUTCFullYear();
				var month = String(date.getUTCMonth() + 1).padStart(2, '0');
				var day = String(date.getUTCDate()).padStart(2, '0');
				var hours = String(date.getUTCHours()).padStart(2, '0');
				var minutes = String(date.getUTCMinutes()).padStart(2, '0');
				var seconds = String(date.getUTCSeconds()).padStart(2, '0');
				return year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
			} catch (e) {
				return isoString;
			}
		}

		function showDaymap(topicName, cruncherName=null, selectedYear, flavour='wow') {
			var currentYear = selectedYear || new Date().getFullYear();
			window.currentDaymapTopic = topicName;
			window.currentDaymapCruncher = cruncherName;
			window.currentDaymapFlavor = flavour;
			
			// Fetch full history from 2000 to current year on first load
			// If we already have cached data, use that instead

			/*
			if (window.daymapCache && window.daymapCacheTopic === topicName && window.daymapCacheCruncher === cruncherName) {
				displayCalendar(topicName, window.daymapCache, currentYear);
				return;
			}
			*/
			
			var fromDate = new Date(2000, 0, 1).toISOString().split('T')[0];
			var toDate = new Date(currentYear, 11, 31).toISOString().split('T')[0];

			$.ajax({
				url: 'telemetry_endpoint.php',
				type: 'GET',
				data: {
					topic: topicName,
					cruncher: cruncherName,
					from: fromDate,
					to: toDate,
					flavour: flavour,
					variant: 'daymap'
				},
				dataType: 'json',
				beforeSend: function() {
					$('.calendar-header').text(topicName + (cruncherName ? ' - ' + cruncherName : '') + ' — Loading…');
					$('#calendar-modal').addClass('active loading');
				},
				complete: function() {
					$('#calendar-modal').removeClass('loading');
				},
				success: function(response) {
					if (response.success) {
						// Cache the response for future year selections
						window.daymapCache = response;
						window.daymapCacheTopic = topicName;
						window.daymapCacheCruncher = cruncherName;

						displayCalendar(topicName + ' - ' + (cruncherName || 'events'), response, currentYear);
					} else if (response.error) {
						alert('Error loading daymap: ' + (response.error || 'Unknown error') + 
							(response.errcode ? ' (' + response.errcode + ')' : ''));
					} else if (response.status == "EXCEPTION") {
						alert(`Exception occurred: ${response.type || 'Unknown'} - ${response.message || 'No message'} ${response.file ? 'in ' + response.file : ''} ${response.line ? 'at line ' + response.line : ''}`);
					} else {
						alert('Failed to load daymap data');
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = 'Error fetching daymap data';
					try {
						var response = JSON.parse(xhr.responseText);
						if (response.error) {
							errorMsg = 'Error: ' + response.error + 
								(response.errcode ? ' (' + response.errcode + ')' : '');
						}
					} catch (e) {
						if (e instanceof SyntaxError) {
							errorMsg += ': ' + xhr.responseText;
						} else if (xhr.status) {
							// If response is not JSON, use generic error message
							errorMsg += ' (HTTP ' + xhr.status + ': ' + xhr.statusText + ')';
						}
					}
					alert(errorMsg);
				}
			});
		}

		function applyDaymapData() {
			var daymap = window.currentDaymap || {};
			var maxCount = window.currentMaxCount || 1;
			var totalCount = window.currentTotalCount || 0;
			var yearTotals = window.currentYearTotals || {};
			var topic = window.currentDaymapTopic || 'Unknown Topic?';
			var flavor = window.currentDaymapFlavor || '';

			$(".calendar-header").text(`Topic: ${topic} - Flavor: ${flavor} - Yearly Total: ${yearTotals[$('#year-select').val()] || 0} / Overall Total: ${totalCount}`);
			
			$('.calendar-day').each(function() {
				var dateStr = $(this).data('date');
				if (!dateStr) return;
				var count = daymap[dateStr] || 0;
				var heatLevel = 0;
				
				if (count > 0 && maxCount > 0) {
					var ratio = count / maxCount;
					heatLevel = Math.ceil(ratio * 10);
					if (heatLevel > 10) heatLevel = 10;
				}
				$(this).attr('title', count);
				$(this).removeClass(function(index, css) {
					return (css.match(/heat-\d+/g) || []).join(' ');
				});

				var importantDate = window.IMPORTANT_DATES && window.IMPORTANT_DATES[dateStr];
				$(this).toggleClass('important-date', !!importantDate);
				$(this).attr('data-flavor', importantDate ? importantDate.flavor : '')
				if (importantDate)
					$(this).attr('title', `${count}\n\u2605 [${importantDate.type}] ${importantDate.name}` + (importantDate.phase ? ` (${importantDate.phase})` : '') + `\n(${dateStr})`);
				if (heatLevel > 0) {
					$(this).addClass('heat-' + heatLevel);
				}
			});
		}

		function populateCalendarGrid(year) {
			$('.month-calendar').each(function() {
				var month = parseInt($(this).data('month'));
				var firstDate = new Date(year, month, 1);
				var firstDay = firstDate.getDay();
				var daysInMonth = new Date(year, month + 1, 0).getDate();
				
				var cells = $(this).find('.calendar-day');
				cells.each(function(index) {
					var $cell = $(this);
					// clear out
					$cell.removeClass().addClass('calendar-day').data("date",null).attr('title', '0').text('');
					
					if (index < firstDay) {
						// Padding cell before month starts
						$cell.addClass('padding-cell');
					} else if (index - firstDay < daysInMonth) {
						// Actual day of month
						var day = index - firstDay + 1;
						var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
						$cell.text(day).data('date', dateStr);
					} else {
						// Padding cell after month ends
						$cell.addClass('padding-cell');
					}
				});
			});

			// update button states
			$("#prev-year-btn").prop('disabled', year === parseInt(window.yearRange[0]));
			$("#next-year-btn").prop('disabled', year === parseInt(window.yearRange[window.yearRange.length - 1]));
		}

		function displayCalendar(topicName, daymapObj, year) {
			// Extract data and metadata
			var daymap = daymapObj.data || daymapObj;
			var maxCount = daymapObj.count_max || 1;
			var totalCount = daymapObj.count_total || 0;
			var yearTotals = daymapObj.year_totals || {};
			
			// Extract years that have data
			var yearsWithData = {};
			$.each(daymap, function(dateStr, count) {
				if (count > 0) {
					var yearStr = dateStr.substring(0, 4);
					yearsWithData[yearStr] = true;
				}
			});
			var availableYears = Object.keys(yearsWithData).sort();
			
			// Build year range from first year with data to current year
			var yearRange = [];
			if (availableYears.length > 0) {
				var firstYear = parseInt(availableYears[0]);
				var lastYear = new Date().getFullYear();
				for (var y = firstYear; y <= lastYear; y++) {
					yearRange.push(String(y));
				}
			}
			
			// Store available years, year range, and daymap for navigation
			window.availableYears = availableYears;
			window.yearRange = yearRange;
			window.currentDaymap = daymap;
			window.currentMaxCount = maxCount;
			window.currentTotalCount = totalCount;
			window.currentYearTotals = yearTotals;
			
			// Set calendar header and title
			$('.calendar-header').text(topicName);
			
			// Populate year selector
			var yearSelect = $('#year-select');
			yearSelect.empty();
			if (yearRange.length === 0) {
				yearSelect.append('<option>No data available</option>');
			} else {
				$.each(yearRange, function(idx, y) {
					yearSelect.append('<option value="' + y + '"' + (parseInt(y) === year ? ' selected' : '') + '>' + y + '</option>');
				});
			}
			
			// Populate grid with day numbers for the selected year
			populateCalendarGrid(year);		
			// Apply daymap data (counts and heat levels)
			applyDaymapData();
			
			$('#calendar-modal').addClass('active');
		}

		function changeYear(element, direction) {
			var yearRange = window.yearRange || [];
			var currentYearSelect = parseInt($('#year-select').val());
			var newYear;
			
			if (direction === 0) {
				// Changed via dropdown
				newYear = currentYearSelect;
			} else {
				// Clicked prev/next button
				var currentIndex = yearRange.indexOf(String(currentYearSelect));
				var newIndex = currentIndex + direction;
				
				// Prevent navigation outside year range
				if (newIndex < 0 || newIndex >= yearRange.length) {
					console.log("Year out of range");
					return;
				}
				newYear = parseInt(yearRange[newIndex]);

				// Update dropdown to reflect new year
				$('#year-select').val(newYear);

			}

			// Populate grid with new year and apply data
			populateCalendarGrid(newYear);
			applyDaymapData();
		}

		function closeCalendar() {
			$('#calendar-modal').removeClass('active');
		}

		// Close modal when clicking outside the content area
		$(document).on('click', function(e) {
			var $modal = $('#calendar-modal');
			if ($modal.hasClass('active') && e.target.id === 'calendar-modal') {
				closeCalendar();
			}
		});

		// Close modal on Escape key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $('#calendar-modal').hasClass('active')) {
				closeCalendar();
			}
		});
	</script>

	<!-- HTML Templates for table rows -->
	<template id="status-row-template">
		<tr class="status-row">
			<td><strong data-field="tag"></strong></td>
			<td><code data-field="updated_at"></code></td>
			<td><strong data-field="key"></strong></td>
			<td data-field="value"></td>
		</tr>
	</template>

	<template id="status-data-row-template">
		<tr class="status-row-data">
			<td><strong data-field="key"></strong></td>
			<td data-field="value"></td>
		</tr>
	</template>

	<template id="topic-row-template">
		<tr class="topic-row">
			<td><strong data-field="name"></strong></td>
			<td data-field="scraper"></td>
			<td style="text-align: center;"><span class="badge" data-field="crunchers"></span></td>
			<td style="text-align: center;"><span class="badge" data-field="endpoint"></span></td>
			<td style="text-align: center;"><span class="badge" data-field="view"></span></td>
			<td data-field="actions"></td>
		</tr>
	</template>

	<template id="cruncher-row-template">
		<tr class="cruncher-sub-row">
			<td class="cruncher-indent" data-field="label"></td>
			<td><code data-field="input"></code></td>
			<td colspan="3"><code class="table-name" data-field="table"></code></td>
			<td data-field="actions"></td>
		</tr>
	</template>

	<template id="cruncher-header-template">
		<tr class="cruncher-header-row">
			<td class="cruncher-header" style="font-weight: bold; padding: 8px 4px 4px 20px;">Cruncher</td>
			<td class="cruncher-header" style="font-weight: bold;">Source</td>
			<td class="cruncher-header" colspan="4" style="font-weight: bold;">Dest. Table</td>
		</tr>
	</template>

	<template id="source-row-template">
		<tr>
			<td><strong data-field="label"></strong></td>
			<td data-field="description"></td>
			<td style="text-align: center;"><span class="badge" data-field="topics"></span></td>
			<td style="text-align: center; vertical-align: middle;">
				<span class="badge" data-field="status-badge"></span>
				<div class="status-details" data-field="status-details"></div>
			</td>
		</tr>
	</template>

	<template id="commit-row-template">
		<tr class="commit-row">
			<td><span class="commit-hash" data-field="hash"></span></td>
			<td><span data-field="author-email"></span><br></td>
			<td data-field="date"></td>
			<td>
				<strong data-field="message"></strong>
				<div class="commit-body"><small data-field="message-body"></small></div>
			</td>
		</tr>
	</template>

	<div id="calendar-modal" class="calendar-modal">
		<div class="calendar-content">
			<div class="calendar-header"></div>
			<div class="year-selector">
				<button onclick="changeYear(this, -1)" id="prev-year-btn">← Prev Year</button>
				<select id="year-select" onchange="changeYear(this, 0)"></select>
				<button onclick="changeYear(this, 1)" id="next-year-btn">Next Year →</button>
			</div>
			<div class="months-grid">
				<?php
				$monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
					'July', 'August', 'September', 'October', 'November', 'December'];
				
				for ($month = 0; $month < 12; $month++):
				?>
				<div class="month-block">
					<div class="month-name"><?php echo $monthNames[$month]; ?></div>
					<div class="month-calendar" data-month="<?php echo $month; ?>">
						<?php
						// Create 42 empty cells (6 weeks × 7 days)
						for ($cell = 0; $cell < 42; $cell++):
						?>
						<div class="calendar-day"></div>
						<?php endfor; ?>
					</div>
				</div>
				<?php endfor; ?>
			</div>
			<div class="calendar-close"><button onclick="closeCalendar()">Close</button></div>
		</div>
	</div>
</body>
</html>
