<?php
return [
	'scraper'=>[
		'input'=>"sv",
		'extraction_lua'=><<<ENDLUA
			if %ZGVS_VAR%.char then
				for char,d in pairs(%ZGVS_VAR%.char) do
					if d.guidestephistory then
						for guide,gd in pairs(d.guidestephistory) do
							if count>0 then print(",") end
							print(('{"type":"usedguide","time":%d,"guide":\"%s\"}'):format(gd.lasttime or 0,guide:gsub('"','\''):gsub('\\\\','/')))
							count=count+1
						end
					end
				end
			end
ENDLUA
		,
		//'output'=>"events" // everything goes into events, this is just a visual reminder
	],
	'crunchers'=>[
		[
			'function'=>function($line,&$alldata,&$mydata) {
				$mydata[$line['guide']]++;
			},
			// without 'action' and maybe 'table' this cruncher just collects data in $mydata which isn't used anyway
		]
	]
];
