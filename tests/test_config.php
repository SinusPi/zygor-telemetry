<?php
// One-time test script for Config class
require_once __DIR__ . '/../classes/Config.class.php';

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

$passed = count(array_filter($tests, fn($t) => $t['status'] === 'PASS'));
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
echo "Passed: " . $results['passed'] . " ✓\n";
echo "Failed: " . $results['failed'] . " ✗\n\n";

if ($results['total'] > 0) {
	echo "Test Details:\n";
	echo str_repeat("-", 60) . "\n";
	foreach ($results['tests'] as $test) {
		$status_icon = ($test['status'] === 'PASS') ? '✓' : '✗';
		echo "[$status_icon] {$test['name']}\n";
		if ($test['status'] === 'FAIL') {
			echo "    Expected: " . var_export($test['expected'], true) . "\n";
			echo "    Actual:   " . var_export($test['actual'], true) . "\n";
		}
	}
	echo str_repeat("-", 60) . "\n";
}

echo "\nResult: " . ($results['failed'] === 0 ? "ALL TESTS PASSED ✓" : "SOME TESTS FAILED ✗") . "\n";
