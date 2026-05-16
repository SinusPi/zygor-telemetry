# Zygor Telemetry — Agent Instructions

## Project Summary
PHP telemetry backend for World of Warcraft client data. Collects SV (SavedVariables) files, aggregates events into topic tables, exposes a JSON API, and renders an HTML admin dashboard.

## PHP Runtime
**Always use `c:\xampp\php5\php.exe`** — the codebase must remain **PHP 5.6 compatible**.
- No arrow functions (`fn() =>`) — use `function() use (...) {}`
- No `??` null-coalescing — use `isset($x) ? $x : $default`
- Short array syntax `[]` is fine (PHP 5.4+)
- No typed properties, union types, named arguments, or match expressions

## Running Tests
```
c:\xampp\php5\php.exe tests\test_tools.php
c:\xampp\php5\php.exe tests\test_logger.php
c:\xampp\php5\php.exe tests\test_schema_manager.php
```
All test files follow the same pattern: `expect()` / `expect_true()` / `expect_false()` helpers, ANSI-colored output, summary `N passed, M failed.`

## Key Architecture

### 1. Telemetry Data Flow
- **Collection**: Data is collected ("scraped") via `telemetry_scrape.php`, and - via `TelemetryScrape` and subclasses - stored in a MySQL database.
  - Each subclass of `TelemetryScrape` is responsible for scraping a specific source of telemetry data.
    - `TelemetryScrapeSVs`: Scrapes data from SVs (Saved Variables files, coming from users' game clients).
    - `TelemetryScrapePackagerLog`: Scrapes data from Packager logs.
- **Processing**: Data is crunched and analyzed using `telemetry_crunch.php`, via `TelemetryCrunch` class. Results are stored in dedicated tables.
- **Querying**: Processed data can be queried through `telemetry_endpoint.php`, via `TelemetryEndpoint` class.
- **Visualization**: Processed data is displayed through `telemetry_view.php` and `TelemetryView` class.
- **Administration**: `admin.php` + `admin.css` - admin dashboards

### Bootstrap
Every script calls `Telemetry::startup($opts)` which wires config, DB, topics, and Logger.  
DB tables are auto-created via `SchemaManager` — never write `CREATE TABLE` statements outside of schema arrays.

### Config
Copy `config*-dist.inc.php` → remove `-dist`, fill credentials. See [readme.md](readme.md).  
Access via `Telemetry::$CFG['KEY']` (supports dot-path: `$CFG->getValue('DB.host')`).

### SQL Queries
Use the `{s}` / `{d}` / `{f}` placeholder system (never raw string concatenation):
```php
Telemetry::$db->query("SELECT * FROM `log` WHERE tag={s} AND id>{d}", $tag, $id);
```
Nullable variants: `{sn}`, `{dn}`. Array variants: `{sa}`, `{da}`.

### Logger
```php
Logger::log($msg, $tag, $level);   // always writes (file + DB)
Logger::vlog($msg);                // only if $verbose is non-empty array
Logger::vflog($flag, $msg);        // only if $flag is in $verbose array
```
`Logger::$verbose` is an **array of flag strings** — it replaces both the old boolean `verbose` and `verbose_flags`.

## Admin Panel (`admin.php` + `admin.css`)
- jQuery 1.x (from `includes/jquery-ui-1.13.2.custom/`)
- All table rows use `<template>` elements — clone with `template.content.cloneNode(true)`, do all `.find()` mutations **before** `tbody.append()` (fragments become empty after append)
- Use `.css('display', 'block'/'none')` instead of `.show()`/`.hide()` on template-cloned nodes
- Log messages are rendered with `ansiToHtml()` (inline styles, no CSS classes, 256-color support)
- Display order: **oldest entries on top, newest at bottom**; "Previous..." button loads older entries above existing rows

## `git_logs.php`
Parses `git log --pretty=tformat:commit=%H%nauthor=%an%nemail=%ae%ndate=%ai%nsubject=%s%nbody=%b%n---` output using field-name prefixes (`commit=`, `author=`, etc.), not positional line counting.

## File Layout
```
classes/          Core PHP classes (Telemetry, Logger, TelemetryDB, …)
topic-*.inc.php   Data topic definitions (scraper + cruncher + endpoint + view hooks)
tests/            Test scripts — run with php5 exe above
includes/         Zygor framework helpers + jQuery UI
lib/              Lua libraries (json.lua)
config*-dist.php  Config templates — copy and fill
mock_storage/     Sample input files for dev/test
```

## Topic Files

- Topic files (e.g., `topic-search.inc.php`, `topic-usedguide.inc.php`) define telemetry logic
- Each topic matches `topic` parameters in requests for `telemetry_endpoint.php`
- Files define:
  - **Scraper**: how data is extracted, often using Lua scripts, as raw event objects
  - **Crunchers**: how raw data is processed and reinserted into the DB, including table schemas and logic. One topic can have multiple crunchers, built-in or loaded from more files
  - **Endpoint**: queries fetching data from the DB
  - **View Configuration**: how the data is visualized, including HTML and JavaScript for charts
  - **Table Schema**: database structure
- File structure:
  - Name: `topic-{topicname}.inc.php` in the root directory
  - Returns a PHP array:
    - `name` (optional): Topic name; defaults to `{topicname}`
    - `scraper` (optional, array):
      - `input`: `"sv"` or `"packagerlog"`, data source type
      - `extraction_lua`: Lua script to extract events as JSON objects
    - `crunchers` (optional): array of *sets* of definitions that process raw events, executed in order
      - `name`: name of cruncher
      - `table`: name of target table
      - `table_schema`: definition of table structure (use `<TABLE>` as placeholder for table name).
      - `action`: operation type; `"insert"` to process one event with `function` and insert into `table`; `"run"` to just execute `function`
      - `function`: Callable; parameters vary depending on `action`
    - `crunchers_load` (optional): If `true`, loads additional cruncher definitions from files named `topic-{topicname}-*.inc.php`.
    - `endpoint` (optional): Array
	  - `queryfunc`: callable that queries the database for processed data.
    - `view` (optional): Array with `title`, `class`, and `printer` callable for rendering the topic data in a web interface.
