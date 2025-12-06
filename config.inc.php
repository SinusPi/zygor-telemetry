<?php
return [
	"TELEMETRY_ROOT" => __DIR__,
	"FLAVOUR_PATH" => "<TELEMETRY_ROOT>/<FLAVOUR>",
	"DATA_PATH_DPMODE_DAY" => "<TELEMETRY_ROOT>/<FLAVOUR>/<TOPIC>/<DAY>.json",
	"DATA_PATH_DPMODE_DAY_USER" => "<TELEMETRY_ROOT>/<FLAVOUR>/<TOPIC>/<DAY>/<USER>.json",
	"TELEMETRY_FILE_AGE" => 7 * DAY,
	"TELEMETRY_DATA_AGE" => 30 * DAY,

	"WOW_FLAVOURS" => [ 'wow','wow-classic','wow-classic-tbc' ],

	"DB" => [ //overwritten
		'host' => 'localhost',
		'db'   => 'telemetry_db',
		'user' => 'telemetry_user',
		'pass' => 'telemetry_password',
	],
];
