@echo off
REM Test script for Telemetry error handlers
REM Runs various crash scenarios to test error handler responses

setlocal enabledelayedexpansion

cd /d "%~dp0\.."

echo.
echo ============================================================================
echo Testing Telemetry Error Handlers
echo ============================================================================
echo.

REM Define error types
set errors=^
	"1:Undefined function call"^
	"2:Undefined variable access"^
	"3:Type error (strlen with array)"^
	"4:Exception throw"^
	"5:User error via trigger_error"^
	"6:Division by zero"^
	"7:Array access on non-array"^
	"8:Null method call"^
	"9:Infinite loop (timeout)"^
	"10:Manual exit with error code"^
	"11:JSON mode - Undefined function call"^
	"12:JSON mode - Exception throw"^
	"13:JSON mode - User error via trigger_error"^
	"14:JSON mode - Array access on non-array"^
	"15:JSON mode - Null method call"

set test_count=0
set passed_count=0
set failed_count=0

REM Run each test
for %%E in (%errors%) do (
	for /f "tokens=1,2 delims=:" %%A in ("%%E") do (
		set /a test_count+=1
		set error_num=%%A
		set error_desc=%%B
		
		echo.
		echo [Test !test_count!] Error Type !error_num!: !error_desc!
		echo ---------------------------------------------------------------------------
		
		REM Run the test and capture output
		php tests\test_error_handlers.php !error_num! 2>&1
		set last_exit=!ERRORLEVEL!
		
		if !last_exit! equ 0 (
			echo Result: Test script exited normally
			set /a passed_count+=1
		) else (
			echo Result: Test script crashed/exited with code !last_exit!
			set /a failed_count+=1
		)
	)
)

echo.
echo ============================================================================
echo Test Summary
echo ============================================================================
echo Total Tests: !test_count!
echo Passed (handled): !passed_count!
echo Failed (unhandled): !failed_count!
echo ============================================================================
echo.

pause
