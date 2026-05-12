<?php
/**
 * Tests for Logger class.
 * Requires a live DB connection (uses Telemetry::startup()).
 */

chdir(dirname(__DIR__));

require_once 'loader.inc.php';
require_once 'includes/zygor.class.inc.php';

use Zygor\Telemetry\Telemetry;
use Zygor\Telemetry\Logger;

// ANSI
define('C_PASS',  "\x1b[32m");
define('C_FAIL',  "\x1b[31m");
define('C_INFO',  "\x1b[33m");
define('C_RESET', "\x1b[0m");

$pass = 0;
$fail = 0;

function expect($name, $actual, $expected) {
	global $pass, $fail;
	if ($actual === $expected) {
		echo C_PASS . "✓" . C_RESET . " $name\n";
		$pass++;
	} else {
		echo C_FAIL . "✗" . C_RESET . " $name\n";
		echo "  " . C_INFO . "Expected:" . C_RESET . " " . json_encode($expected) . "\n";
		echo "  " . C_INFO . "Actual:  " . C_RESET . " " . json_encode($actual) . "\n";
		$fail++;
	}
}

function expect_true($name, $actual) { expect($name, (bool)$actual, true); }
function expect_false($name, $actual) { expect($name, (bool)$actual, false); }

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$tmp_log = sys_get_temp_dir() . '/test_logger_' . getmypid() . '.log';

try {
	Telemetry::startup(['verbose' => false, 'verbose_flags' => []]);
} catch (Exception $e) {
	die("Startup failed: " . $e->getMessage() . "\n");
}

// Re-init Logger with a temp log file so we can inspect file output
Logger::init([
	'tag'           => 'TEST',
	'verbose'       => false,
	'verbose_flags' => [],
	'log_path'      => $tmp_log,
]);

// ---------------------------------------------------------------------------
// Helper: read lines of the temp log that contain a sentinel string
// ---------------------------------------------------------------------------
function log_lines_containing($needle) {
	global $tmp_log;
	if (!file_exists($tmp_log)) return [];
	return array_values(array_filter(
		explode("\n", file_get_contents($tmp_log)),
		function($line) use ($needle) { return strpos($line, $needle) !== false; }
	));
}

// Helper: count DB log rows containing a message (substring match)
function db_log_count($msg) {
	$q = Telemetry::$db->query("SELECT COUNT(*) FROM `log` WHERE `message` LIKE {s}", '%' . $msg . '%');
	$row = $q->fetch_array();
	return (int)$row[0];
}

echo "\n=== Logger Tests ===\n\n";

// ---------------------------------------------------------------------------
// 1. init() sets static properties
// ---------------------------------------------------------------------------
expect('init: tag set',           Logger::$tag,           'TEST');
expect('init: verbose false',     Logger::$verbose,       false);
expect('init: verbose_flags empty', Logger::$verbose_flags, []);
expect('init: log_path set',      Logger::$log_path,      $tmp_log);

// ---------------------------------------------------------------------------
// 2. log() writes to file
// ---------------------------------------------------------------------------
$sentinel = 'LOG_FILE_TEST_' . uniqid();
Logger::log($sentinel);
$lines = log_lines_containing($sentinel);
expect_true('log: line written to file', count($lines) === 1);
// format check: timestamp, tag, level
expect_true('log: file line contains [TEST]',  strpos($lines[0], '[TEST]') !== false);
expect_true('log: file line contains <MAIN>',  strpos($lines[0], '<MAIN>') !== false);
expect_true('log: file line contains sentinel', strpos($lines[0], $sentinel) !== false);
// crude timestamp check: starts with 20YY-
expect_true('log: file line starts with date', preg_match('/^20\d\d-/', $lines[0]) === 1);

// ---------------------------------------------------------------------------
// 3. log() writes to DB
// ---------------------------------------------------------------------------
expect('log: row in DB', db_log_count($sentinel), 1);

// calling log() again should add a second row (not upsert)
Logger::log($sentinel);
expect('log: second call adds second row', db_log_count($sentinel), 2);

// ---------------------------------------------------------------------------
// 4. log() respects $level in file and DB
// ---------------------------------------------------------------------------
$sentinel2 = 'LOG_LEVEL_TEST_' . uniqid();
Logger::log($sentinel2, null, 'WARNING');
$lines2 = log_lines_containing($sentinel2);
expect_true('log: custom level in file', strpos($lines2[0], '<WARNING>') !== false);
$q = Telemetry::$db->query("SELECT `level` FROM `log` WHERE `message` = {s} LIMIT 1", $sentinel2);
$row = $q->fetch_array();
expect('log: custom level in DB', $row[0], 'WARNING');

// ---------------------------------------------------------------------------
// 5. vlog() is suppressed when verbose = false
// ---------------------------------------------------------------------------
$sentinel3 = 'VLOG_SILENT_' . uniqid();
Logger::$verbose = false;
Logger::vlog($sentinel3);
expect('vlog: silent when verbose=false (file)', count(log_lines_containing($sentinel3)), 0);
expect('vlog: silent when verbose=false (DB)',   db_log_count($sentinel3), 0);

// ---------------------------------------------------------------------------
// 6. vlog() emits when verbose = true
// ---------------------------------------------------------------------------
$sentinel4 = 'VLOG_EMIT_' . uniqid();
Logger::$verbose = true;
Logger::vlog($sentinel4);
expect('vlog: emits when verbose=true (file)', count(log_lines_containing($sentinel4)), 1);
expect('vlog: emits when verbose=true (DB)',   db_log_count($sentinel4), 1);
Logger::$verbose = false; // restore

// ---------------------------------------------------------------------------
// 7. vflog() is suppressed when flag not in list
// ---------------------------------------------------------------------------
$sentinel5 = 'VFLOG_NOFLAG_' . uniqid();
Logger::$verbose = true;
Logger::$verbose_flags = ['other_flag'];
Logger::vflog('my_flag', $sentinel5);
expect('vflog: silent when flag absent (file)', count(log_lines_containing($sentinel5)), 0);
expect('vflog: silent when flag absent (DB)',   db_log_count($sentinel5), 0);

// ---------------------------------------------------------------------------
// 8. vflog() emits when flag is in list
// ---------------------------------------------------------------------------
$sentinel6 = 'VFLOG_WITHFLAG_' . uniqid();
Logger::$verbose_flags = ['my_flag'];
Logger::vflog('my_flag', $sentinel6);
expect('vflog: emits when flag present (file)', count(log_lines_containing($sentinel6)), 1);
expect('vflog: emits when flag present (DB)',   db_log_count($sentinel6), 1);

Logger::$verbose = false;
Logger::$verbose_flags = [];

// ---------------------------------------------------------------------------
// 9. vflog() is suppressed when verbose = false even if flag matches
// ---------------------------------------------------------------------------
$sentinel7 = 'VFLOG_NOVERBOSE_' . uniqid();
Logger::$verbose = false;
Logger::$verbose_flags = ['my_flag'];
Logger::vflog('my_flag', $sentinel7);
expect('vflog: silent when verbose=false (file)', count(log_lines_containing($sentinel7)), 0);
expect('vflog: silent when verbose=false (DB)',   db_log_count($sentinel7), 0);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
@unlink($tmp_log);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n$pass passed, $fail failed.\n";
exit($fail > 0 ? 1 : 0);
