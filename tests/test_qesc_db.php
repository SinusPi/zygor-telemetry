<?php
/**
 * Test script for qesc escaping functionality
 * 
 * Tests various escaping scenarios including:
 * - Basic string, int, float escaping
 * - Nullable format handling
 * - Array escaping
 * - SQL injection prevention
 * - Special character handling
 */

// ANSI color codes
define("C_PASS", "\x1b[32m");    // Green
define("C_FAIL", "\x1b[31m");    // Red
define("C_TEST", "\x1b[38;5;4m"); // Blue
define("C_INFO", "\x1b[33m");    // Yellow
define("C_R", "\x1b[0m");        // Reset

// Change to telemetry root directory
chdir(dirname(__DIR__));

// Load required classes and config
require_once 'loader.inc.php';
require_once 'includes/zygor.class.inc.php';

// Initialize Telemetry with database connection
try {
	Telemetry::startup();
} catch (Exception $e) {
	die("Failed to initialize telemetry: " . $e->getMessage() . "\n");
}

echo C_TEST . "=== QESC Escaping Test Suite ===" . C_R . "\n";
echo "Database connected: " . C_INFO . Telemetry::$CFG['DB']['db'] . C_R . "\n\n";

$db = Telemetry::$db;
$testsPassed = 0;
$testsFailed = 0;

function assert_equals($expected, $actual, $testName) {
	global $testsPassed, $testsFailed;
	if ($expected === $actual) {
		echo C_PASS . "✓" . C_R . " $testName\n";
		$testsPassed++;
		return true;
	} else {
		echo C_FAIL . "✗" . C_R . " $testName\n";
		echo "  " . C_INFO . "Expected:" . C_R . " $expected\n";
		echo "  " . C_INFO . "Got:" . C_R . "      $actual\n";
		$testsFailed++;
		return false;
	}
}

assert_equals('name="John Doe"', $db->qesc("name={s}", "John Doe"), "[1] Simple string");

assert_equals('name="O\\\'Reilly"', $db->qesc("name={s}", 'O\'Reilly'), "[2] String with single quote");

assert_equals('name="He said \\"Hello\\""', $db->qesc("name={s}", 'He said "Hello"'), "[3] String with double quotes");

assert_equals('name=" OR 1=1--"', $db->qesc("name={s}", ' OR 1=1--'), "[3a] SQL injection - OR 1=1");

assert_equals("name=\"\\'; DROP TABLE users;--\"", $db->qesc("name={s}", '\'; DROP TABLE users;--'), "[3b] SQL injection - DROP TABLE");

assert_equals('name=" UNION SELECT * FROM admin--"', $db->qesc("name={s}", ' UNION SELECT * FROM admin--'), "[3c] SQL injection - UNION");

assert_equals('name=" AND user_id={d}--"', $db->qesc("name={s}", ' AND user_id={d}--'), "[3d] SQL injection - template");

assert_equals('age=25', $db->qesc("age={d}", 25), "[4] Integer value");

assert_equals('age=0', $db->qesc("age={d}", "not_a_number"), "[5] Non-numeric string to int");

assert_equals('price=19.99', $db->qesc("price={f}", 19.99), "[6] Float value");

assert_equals('name=NULL', $db->qesc("name={sn}", null), "[7] Null string nullable format");

assert_equals('name="John"', $db->qesc("name={sn}", "John"), "[8] String with nullable format");

assert_equals('age=NULL', $db->qesc("age={dn}", null), "[9] Null integer nullable format");

assert_equals('age=NULL', $db->qesc("age={dn}", "invalid"), "[10] Invalid int in nullable format");

assert_equals('name=""', $db->qesc("name={sn}", ""), "[10b] Empty string in nullable format");

assert_equals('price=NULL', $db->qesc("price={fn}", "not_a_number"), "[10c] Invalid float in nullable format");

assert_equals('id IN (1,2,3)', $db->qesc("id IN ({da})", [1, 2, 3]), "[11] Array of integers");

assert_equals('name IN ("John Doe","Mary Sue")', $db->qesc("name IN ({sa})", ["John Doe", "Mary Sue"]), "[12] Array of strings");

assert_equals('name IN ("O\\\'Brien","He said \\"Hi\\"")', $db->qesc("name IN ({sa})", ["O'Brien", 'He said "Hi"']), "[13] Array with special chars");

assert_equals('name IN (" OR 1=1","admin")', $db->qesc("name IN ({sa})", [" OR 1=1", "admin"]), "[13a] Array injection - OR 1=1");

assert_equals('name IN ("' . chr(92) . chr(39) . '--"," UNION SELECT")', $db->qesc("name IN ({sa})", ["'--", " UNION SELECT"]), "[13b] Array injection - comments");

assert_equals('id IN (1,999,3)', $db->qesc("id IN ({da})", [1, 999, 3]), "[14] Array with large number");

assert_equals('id IN ()', $db->qesc("id IN ({da})", []), "[15] Empty array");

assert_equals('name="John",age=25,active="yes"', $db->qesc("name={s},age={d},active={s}", "John", 25, "yes"), "[16] Multiple parameters");

assert_equals('name IN ("John","Mary")', $db->qesc("name IN ({sna})", ["John", "Mary"]), "[17] Array of names nullable format");

assert_equals('name IN ("John",NULL,"Mary")', $db->qesc("name IN ({sna})", ["John", null, "Mary"]), "[18] Array with null value");

assert_equals('name IN ("John","","Mary")', $db->qesc("name IN ({sna})", ["John", "", "Mary"]), "[19] Array with empty string");

try {
	$db->qesc("name={s}");
	echo C_FAIL . "✗" . C_R . " [20] Expected exception for too few args\n";
	$testsFailed++;
} catch (Exception $e) {
	echo C_PASS . "✓" . C_R . " [20] Exception thrown for too few args\n";
	$testsPassed++;
}

try {
	$db->qesc("id IN ({da})", "not_an_array");
	echo C_FAIL . "✗" . C_R . " [21] Expected exception for non-array input\n";
	$testsFailed++;
} catch (Exception $e) {
	echo C_PASS . "✓" . C_R . " [21] Exception thrown for non-array input\n";
	$testsPassed++;
}

// Summary
echo C_TEST . "\n=== Test Summary ===" . C_R . "\n";
echo "Passed: " . C_PASS . $testsPassed . C_R . "\n";
echo "Failed: " . C_FAIL . $testsFailed . C_R . "\n";
echo "Total:  " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
	echo "\n" . C_PASS . "✓ All tests passed!" . C_R . "\n";
	exit(0);
} else {
	echo "\n" . C_FAIL . "✗ Some tests failed." . C_R . "\n";
	exit(1);
}
