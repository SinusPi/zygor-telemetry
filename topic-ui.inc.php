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
		'crunchers' => [],
		'crunchers_load' => true
];
