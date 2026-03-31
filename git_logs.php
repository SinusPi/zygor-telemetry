<?php
/**
 * Endpoint to serve recent Git commit logs in JSON format
 */

header("Content-Type: application/json; charset=utf-8");

try {
	$limit = isset($_REQUEST['limit']) ? intval($_REQUEST['limit']) : 10;
	if ($limit < 1 || $limit > 100) {
		$limit = 10;
	}

	$offset = isset($_REQUEST['offset']) ? intval($_REQUEST['offset']) : 0;
	if ($offset < 0) {
		$offset = 0;
	}

	// Get the directory where this script is located (the repo root)
	$repoDir = __DIR__;

	// Check if .git directory exists
	if (!is_dir($repoDir . '/.git')) {
		die(json_encode([
			"success" => false,
			"error" => "Not a Git repository"
		], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
	}

	// Execute git log command to get recent commits with offset
	$cmd = sprintf(
		"cd %s && git log --pretty=format:%%H%%n%%an%%n%%ae%%n%%ai%%n%%s%%n%%b%%n--- --skip %d -n %d",
		escapeshellarg($repoDir),
		$offset,
		$limit
	);

	$output = [];
	$returnCode = 0;
	exec($cmd, $output, $returnCode);

	if ($returnCode !== 0) {
		die(json_encode([
			"success" => false,
			"error" => "Failed to execute git log"
		], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
	}

	// Parse the output into commit objects
	$commits = [];
	$currentCommit = null;
	$bodyLines = [];
	$isParsingBody = false;

	foreach ($output as $line) {
		if ($line === '---') {
			// End of current commit
			if ($currentCommit !== null) {
				$currentCommit['message_body'] = trim(implode("\n", $bodyLines));
				$commits[] = $currentCommit;
			}
			$currentCommit = null;
			$bodyLines = [];
			$isParsingBody = false;
		} elseif ($currentCommit === null) {
			// Start new commit - first line is hash
			$currentCommit = [
				'hash' => $line,
				'author' => null,
				'email' => null,
				'date' => null,
				'message' => null,
				'message_body' => ''
			];
		} elseif ($currentCommit['author'] === null) {
			// Second line is author name
			$currentCommit['author'] = $line;
		} elseif ($currentCommit['email'] === null) {
			// Third line is author email
			$currentCommit['email'] = $line;
		} elseif ($currentCommit['date'] === null) {
			// Fourth line is date (ISO format)
			$currentCommit['date'] = $line;
		} elseif ($currentCommit['message'] === null) {
			// Fifth line is commit message
			$currentCommit['message'] = $line;
			$isParsingBody = true;
		} else {
			// Subsequent lines are message body
			$bodyLines[] = $line;
		}
	}

	// Handle last commit if output doesn't end with separator
	if ($currentCommit !== null) {
		$currentCommit['message_body'] = trim(implode("\n", $bodyLines));
		$commits[] = $currentCommit;
	}

	die(json_encode([
		"success" => true,
		"limit" => $limit,
		"offset" => $offset,
		"count" => count($commits),
		"commits" => $commits
	], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

} catch (Exception $e) {
	die(json_encode([
		"success" => false,
		"error" => $e->getMessage()
	], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
?>
