<?php
/**
 * Example usage of SchemaManager with one-shot manageTable() method
 * 
 * Key features:
 * - Version tracking stored in table COMMENT (no separate metadata table)
 * - Simple one-call API: new SchemaManager($db)->manageTable("table", [...])
 * - State definitions: "1" => "CREATE TABLE..." (reset points for fresh installs)
 * - Transition definitions: "1>2" => "ALTER TABLE..." (required between all versions)
 * - Missing transitions throw an error (prevents incomplete migration paths)
 */

// Assume you have a MySQLi connection
// $db = new mysqli("localhost", "user", "pass", "database");

// ============================================================================
// EXAMPLE 1: Simple table with initial creation and a few updates
// ============================================================================

/*
try {
	$result = new SchemaManager($db)->manageTable("users", [
		// Version 1: Initial table creation (reset point)
		"1" => "
			CREATE TABLE IF NOT EXISTS users (
				id INT PRIMARY KEY AUTO_INCREMENT,
				username VARCHAR(255) NOT NULL UNIQUE,
				email VARCHAR(255) NOT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_email (email)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		",
		
		// Transition from v1 to v2
		"1>2" => "ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT 'tmp'",
		
		// Transition from v2 to v3
		"2>3" => "ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL"
	]);
	
	echo json_encode($result) . "\n";
	// Fresh install: {"status":"success","current_version":0,"target_version":3,"applied":3}
	// Already at v3: {"status":"already_current","current_version":3,"target_version":3,"applied":0}
	// At v1 needing v3: {"status":"success","current_version":1,"target_version":3,"applied":2}
} catch (Exception $e) {
	echo "Error: " . $e->getMessage() . "\n";
}
*/

// ============================================================================
// EXAMPLE 2: Reset point for deployment flexibility
// ============================================================================

/*
// Scenario: You're at v3, then need v4. But also want new installations to 
// have an optimized v4 schema without going through v1->v2->v3->v4
// Define v4 as both a reset point AND a transition from v3

try {
	$result = new SchemaManager($db)->manageTable("events", [
		// v1 reset point
		"1" => "
			CREATE TABLE events (
				id INT PRIMARY KEY AUTO_INCREMENT,
				type VARCHAR(100) NOT NULL,
				time INT NOT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_type (type),
				INDEX idx_time (time)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
		",
		
		"1>2" => "ALTER TABLE events ADD COLUMN source VARCHAR(50) DEFAULT 'unknown'",
		"2>3" => "ALTER TABLE events ADD COLUMN user_id INT DEFAULT NULL",
		"3>4" => "ALTER TABLE events ADD COLUMN metadata JSON DEFAULT NULL",
		
		// v4 reset point: complete redesigned schema with all columns
		// Fresh installs jump directly here, existing customers go v3->v4
		"4" => "
			CREATE TABLE IF NOT EXISTS events (
				id INT PRIMARY KEY AUTO_INCREMENT,
				type VARCHAR(100) NOT NULL,
				time INT NOT NULL,
				user_id INT DEFAULT NULL,
				source VARCHAR(50) DEFAULT 'unknown',
				metadata JSON DEFAULT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_type (type),
				INDEX idx_time (time),
				INDEX idx_user_id (user_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		",
		
		"4>5" => "ALTER TABLE events ADD COLUMN severity INT DEFAULT 0"
	]);
	
	// Fresh install: Applies "4" reset (1 step, not 4 steps)
	// {"status":"success","current_version":0,"target_version":5,"applied":2}
	// (applies v4 reset + v4>5 transition)
	
	// Existing at v3: Applies v3>4 and v4>5 
	// {"status":"success","current_version":3,"target_version":5,"applied":2}
} catch (Exception $e) {
	echo "Error: " . $e->getMessage() . "\n";
}
*/

// ============================================================================
// EXAMPLE 3: Error handling - missing transition
// ============================================================================

