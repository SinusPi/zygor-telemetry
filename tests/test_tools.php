<?php
// Tests for Tools\Date class
require_once __DIR__ . '/../classes/Tools.class.php';

use Zygor\Telemetry\Tools\Date as DateTools;

// ANSI color codes
define('ANSI_GREEN', "\033[32m");
define('ANSI_RED',   "\033[31m");
define('ANSI_RESET', "\033[0m");

define('PASS_ICON', ANSI_GREEN . '✓' . ANSI_RESET);
define('FAIL_ICON', ANSI_RED   . 'X' . ANSI_RESET);

$tests = [];
$expect = function($name, $actual, $expected) use (&$tests) {
	if ($actual === $expected) {
		$tests[] = ['name' => $name, 'status' => 'PASS'];
		return true;
	} else {
		$tests[] = ['name' => $name, 'status' => 'FAIL', 'expected' => $expected, 'actual' => $actual];
		return false;
	}
};

function collect_gen($gen) {
	return iterator_to_array($gen, false);
}

// --- Date::gen_days ---
// Note: gen_days takes Y-m-d strings and the end is exclusive.

// Single day (one iteration: from < next_day(from))
$expect('Single day range',
	collect_gen(DateTools::gen_days("2024-03-15", "2024-03-16")),
	["2024-03-15"]);

// Three consecutive days
$expect('Three consecutive days',
	collect_gen(DateTools::gen_days("2024-01-01", "2024-01-04")),
	["2024-01-01","2024-01-02","2024-01-03"]);

// Empty range (from == to, exclusive end)
$expect('Empty range (from == to)',
	collect_gen(DateTools::gen_days("2024-01-03", "2024-01-03")),
	[]);

// Reversed range yields nothing
$expect('Reversed range is empty',
	collect_gen(DateTools::gen_days("2024-01-03", "2024-01-01")),
	[]);

// Month boundary (Jan 31, yields Jan 31 only up to Feb 1 exclusive)
$expect('Month boundary',
	collect_gen(DateTools::gen_days("2024-01-31", "2024-02-02")),
	["2024-01-31","2024-02-01"]);

// Leap day (Feb 28 to Mar 2 in leap year)
$expect('Leap day 2024',
	collect_gen(DateTools::gen_days("2024-02-28", "2024-03-02")),
	["2024-02-28","2024-02-29","2024-03-01"]);

// Non-leap year skips Feb 29
$expect('Non-leap year Feb',
	collect_gen(DateTools::gen_days("2023-02-28", "2023-03-02")),
	["2023-02-28","2023-03-01"]);

// Year boundary (Dec 31 → Jan 1)
$expect('Year boundary',
	collect_gen(DateTools::gen_days("2023-12-31", "2024-01-02")),
	["2023-12-31","2024-01-01"]);

// --- Summary ---

$pass = 0;
$fail = 0;
foreach ($tests as $t) {
	if ($t['status'] === 'PASS') $pass++;
	else $fail++;
}

foreach ($tests as $t) {
	if ($t['status'] === 'PASS') {
		echo PASS_ICON . " {$t['name']}\n";
	} else {
		echo FAIL_ICON . " {$t['name']}\n";
		echo "   Expected: " . json_encode($t['expected']) . "\n";
		echo "   Actual:   " . json_encode($t['actual']) . "\n";
	}
}

echo "\n$pass passed, $fail failed.\n";
exit($fail > 0 ? 1 : 0);
