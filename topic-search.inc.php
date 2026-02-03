<?php
return [
	'scraper'=>[
		'input'=>"sv",
		'extraction_lua'=><<<ENDLUA
			if %ZGVS_VAR%.char then
				for char,ch in pairs(%ZGVS_VAR%.char) do
					if ch.searchhistory then
						for i,sh in ipairs(ch.searchhistory) do
							if count>0 then print(",") end
							print(('{"type":"search","faction":\"%s\","time":%d,"query":\"%s\","results":\"%s\"}'):format(ch.faction or "?",sh.time or 0,sh.query:gsub('"','\''):gsub('\\\\','/'),sh.numresults or 0))
							count=count+1
						end
					end
				end
			end

ENDLUA
		,
		//'event'=>"search"
	],
	'crunchers'=>[
		[
			//'input'=>"event",
			//'event'=>"search",
			'function' => function($line) {
				$unpacked = json_decode($line["data"], true);
				$line = $unpacked + $line;
				unset($line['data']);
				unset($line['type']);
				unset($line['file_id']);

				$factions = ['Alliance'=>1,'Horde'=>2];
				$values = [
					"event_id" => $line["id"],
					"flavnum" => $line["flavnum"],
					"time" => $line["time"],

					"query" => $line["query"],
					"faction" => $factions[$line["faction"]]?:0,
					"results" => intval($line["results"]),
				];

				return $values;
			},
			'action' => "insert",
			'table' => "search",
			'table_schema' => "
				CREATE TABLE `search` (
					`event_id` int(11) NOT NULL,
					`flavnum` int(1) NOT NULL,
					`time` int(11) NOT NULL,
					`query` varchar(30) NOT NULL,
					`faction` int(1) NOT NULL,
					`results` int(6) NOT NULL,
					PRIMARY KEY (`event_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci
				"
		]
	]
];