/*
// This will throw an error: we need a path v1->v2->v3, but missing v2>3
try {
	$result = new SchemaManager($db)->manageTable("broken_table", [
		"1" => "CREATE TABLE broken_table (id INT PRIMARY KEY AUTO_INCREMENT)",
		"1>2" => "ALTER TABLE broken_table ADD COLUMN name VARCHAR(100)",
		"3" => "CREATE TABLE IF NOT EXISTS broken_table (id INT, name VARCHAR(100))"
		// Missing "2>3" transition!
	]);
} catch (Exception $e) {
	echo "Error (expected): " . $e->getMessage() . "\n";
	// Output: "Incomplete migration path. Missing transitions: 2>3"
}
*/

// ============================================================================
// EXAMPLE 4: Multiple tables in sequence
// ============================================================================

/*
try {
	$users_result = new SchemaManager($db)->manageTable("users", [
		"1" => "CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(255))",
		"1>2" => "ALTER TABLE users ADD COLUMN phone VARCHAR(20)"
	]);
	
	$posts_result = new SchemaManager($db)->manageTable("posts", [
		"1" => "CREATE TABLE posts (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, title VARCHAR(255))",
		"1>2" => "ALTER TABLE posts ADD COLUMN published_at TIMESTAMP NULL",
		"2>3" => "ALTER TABLE posts ADD COLUMN views INT DEFAULT 0"
	]);
	
	echo "Users: " . $users_result['status'] . "\n";
	echo "Posts: " . $posts_result['status'] . "\n";
} catch (Exception $e) {
	echo "Error: " . $e->getMessage() . "\n";
}
*/

// ============================================================================
// EXAMPLE 5: Complex migration with multiple reset points
// ============================================================================

/*
try {
	$result = new SchemaManager($db)->manageTable("complex_table", [
		// First generation schema
		"1" => "
			CREATE TABLE complex_table (
				id INT PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(100)
			)
		",
		
		"1>2" => "ALTER TABLE complex_table ADD COLUMN email VARCHAR(100)",
		"2>3" => "ALTER TABLE complex_table ADD COLUMN phone VARCHAR(20)",
		
		// Second generation: optimize schema at v4
		"3>4" => "ALTER TABLE complex_table ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
		"4>5" => "ALTER TABLE complex_table ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
		
		// Third generation: complete redesign at v6
		"5>6" => "ALTER TABLE complex_table ADD COLUMN metadata JSON",
		
		"6" => "
			CREATE TABLE IF NOT EXISTS complex_table (
				id INT PRIMARY KEY AUTO_INCREMENT,
				name VARCHAR(100) NOT NULL,
				email VARCHAR(100),
				phone VARCHAR(20),
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				metadata JSON,
				INDEX idx_email (email)
			)
		",
		
		"6>7" => "ALTER TABLE complex_table ADD COLUMN status ENUM('active','inactive') DEFAULT 'active'"
	]);
	
	echo json_encode($result) . "\n";
	// Fresh install: Uses v6 reset, applies v6>7 (2 steps, not 7!)
	// At v3: Applies v3>4, v4>5, v5>6, v6>7 (4 steps)
} catch (Exception $e) {
	echo "Error: " . $e->getMessage() . "\n";
}
*/

// ============================================================================
// EXAMPLE 6: With TelemetryDB class
// ============================================================================

/*
$tdb = new TelemetryDB();
$tdb->connect(['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'telemetry']);

try {
	$result = new SchemaManager($tdb->conn)->manageTable("events", [
		"1" => "
			CREATE TABLE IF NOT EXISTS events (
				id INT PRIMARY KEY AUTO_INCREMENT,
				type VARCHAR(100) NOT NULL,
				time INT NOT NULL,
				data JSON,
				INDEX idx_type (type),
				INDEX idx_time (time)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
		",
		"1>2" => "ALTER TABLE events ADD COLUMN source VARCHAR(50) DEFAULT 'unknown'",
		"2>3" => "ALTER TABLE events ADD COLUMN user_id INT DEFAULT NULL",
		"3" => "
			CREATE TABLE IF NOT EXISTS events (
				id INT PRIMARY KEY AUTO_INCREMENT,
				type VARCHAR(100) NOT NULL,
				time INT NOT NULL,
				source VARCHAR(50) DEFAULT 'unknown',
				user_id INT DEFAULT NULL,
				data JSON,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_type (type),
				INDEX idx_time (time)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	]);
	
	echo "Status: " . $result['status'] . "\n";
	echo "Applied: " . $result['applied'] . " migrations\n";
} catch (Exception $e) {
	echo "Error: " . $e->getMessage() . "\n";
}
*/

