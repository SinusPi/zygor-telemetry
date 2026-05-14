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
		"cd %s && git log --pretty=tformat:commit=%%H%%nauthor=%%an%%nemail=%%ae%%ndate=%%ai%%nsubject=%%s%%nbody=%%n%%b%%n--- --skip %d -n %d",
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
			if ($currentCommit !== null) {
				$currentCommit['message_body'] = trim(implode("\n", $bodyLines));
				$commits[] = $currentCommit;
			}
			$currentCommit = null;
			$bodyLines = [];
			$isParsingBody = false;
		} elseif ($isParsingBody) {
			$bodyLines[] = $line;
		} elseif (strncmp($line, 'commit=', 7) === 0) {
			$currentCommit = [
				'hash' => substr($line, 7),
				'author' => null,
				'email' => null,
				'date' => null,
				'message' => null,
				'message_body' => ''
			];
			$bodyLines = [];
			$isParsingBody = false;
		} elseif (strncmp($line, 'author=', 7) === 0) {
			$currentCommit['author'] = substr($line, 7);
		} elseif (strncmp($line, 'email=', 6) === 0) {
			$currentCommit['email'] = substr($line, 6);
		} elseif (strncmp($line, 'date=', 5) === 0) {
			$currentCommit['date'] = substr($line, 5);
		} elseif (strncmp($line, 'subject=', 8) === 0) {
			$currentCommit['message'] = substr($line, 8);
		} elseif (strncmp($line, 'body=', 5) === 0) {
			$isParsingBody = true;
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
