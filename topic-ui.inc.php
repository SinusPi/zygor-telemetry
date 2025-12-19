<?php
$UI = [
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
		'crunch_func'=>function($line,&$alldata,&$mydata,$userfn) {
			unset($line['type']);
			$mydata[$userfn][]=$line;
		},
		'output_mode'=>"day_user"
];

foreach (glob(__DIR__."/topic-ui-*.inc.php") as $cruncher) {
	try {
		$parse = token_get_all(file_get_contents($cruncher));
		$fnconf = include $cruncher;
		if (!is_array($fnconf)) continue; // allow empty files
		$name = preg_replace("/.*topic-ui-([^.]+)\.inc\.php$/","$1",$cruncher);
		$UI['crunchers'][$name] = $fnconf;
	} catch (Exception $e) {
		die("Error including $cruncher: ".$e->getMessage()."\n");
	}
}

return $UI;