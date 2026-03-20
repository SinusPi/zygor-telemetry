<?php
// Admin panel for telemetry management
?>
<!DOCTYPE html>
<html>
<head>
	<title>Telemetry Admin</title>
	<script src="includes/jquery-ui-1.13.2.custom/external/jquery/jquery.js"></script>
	<style>
		body {
			font-family: Arial, sans-serif;
			margin: 20px;
			background-color: #f5f5f5;
		}
		.container {
			max-width: 1000px;
			margin: 0 auto;
			background-color: white;
			padding: 20px;
			border-radius: 5px;
			box-shadow: 0 2px 5px rgba(0,0,0,0.1);
		}
		h1 {
			color: #333;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			margin-top: 20px;
		}
		table thead {
			background-color: #1976d2;
			color: white;
		}
		table th {
			padding: 12px;
			text-align: left;
			font-weight: bold;
		}
		table td {
			padding: 10px 12px;
			border-bottom: 1px solid #ddd;
		}
		table tbody tr:hover {
			background-color: #f5f5f5;
		}
		table tbody tr:nth-child(odd) {
			background-color: #fafafa;
		}
		.badge {
			display: inline-block;
			padding: 4px 8px;
			margin-right: 5px;
			background-color: #4caf50;
			color: white;
			border-radius: 3px;
			font-size: 11px;
			font-weight: bold;
		}
		.badge.disabled {
			background-color: #ccc;
			color: #666;
		}
		.loading {
			text-align: center;
			padding: 20px;
			color: #999;
		}
		.error {
			background-color: #ffebee;
			color: #c62828;
			padding: 15px;
			border-radius: 3px;
			border: 1px solid #ef5350;
		}
		a.action-link {
			color: #1976d2;
			text-decoration: none;
			cursor: pointer;
			font-weight: bold;
		}
		a.action-link:hover {
			text-decoration: underline;
		}
		.calendar-modal {
			display: none;
			position: fixed;
			z-index: 1000;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background-color: rgba(0, 0, 0, 0.5);
		}
		.calendar-modal.active {
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.calendar-content {
			background-color: white;
			padding: 25px;
			border-radius: 8px;
			box-shadow: 0 4px 20px rgba(0,0,0,0.3);
			width: 95%;
			max-width: 1400px;
			max-height: 80vh;
			overflow-y: auto;
		}
		.calendar-header {
			font-size: 18px;
			font-weight: bold;
			margin-bottom: 20px;
			color: #333;
		}
		.calendar-grid {
			display: grid;
			grid-template-columns: repeat(7, 1fr);
			gap: 8px;
			margin-bottom: 20px;
		}
		.months-grid {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 20px;
			margin-bottom: 20px;
		}
		.month-block {
			border: 1px solid #ccc;
			border-radius: 5px;
			padding: 15px;
			background-color: #fafafa;
		}
		.month-name {
			font-weight: bold;
			font-size: 14px;
			text-align: center;
			margin-bottom: 10px;
			color: #333;
		}
		.month-calendar {
			display: grid;
			grid-template-columns: repeat(7, 1fr);
			gap: 4px;
		}
		.calendar-day {
			text-align: center;
			padding: 8px;
			font-size: 12px;
			border: 1px solid #eee;
			min-height: 30px;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.month-calendar .calendar-day {
			padding: 4px;
			min-height: 20px;
			font-size: 10px;
		}
		.calendar-day.has-data::after {
			content: "●";
			font-size: 20px;
			color: #4caf50;
		}
		.calendar-day.header {
			font-weight: bold;
			background-color: #f0f0f0;
			border: none;
		}
		.calendar-close {
			text-align: right;
		}
		.calendar-close button {
			padding: 8px 16px;
			background-color: #1976d2;
			color: white;
			border: none;
			border-radius: 3px;
			cursor: pointer;
			font-size: 14px;
		}
		.calendar-close button:hover {
			background-color: #1565c0;
		}
		.year-selector {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 15px;
			margin-bottom: 20px;
		}
		.year-selector button {
			padding: 6px 12px;
			background-color: #1976d2;
			color: white;
			border: none;
			border-radius: 3px;
			cursor: pointer;
			font-size: 12px;
		}
		.year-selector button:hover {
			background-color: #1565c0;
		}
		.year-selector select {
			padding: 6px 10px;
			font-size: 14px;
			border: 1px solid #ccc;
			border-radius: 3px;
		}
	</style>
</head>
<body>
	<div class="container">
		<h1>Telemetry Administration</h1>
		<h2>Available Topics</h2>
		<div id="topics-container" class="loading">Loading topics...</div>
	</div>

	<script>
		$(function() {
			loadTopics();
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
						showError('Failed to load topics: ' + response.error);
					}
				},
				error: function(xhr, status, error) {
					showError('Error loading topics: ' + error);
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
					html += '<tr>';
					html += '<td><strong>' + escapeHtml(topic.name) + '</strong></td>';
					html += '<td>' + (topic.scraper ? escapeHtml(topic.scraper) : '<em>N/A</em>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.crunchers > 0 ? '<span class="badge">' + topic.crunchers + '</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.endpoint ? '<span class="badge">✓</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.view ? '<span class="badge">✓</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td><a class="action-link" onclick="showDaymap(\'' + escapeHtml(topic.name) + '\')">daymap</a></td>';
					html += '</tr>';
				});
			}
			
			html += '</tbody>';
			html += '</table>';
			$('#topics-container').html(html);
		}

		function showError(message) {
			$('#topics-container').html('<div class="error">' + escapeHtml(message) + '</div>');
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
