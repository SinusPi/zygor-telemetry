<?php
return false;
//
return [ // data topic definition
	'name'=>"ui", // name of topic; optional; defaults to part of file name "topic-<name>.inc.php" if omitted.
	'scraper'=>[ // if present, registers topic for scraping from some source. If not, topic may just query existing data. Or not even that.
		'input'=>"sv", // "sv"|"packagerlog"; where the data originates from.
					// Available sources are hardcoded, for now.
					// "sv" means the scraper will run a Lua script "extraction_lua" in the context of the saved variables file. %ZGVS_VAR% will be replaced with the SV table name.
					//  The Lua script should print JSON objects in text format, one per line, which will be collected as "events" with type "<topicname>" (or "eventtype" if specified).
					// "packagerlog" means the scraper will:
					//  - optionally run "packagerlog_check" once per parsed log, and if that check returns false, skip the log
					//  - run "packagerlog_line" for each line in the log.
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
	],
	'crunchers' => [], // array of cruncher definitions, possibly several, as raw data collected may represent different events.
	'crunchers_load' => true // if true, crunchers will be loaded from files named "topic-<name>-*.inc.php" after the current file.
];
