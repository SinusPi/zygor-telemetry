<?php
// One-time test script for Config class
require_once __DIR__ . '/../classes/Config.class.php';

// ANSI color codes
define('ANSI_GREEN', "\033[32m");
define('ANSI_RED', "\033[31m");
define('ANSI_YELLOW', "\033[33m");
define('ANSI_RESET', "\033[0m");
define('ANSI_BOLD', "\033[1m");

// Icons with colors
define('PASS_ICON', ANSI_GREEN . '✓' . ANSI_RESET);
define('FAIL_ICON', ANSI_RED . 'X' . ANSI_RESET);

// Run tests inline
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

// Test 1: Single config add and get
$c = new Config();
$c->add(['key1' => 'value1']);
$expect('Single config add and get', $c->get(), ['key1' => 'value1']);

// Test 2: Priority-based merging
$c = new Config();
$c->add(['key' => 'low'], 1);
$c->add(['key' => 'high'], 2);
$expect('Priority-based merging', $c->get()['key'], 'high');

// Test 3: Recursive array merging
$c = new Config();
$c->add(['database' => ['host' => 'localhost', 'port' => 3306]]);
$c->add(['database' => ['port' => 5432]], 1);
$result = $c->get();
$expect('Recursive array merging', $result['database'], ['host' => 'localhost', 'port' => 5432]);

// Test 4: getValue with dot notation
$c = new Config();
$c->add(['database' => ['host' => 'localhost', 'credentials' => ['user' => 'admin']]]);
$expect('getValue with dot notation', $c->getValue('database.credentials.user'), 'admin');

// Test 5: getValue with default value
$c = new Config();
$c->add(['key' => 'value']);
$expect('getValue with default value', $c->getValue('nonexistent.key', 'default_value'), 'default_value');

// Test 6: Named config with priorities
$c = new Config();
$c->add(['key' => 'value1'], 1, 'config_a');
$c->add(['key' => 'value2'], 2, 'config_b');
$expect('Named config with priorities', $c->get()['key'], 'value2');

// Test 7: Cache invalidation on add
$c = new Config();
$c->add(['key' => 'value1']);
$r1 = $c->get()['key'];
$c->add(['key' => 'value2'], 1);
$r2 = $c->get()['key'];
$expect('Cache invalidation on add', [$r1, $r2], ['value1', 'value2']);

// Test 8: ArrayAccess offsetExists
$c = new Config();
$c->add(['database' => ['host' => 'localhost']]);
$expect('ArrayAccess offsetExists', isset($c['database']), true);

// Test 9: ArrayAccess offsetGet
$c = new Config();
$c->add(['database' => ['host' => 'localhost']]);
$expect('ArrayAccess offsetGet', $c['database']['host'], 'localhost');

// Test 10: ArrayAccess offsetGet with non-existent key
$c = new Config();
$c->add(['key' => 'value']);
$expect('ArrayAccess offsetGet non-existent', $c['nonexistent'], null);

// Test 11: ArrayAccess offsetSet throws exception
$c = new Config();
$c->add(['key' => 'value']);
try {
	$c['key'] = 'new_value';
	$expect('ArrayAccess offsetSet throws', false, true);
} catch (Exception $e) {
	$expect('ArrayAccess offsetSet throws', true, true);
}

// Test 12: ArrayAccess offsetUnset throws exception
$c = new Config();
$c->add(['key' => 'value']);
try {
	unset($c['key']);
	$expect('ArrayAccess offsetUnset throws', false, true);
} catch (Exception $e) {
	$expect('ArrayAccess offsetUnset throws', true, true);
}

$passed = count(array_filter($tests, function($t) { return $t['status'] === 'PASS'; }));
$failed = count($tests) - $passed;
$results = [
	'passed' => $passed,
	'failed' => $failed,
	'total' => count($tests),
	'tests' => $tests
];

// Display results
echo "=== Config Self-Test Results ===\n\n";
echo "Total Tests: " . $results['total'] . "\n";
echo "Passed: " . $results['passed'] . " " . PASS_ICON . "\n";
echo "Failed: " . $results['failed'] . " " . FAIL_ICON . "\n\n";

if ($results['total'] > 0) {
	echo "Test Details:\n";
	echo str_repeat("-", 60) . "\n";
	foreach ($results['tests'] as $test) {
		$icon = ($test['status'] === 'PASS') ? PASS_ICON : FAIL_ICON;
		echo "[" . $icon . "] " . $test['name'] . "\n";
		if ($test['status'] === 'FAIL') {
			echo "    Expected: " . var_export($test['expected'], true) . "\n";
			echo "    Actual:   " . var_export($test['actual'], true) . "\n";
		}
	}
	echo str_repeat("-", 60) . "\n";
}

$result_icon = ($results['failed'] === 0) ? PASS_ICON : FAIL_ICON;
echo "\nResult: " . ($results['failed'] === 0 ? "ALL TESTS PASSED " : "SOME TESTS FAILED ") . $result_icon . "\n";
