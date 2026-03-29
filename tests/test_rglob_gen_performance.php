#!/usr/bin/php
<?php
/**
 * Performance test for FileTools::rglob_gen
 * 
 * Measures how long it takes to iterate through the first 1000 files
 * from a large directory structure (e.g., C:\Windows)
 */

require_once __DIR__ . '/../classes/FileTools.class.php';

// ANSI color codes
define('ANSI_GREEN', "\033[32m");
define('ANSI_RED', "\033[31m");
define('ANSI_YELLOW', "\033[33m");
define('ANSI_BLUE', "\033[34m");
define('ANSI_RESET', "\033[0m");
define('ANSI_BOLD', "\033[1m");

// Test configuration
$test_path = 'C:\\Windows';
$file_pattern = '*.*';
$depth_limit = 10;
$target_files = 1000;

// Verify path exists
if (!is_dir($test_path)) {
    echo ANSI_RED . "Error: Test path does not exist: $test_path" . ANSI_RESET . "\n";
    exit(1);
}

echo ANSI_BOLD . "FileTools::rglob_gen Performance Test" . ANSI_RESET . "\n";
echo str_repeat("=", 50) . "\n";
echo "Path: " . ANSI_BLUE . $test_path . ANSI_RESET . "\n";
echo "Pattern: " . ANSI_BLUE . $file_pattern . ANSI_RESET . "\n";
echo "Depth limit: " . ANSI_BLUE . $depth_limit . ANSI_RESET . "\n";
echo "Target files: " . ANSI_BLUE . $target_files . ANSI_RESET . "\n";
echo str_repeat("=", 50) . "\n\n";

// Start measurement
$start_time = microtime(true);
$start_memory = memory_get_usage(true);

echo "Iterating through files...\n";

$file_count = 0;
$generator = FileTools::rglob_gen($test_path, $file_pattern, $depth_limit);

try {
    foreach ($generator as $file) {
        $file_count++;
        
        // Show progress every 100 files
        if ($file_count % 100 == 0) {
            $elapsed = microtime(true) - $start_time;
            $per_file = $elapsed / $file_count * 1000; // ms per file
            echo "\r  [$file_count files] Time: " . sprintf("%.2f", $elapsed) . "s | " . sprintf("%.3f", $per_file) . "ms/file";
        }
        
        // Stop after target
        if ($file_count >= $target_files) {
            break;
        }
    }
} catch (Exception $e) {
    echo ANSI_RED . "Error during iteration: " . $e->getMessage() . ANSI_RESET . "\n";
    exit(1);
}

// End measurement
$end_time = microtime(true);
$end_memory = memory_get_usage(true);

$elapsed_time = $end_time - $start_time;
$memory_used = $end_memory - $start_memory;
$per_file_ms = ($elapsed_time / $file_count) * 1000;
$files_per_sec = $file_count / $elapsed_time;

// Clear progress line and show results
echo "\r" . str_repeat(" ", 80) . "\r";
echo "\n" . str_repeat("=", 50) . "\n";
echo ANSI_BOLD . "Results:" . ANSI_RESET . "\n";
echo str_repeat("=", 50) . "\n";
echo "Files iterated: " . ANSI_GREEN . $file_count . ANSI_RESET . "\n";
echo "Total time: " . ANSI_GREEN . sprintf("%.4f", $elapsed_time) . "s" . ANSI_RESET . "\n";
echo "Per-file average: " . ANSI_GREEN . sprintf("%.3f", $per_file_ms) . "ms" . ANSI_RESET . "\n";
echo "Files per second: " . ANSI_GREEN . sprintf("%.0f", $files_per_sec) . ANSI_RESET . "\n";
echo "Memory used: " . ANSI_GREEN . sprintf("%.2f", $memory_used / 1024 / 1024) . "MB" . ANSI_RESET . "\n";
echo str_repeat("=", 50) . "\n";

// Performance assessment
if ($elapsed_time < 1.0) {
    echo ANSI_GREEN . "✓ Excellent performance" . ANSI_RESET . " (< 1 second)\n";
} elseif ($elapsed_time < 5.0) {
    echo ANSI_YELLOW . "⚠ Good performance" . ANSI_RESET . " (1-5 seconds)\n";
} else {
    echo ANSI_RED . "✗ Slow performance" . ANSI_RESET . " (> 5 seconds)\n";
}

if ($memory_used < 10 * 1024 * 1024) {
    echo ANSI_GREEN . "✓ Efficient memory usage" . ANSI_RESET . " (< 10MB)\n";
} elseif ($memory_used < 50 * 1024 * 1024) {
    echo ANSI_YELLOW . "⚠ Moderate memory usage" . ANSI_RESET . " (10-50MB)\n";
} else {
    echo ANSI_RED . "✗ High memory usage" . ANSI_RESET . " (> 50MB)\n";
}

echo "\n";
