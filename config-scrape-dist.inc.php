<?php
return [
	"SV_STORAGE_ROOT" => __DIR__."/mock_storage", //overridden
	"SV_STORAGE_DATA_PATH" => "<SV_STORAGE_ROOT>/<SYNC_FOLDER>", // set with SV_STORAGE_ROOT/config.inc.php
	"SV_STORAGE_FLAVOUR_PATH" => "<SV_STORAGE_DATA_PATH>/<FLAVOUR>",

	"SCRAPES_PATH" => "<TELEMETRY_ROOT>/<FLAVOUR>/_scrapes_/<DAY>", // within TELEMETRY_SV_PATH/{flavour}
	"MTIMES_CACHE_FILENAME" => "telemetry_scrape.mtimes_cache.json",
	"STATUS_INTERVAL" => 1,
	"STATUS_FILENAME" => "telemetry_scrape.status.json", // Telemetry writes here
	"MTIMES_WRITE_INTERVAL" => 10,
	"LUA_PATH" => "lua",
	"LUA_JSON_MODULE_REQUIRE" => "json = require 'JSON'",
	// Windows? "LUA_JSON_MODULE_REQUIRE" => "json = require 'lib/json'  if (not json) then error('Cannot load json module') end   local exjsonencode=json.encode  json.encode=function(_,...) return exjsonencode(...) end  -- wrapper to call as :",
	
	"WOW_FLAVOUR_DATA" => [
		'wow' => ['ZGVS_VAR' => "ZygorGuidesViewerSettings"],
		'wow-classic' => ['ZGVS_VAR' => "ZygorGuidesViewerClassicSettings"],
		'wow-classic-tbc' => ['ZGVS_VAR' => "ZygorGuidesViewerClassicSettings"]
	],
];
