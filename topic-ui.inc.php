<?php
return [
	'scraper'=>[
		'input'=>"sv",
		'extraction_lua'=><<<ENDLUA
			if %ZGVS_VAR%.char then
				for charname,chardata in pairs(%ZGVS_VAR%.char) do
					local telemetry = chardata.telemetry
					if telemetry then
						for _,ev in ipairs(telemetry) do
							if count>0 then print(",") end
							ev.type="ui"
							ev.subtype=ev.event
							ev.event=nil
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
		'description'=>"Get various variables stored in ZGV.db.char[x].telemetry",
	],
	'crunchers' => [],
	'crunchers_load' => true
];
