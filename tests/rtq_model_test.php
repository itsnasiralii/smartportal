<?php
require __DIR__.'/../rtq_model.php';
$c=json_decode(file_get_contents(__DIR__.'/../rtq_defaults.json'),true);
rtq_validate_config($c);
$r=array_merge($c['presets'][1],['CSP TT'=>'20260825060358231853','NMS User Label'=>'ESS_TEST','Complaint Date/Time'=>'2026-10-11T23:50','Resolution Date / Time'=>'2026-10-12T00:20']);
if(rtq_validate($r,$c)['CSP TT']!==$r['CSP TT'])throw new Exception('Ticket changed');
foreach(['2026-02-30T00:00','2026-10-11T23:00'] as $bad){$r['Resolution Date / Time']=$bad;try{rtq_validate($r,$c);throw new RuntimeException('Invalid date accepted');}catch(InvalidArgumentException $expected){}}
echo "RTQ validation passed\n";