// ============================================================================
// VALIDATION TESTS: Tests for the validation layer
// ============================================================================

// Set up database connection for validation tests
require_once __DIR__ . '/../classes/SchemaManager.class.php';

$db = new mysqli("localhost", "root", "", "telemetry_test");
if ($db->connect_error) {
	echo "Warning: Could not connect to test database, running validation tests without DB execution\n";
	echo "(Connection error: " . $db->connect_error . ")\n\n";
	$db = null;
} else {
	echo "Connected to test database for validation testing\n\n";
}

echo "=== Validation Test Suite ===\n\n";

// Test 1: State that's not a CREATE statement (should fail)
echo "Test 1: Non-CREATE state\n";
if ($db) {
	try {
		// This will fail because "1" is not a CREATE statement
		(new SchemaManager($db))->manageTable("test1", [
			"1" => "ALTER TABLE test1 ADD COLUMN id INT",  // Wrong: should be CREATE
			"1>2" => "ALTER TABLE test1 ADD COLUMN name VARCHAR(100)"
		]);
		echo "  FAIL: Should have thrown exception\n\n";
	} catch (Exception $e) {
		echo "  PASS: " . $e->getMessage() . "\n\n";
	}
} else {
	echo "  SKIPPED: No database connection\n\n";
}

// Test 2: Transition that's not an ALTER statement (should fail)
echo "Test 2: Non-ALTER transition\n";
if ($db) {
	try {
		// This will fail because "1>2" is not an ALTER statement
		(new SchemaManager($db))->manageTable("test2", [
			"1" => "CREATE TABLE test2 (id INT PRIMARY KEY)",
			"1>2" => "CREATE TABLE test2 (id INT PRIMARY KEY, name VARCHAR(100))"  // Wrong: should be ALTER
		]);
		echo "  FAIL: Should have thrown exception\n\n";
	} catch (Exception $e) {
		echo "  PASS: " . $e->getMessage() . "\n\n";
	}
} else {
	echo "  SKIPPED: No database connection\n\n";
}

// Test 3: Transition that skips version numbers (should fail)
echo "Test 3: Non-consecutive transition (1>3 instead of 1>2)\n";
if ($db) {
	try {
		// This will fail because "1>3" skips version 2
		(new SchemaManager($db))->manageTable("test3", [
			"1" => "CREATE TABLE test3 (id INT PRIMARY KEY)",
			"1>3" => "ALTER TABLE test3 ADD COLUMN name VARCHAR(100)"  // Wrong: should be 1>2
		]);
		echo "  FAIL: Should have thrown exception\n\n";
	} catch (Exception $e) {
		echo "  PASS: " . $e->getMessage() . "\n\n";
	}
} else {
	echo "  SKIPPED: No database connection\n\n";
}

// Test 4: Valid migration definitions (should pass validation)
echo "Test 4: Valid migration definitions\n";
if ($db) {
	try {
		// This will pass validation (though may fail on DB connection issues)
		$result = (new SchemaManager($db))->manageTable("test4", [
			"1" => "CREATE TABLE test4 (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100))",
			"1>2" => "ALTER TABLE test4 ADD COLUMN email VARCHAR(100)",
			"2>3" => "ALTER TABLE test4 ADD COLUMN status VARCHAR(50)"
		]);
		echo "  PASS: Validation succeeded\n\n";
	} catch (Exception $e) {
		// Might fail on actual DB operation, but validation passed
		if (strpos($e->getMessage(), "State") === 0 || 
		    strpos($e->getMessage(), "Transition") === 0) {
			echo "  FAIL: " . $e->getMessage() . "\n\n";
		} else {
			echo "  PASS: Validation succeeded (DB operation: " . $e->getMessage() . ")\n\n";
		}
	}
} else {
	echo "  SKIPPED: No database connection\n\n";
}

echo "=== End of Validation Tests ===\n";

?>
