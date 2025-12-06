<?php
return [
		'extraction_lua'=><<<ENDLUA
			if %ZGVS_VAR%.char then
				for charname,chardata in pairs(%ZGVS_VAR%.char) do
					local telemetry = chardata.telemetry
					if telemetry then
						for _,ev in ipairs(telemetry) do
							if count>0 then print(",") end
							ev.type="ui"
							print(json:encode(ev))
							--[[
							local c=0
							print("{")
							for k,v in pairs(ev) do
								if c>0 then print(",") end
								print(('"%s":"%s"'):format(k,v))
								c=c+1
							end
							print("}")
							if count>0 then print(",") end
							count=count+1
							--]]
							count=count+1
						end
					end
				end
			end

ENDLUA
		,
		'crunchers' => [
			"ui_GOSSIP_MINED" => [
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
						"id" => $line["id"],
						"flavnum" => $line["flavnum"]
						];

					return $values;
				},
				"table" => "gossips",
			],
		],
		'crunch_func'=>function($line,&$alldata,&$mydata,$userfn) {
			unset($line['type']);
			$mydata[$userfn][]=$line;
		},
		'output_mode'=>"day_user"
];