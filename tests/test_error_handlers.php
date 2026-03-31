<?php
/**
 * Test script for Telemetry error handlers
 * 
 * Usage: php test_error_handlers.php <error_type>
 * 
 * Error types:
 *   1 - Undefined function call
 *   2 - Undefined variable access
 *   3 - Type error (passing wrong type)
 *   4 - Throw exception
 *   5 - User error via trigger_error
 *   6 - Division by zero
 *   7 - Array access on non-array
 *   8 - Null method call
 *   9 - Infinite loop (timeout)
 *   10 - Manual fatal error
 *   11 - JSON mode: Undefined function call
 *   12 - JSON mode: Exception throw
 *   13 - JSON mode: User error via trigger_error
 *   14 - JSON mode: Array access on non-array
 *   15 - JSON mode: Null method call
 */

chdir(dirname(__DIR__));
require_once 'loader.inc.php';
require_once 'includes/zygor.class.inc.php';

// Initialize Telemetry (sets up error/exception handlers)
try {
    Telemetry::startup();
} catch (Exception $e) {
    die("Failed to initialize telemetry: " . $e->getMessage() . "\n");
}

$error_type = intval(isset($argv[1]) ? $argv[1] : 0);

echo "=== Testing Error Handler Type: $error_type ===\n";

// NO try-catch - let Telemetry error handlers catch these
switch ($error_type) {
    case 1:
        echo "Triggering: Undefined function call\n";
        undefined_function_that_does_not_exist();
        break;
        
    case 2:
        echo "Triggering: Undefined variable access\n";
        echo $undefined_variable_xyz;
        break;
        
    case 3:
        echo "Triggering: Type error (strlen expects string, passing array)\n";
        strlen([1, 2, 3]);
        break;
        
    case 4:
        echo "Triggering: Exception throw\n";
        throw new Exception("Test exception from error handler test");
        break;
        
    case 5:
        echo "Triggering: User error via trigger_error\n";
        trigger_error("Test user error", E_USER_ERROR);
        break;
        
    case 6:
        echo "Triggering: Division by zero\n";
        $result = 10 / 0;
        break;
        
    case 7:
        echo "Triggering: Array access on non-array\n";
        $nonarray = "string";
        $value = $nonarray[5]['nested']['key'];
        break;
        
    case 8:
        echo "Triggering: Null method call\n";
        $null_obj = null;
        $null_obj->method();
        break;
        
    case 9:
        echo "Triggering: Infinite loop (timeout)\n";
        set_time_limit(3);
        while (true) {
            usleep(100);
        }
        break;
        
    case 10:
        echo "Triggering: Manual exit with error code\n";
        die("Manual fatal error");
        break;
        
    case 11:
        echo "Triggering JSON mode: Undefined function call\n";
        Telemetry::$json = true;
        undefined_function_that_does_not_exist();
        break;
        
    case 12:
        echo "Triggering JSON mode: Exception throw\n";
        Telemetry::$json = true;
        throw new Exception("Test exception in JSON mode");
        break;
        
    case 13:
        echo "Triggering JSON mode: User error via trigger_error\n";
        Telemetry::$json = true;
        trigger_error("Test user error in JSON mode", E_USER_ERROR);
        break;
        
    case 14:
        echo "Triggering JSON mode: Array access on non-array\n";
        Telemetry::$json = true;
        $nonarray = "string";
        $value = $nonarray[5]['nested']['key'];
        break;
        
    case 15:
        echo "Triggering JSON mode: Null method call\n";
        Telemetry::$json = true;
        $null_obj = null;
        $null_obj->method();
        break;
        
    default:
        echo "Unknown error type: $error_type\n";
        echo "Valid types: 1-15\n";
        exit(1);
}

echo "Test completed (should not reach here for fatal errors)\n";
