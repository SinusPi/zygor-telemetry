<?php
/**
 * Simple cruncher for GOSSIP_MINED telemetry data.
 * 
 * Processes telemetry events of type 'ui-GOSSIP_MINED' and extracts relevant fields.
 * When run, returns associative arrays with the extracted values, to be put into the 'gossips' table.
 */
return [
	//'input'=>"event",
	//'event'=>"ui", // these defaults are taken from file names, unless overridden
	"eventtype" => "ui_GOSSIP_MINED",
	"function" => function($line) {
		$unpacked = json_decode($line["data"], true);
		foreach($unpacked as $field=>$value) {
			$line[$field] = $value;
		};
		unset($line['data']);
		unset($line['type']);
		unset($line['file_id']);
		$line["gossip"] = str_replace("||","|",$line["gossip"]);
		$line["file"] = str_replace("\n","",$line["file"]);

		$values = [
			"gossip" => $line["gossip"],
			"file" => $line["file"],
			"gossip" => $line["gossip"],
			"gossipIcon" => $line["gossipIcon"],
			"guide" => $line["guide"],
			"raceclass" => $line["raceclass"],
			"step" => $line["step"],
			"stepgoals" => $line["stepgoals"],
			"ver" => $line["ver"],
			"event_id" => $line["id"],
			"flavnum" => $line["flavnum"]
		];

		return $values;
	},
	"action" => "insert",
	"table" => "gossips",
	"table_schema" => "
		CREATE TABLE `gossips` (
			`event_id` int(11) NOT NULL,
			`flavnum` int(1) NOT NULL,
			`time` int(11) NOT NULL,
			`guide` text NOT NULL,
			`step` int(11) NOT NULL,
			`stepgoals` text NOT NULL,
			`gossip` text NOT NULL,
			`gossipicon` int(11) NOT NULL,
			`file` text NOT NULL,
			`ver` tinytext NOT NULL,
			`raceclass` text NOT NULL,
			PRIMARY KEY (`event_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
		"
];