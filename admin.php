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
			max-width: 800px;
			margin: 0 auto;
			background-color: white;
			padding: 20px;
			border-radius: 5px;
			box-shadow: 0 2px 5px rgba(0,0,0,0.1);
		}
		h1 {
			color: #333;
		}
		.topic-list {
			list-style: none;
			padding: 0;
		}
		.scraper-group {
			margin-bottom: 30px;
		}
		.scraper-group h3 {
			color: #1976d2;
			border-bottom: 2px solid #1976d2;
			padding-bottom: 10px;
			margin-top: 0;
		}
		.topic-item {
			background-color: #f9f9f9;
			border: 1px solid #ddd;
			padding: 15px;
			margin-bottom: 10px;
			border-radius: 3px;
		}
		.topic-name {
			font-weight: bold;
			font-size: 16px;
			color: #333;
		}
		.topic-info {
			font-size: 12px;
			color: #666;
			margin-top: 8px;
		}
		.badge {
			display: inline-block;
			padding: 2px 8px;
			margin-right: 5px;
			background-color: #e3f2fd;
			color: #1976d2;
			border-radius: 3px;
			font-size: 11px;
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
			var grouped = {};
			
			// Group topics by scraper
			$.each(topics, function(name, topic) {
				var scraper = topic.scraper || 'unknown';
				if (!grouped[scraper]) {
					grouped[scraper] = [];
				}
				grouped[scraper].push(topic);
			});
			
			var html = '';
			
			// Display each scraper group
			$.each(grouped, function(scraper, topicList) {
				html += '<div class="scraper-group">';
				html += '<h3>Scraper: ' + escapeHtml(scraper) + '</h3>';
				html += '<ul class="topic-list">';
				
				$.each(topicList, function(index, topic) {
					html += '<li class="topic-item">';
					html += '<div class="topic-name">' + escapeHtml(topic.name) + '</div>';
					html += '<div class="topic-info">';
					if (topic.has_endpoint) {
						html += '<span class="badge">Has Endpoint</span>';
					}
					if (topic.has_view) {
						html += '<span class="badge">Has View</span>';
					}
					html += '</div>';
					html += '</li>';
				});
				
				html += '</ul>';
				html += '</div>';
			});
			
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
