<?php
// Admin panel for telemetry management
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
		<div id="topics-container" class="loading">Loading topics...</div>

		<h2>Available Sources</h2>
		<div id="sources-container" class="loading">Loading sources...</div>
	</div>

	<script>
		$(function() {
			loadTopics();
			loadSources();
		});

		function loadTopics() {
			$.ajax({
				url: 'telemetry_endpoint.php',
				type: 'GET',
				data: { do: 'list_topics' },
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						displayTopics(response.topics);
					} else {
						showError('Failed to load topics: ' + response.error, 'topics-container');
					}
				},
				error: function(xhr, status, error) {
					showError('Error loading topics: ' + error, 'topics-container');
				}
			});
		}

		function loadSources() {
			$.ajax({
				url: 'telemetry_endpoint.php',
				type: 'GET',
				data: { do: 'list_sources' },
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						displaySources(response.sources);
					} else {
						showError('Failed to load sources: ' + response.error, 'sources-container');
					}
				},
				error: function(xhr, status, error) {
					showError('Error loading sources: ' + error, 'sources-container');
				}
			});
		}

		function displayTopics(topics) {
			var html = '<table>';
			html += '<thead>';
			html += '<tr>';
			html += '<th>Topic Name</th>';
			html += '<th>Scraper Source</th>';
			html += '<th>Crunchers</th>';
			html += '<th>Endpoint</th>';
			html += '<th>View</th>';
			html += '<th>Actions</th>';
			html += '</tr>';
			html += '</thead>';
			html += '<tbody>';
			
			if (Object.keys(topics).length === 0) {
				html += '<tr><td colspan="6" style="text-align: center;">No topics available</td></tr>';
			} else {
				$.each(topics, function(name, topic) {
					html += '<tr class="topic-row">';
					html += '<td><strong>' + escapeHtml(topic.name) + '</strong></td>';
					html += '<td>' + (topic.scraper ? escapeHtml(topic.scraper) : '<em>N/A</em>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.crunchers > 0 ? '<span class="badge">' + topic.crunchers + '</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.endpoint ? '<span class="badge">✓</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.view ? '<span class="badge">✓</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td><a class="action-link" onclick="showDaymap(\'' + escapeHtml(topic.name) + '\')">daymap</a></td>';
					html += '</tr>';
					
					// Add sub-rows for each cruncher
					if (topic.crunchers_list && topic.crunchers_list.length > 0) {
						$.each(topic.crunchers_list, function(idx, cruncher) {
							html += '<tr class="cruncher-sub-row">';
							html += '<td class="cruncher-indent">↳ Cruncher ' + cruncher.index + '</td>';
							html += '<td><code>' + escapeHtml(cruncher.eventtype) + '</code></td>';
							html += '<td colspan="4">';
							if (cruncher.table) {
								html += '<code class="table-name">' + escapeHtml(cruncher.table) + '</code>';
							} else {
								html += '<em>N/A</em>';
							}
							html += '</td>';
							html += '</tr>';
						});
					}
				});
			}
			
			html += '</tbody>';
			html += '</table>';
			$('#topics-container').html(html);
		}

		function displaySources(sources) {
			var html = '<table>';
			html += '<thead>';
			html += '<tr>';
			html += '<th>Source</th>';
			html += '<th>Description</th>';
			html += '<th>Topics</th>';
			html += '<th>Status</th>';
			html += '</tr>';
			html += '</thead>';
			html += '<tbody>';
			
			if (Object.keys(sources).length === 0) {
				html += '<tr><td colspan="4" style="text-align: center;">No sources available</td></tr>';
			} else {
				$.each(sources, function(key, source) {
					var statusBadge = '<span class="badge';
					var statusContent = '';
					
					if (source.status === 'configured') {
						statusBadge += '">✓ Configured</span>';
						// Show source paths for configured sources
						if (source.source_paths && source.source_paths.length > 0) {
							statusContent = source.source_paths.map(function(p) { return escapeHtml(p); }).join('<br>');
						}
					} else if (source.status === 'not-configured') {
						statusBadge += ' disabled">⚠ Not Configured</span>';
					} else if (source.status.indexOf('error') === 0) {
						statusBadge += ' error">✗ Error</span>';
						statusContent = '<code>' + escapeHtml(source.status) + '</code>';
					} else {
						statusBadge += '">? Unknown</span>';
					}
					
					// Build topics tooltip
					var topicsTooltip = '';
					if (source.topics_list && source.topics_list.length > 0) {
						topicsTooltip = ' title="' + escapeHtml(source.topics_list.join(', ')) + '"';
					}
					
					html += '<tr>';
					html += '<td><strong>' + escapeHtml(source.label) + '</strong></td>';
					html += '<td>' + escapeHtml(source.description) + '</td>';
					html += '<td style="text-align: center;"><span class="badge"' + topicsTooltip + '>' + source.topics + '</span></td>';
					html += '<td style="text-align: center; vertical-align: middle;">';
					html += statusBadge;
					if (statusContent) {
						html += '<div class="status-paths"><code>' + statusContent + '</code></div>';
					}
					html += '</td>';
					html += '</tr>';
				});
			}
			
			html += '</tbody>';
			html += '</table>';
			$('#sources-container').html(html);
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

		function showDaymap(topicName, selectedYear) {
			var currentYear = selectedYear || new Date().getFullYear();
			window.currentDaymapTopic = topicName;
			
			// Fetch full history from 2000 to current year on first load
			// If we already have cached data, use that instead
			if (window.daymapCache && window.daymapCacheTopic === topicName) {
				displayCalendar(topicName, window.daymapCache, currentYear);
				return;
			}
			
			var fromDate = new Date(2000, 0, 1).toISOString().split('T')[0];
			var toDate = new Date(currentYear, 11, 31).toISOString().split('T')[0];

			$.ajax({
				url: 'telemetry_endpoint.php',
				type: 'GET',
				data: {
					topic: topicName,
					from: fromDate,
					to: toDate,
					flavour: 'wow',
					variant: 'daymap'
				},
				dataType: 'json',
				success: function(response) {
					if (response.success) {
						// Cache the response for future year selections
						window.daymapCache = response.data;
						window.daymapCacheTopic = topicName;
						displayCalendar(topicName, response.data, currentYear);
					} else {
						alert('Error loading daymap: ' + (response.error || 'Unknown error') + 
							(response.errcode ? ' (' + response.errcode + ')' : ''));
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
						// If response is not JSON, use generic error message
						if (xhr.status) {
							errorMsg += ' (HTTP ' + xhr.status + ': ' + xhr.statusText + ')';
						}
					}
					alert(errorMsg);
				}
			});
		}

		function displayCalendar(topicName, daymap, year) {
			var html = '<div class="calendar-header">' + escapeHtml(topicName) + '</div>';
			
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
			
			// Year selector
			html += '<div class="year-selector">';
			html += '<button onclick="changeYear(this, -1)" id="prev-year-btn">← Prev Year</button>';
			html += '<select id="year-select" onchange="changeYear(this, 0)">';
			
			if (yearRange.length === 0) {
				html += '<option>No data available</option>';
			} else {
				$.each(yearRange, function(idx, y) {
					html += '<option value="' + y + '"' + (parseInt(y) === year ? ' selected' : '') + '>' + y + '</option>';
				});
			}
			
			html += '</select>';
			html += '<button onclick="changeYear(this, 1)" id="next-year-btn">Next Year →</button>';
			html += '</div>';
			
			html += '<div class="months-grid">';
			
			var monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
				'July', 'August', 'September', 'October', 'November', 'December'];
			var dayNames = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
			
			// Iterate through each month
			for (var month = 0; month < 12; month++) {
				html += '<div class="month-block">';
				html += '<div class="month-name">' + monthNames[month] + '</div>';
				html += '<div class="month-calendar">';
				
				// Day headers
				$.each(dayNames, function(idx, day) {
					html += '<div class="calendar-day header">' + day + '</div>';
				});
				
				// Get the first day of the month and padding
				var firstDay = new Date(year, month, 1);
				var padding = firstDay.getDay();
				
				// Add padding
				for (var p = 0; p < padding; p++) {
					html += '<div class="calendar-day"></div>';
				}
				
				// Add days of the month
				var daysInMonth = new Date(year, month + 1, 0).getDate();
				for (var day = 1; day <= daysInMonth; day++) {
					var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
					var hasData = daymap[dateStr] && daymap[dateStr] > 0;
					html += '<div class="calendar-day' + (hasData ? ' has-data' : '') + '">' + day + '</div>';
				}
				
				html += '</div>';
				html += '</div>';
			}
			
			html += '</div>';
			html += '<div class="calendar-close"><button onclick="closeCalendar()">Close</button></div>';
			
			$('#calendar-modal .calendar-content').html(html);
			$('#calendar-modal').addClass('active');
			
			// Store available years and year range for navigation
			window.availableYears = availableYears;
			window.yearRange = yearRange;
		}

		function changeYear(element, direction) {
			var currentTopic = window.currentDaymapTopic;
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
					return;
				}
				newYear = parseInt(yearRange[newIndex]);
			}
			
			if (currentTopic && yearRange.indexOf(String(newYear)) !== -1) {
				showDaymap(currentTopic, newYear);
			}
		}

		function closeCalendar() {
			$('#calendar-modal').removeClass('active');
		}
	</script>

	<div id="calendar-modal" class="calendar-modal">
		<div class="calendar-content"></div>
	</div>
</body>
</html>
