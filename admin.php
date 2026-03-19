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
			html += '</tr>';
			html += '</thead>';
			html += '<tbody>';
			
			if (Object.keys(topics).length === 0) {
				html += '<tr><td colspan="5" style="text-align: center;">No topics available</td></tr>';
			} else {
				$.each(topics, function(name, topic) {
					html += '<tr>';
					html += '<td><strong>' + escapeHtml(topic.name) + '</strong></td>';
					html += '<td>' + (topic.scraper ? escapeHtml(topic.scraper) : '<em>N/A</em>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.crunchers > 0 ? '<span class="badge">' + topic.crunchers + '</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.endpoint ? '<span class="badge">✓</span>' : '<span class="badge disabled">—</span>') + '</td>';
					html += '<td style="text-align: center;">' + (topic.view ? '<span class="badge">✓</span>' : '<span class="badge disabled">—</span>') + '</td>';
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
	</script>
</body>
</html>
