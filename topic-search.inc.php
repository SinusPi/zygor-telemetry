<?php
return [
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
		'crunch_func'=>function($line,&$alldata,&$mydata) {
			$mydata[$line['query']."=".$line['results']."/".substr($line['faction'],0,1)]++;
		},
		'output_mode'=>"day"
];