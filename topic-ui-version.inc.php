<?php
/**
 * Simple cruncher for VERSION telemetry data.
 * 
 * Processes telemetry events of type 'ui_VERSION' and extracts relevant fields.
 * When run, returns associative arrays with the extracted values, to be put into the 'table'.
 */
return [
	"eventtype" => "ui_VERSION",
	"function" => function($line) {
		$unpacked = json_decode($line["data"], true);
		$line = $unpacked + $line;
		unset($line['data']);
		unset($line['type']);
		unset($line['file_id']);

		$values = [
			"event_id" => $line["id"],
			"flavnum" => $line["flavnum"],
			"time" => $line["time"],

			"flavour" => $line["flavour"],
			"variant" => $line["variant"],
			"projectID" => intval($line["projectID"]),
			"seasonID" => intval($line["seasonID"]),
			"timerunningID" => intval($line["timerunningID"]),
		];

		return $values;
	},
	"action" => "insert",
	"table" => "version",
	"table_schema" => "
		CREATE TABLE `<TABLE>` (
			`event_id` int(11) NOT NULL,
			`flavnum` int(1) NOT NULL,
			`time` int(11) NOT NULL,
			`flavour` varchar(20) NOT NULL,
			`variant` varchar(20) NOT NULL,
			`projectID` int(4) NOT NULL,
			`seasonID` int(4) NOT NULL,
			`timerunningID` int(11) NOT NULL,
			PRIMARY KEY (`event_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
		"
];