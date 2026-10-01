<?php
declare(strict_types=1);
require_once __DIR__.'/../functions.php';
require_once __DIR__.'/../viewers.php';
function expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
foreach (['VE3RXR'=>'rxr','VE3TTT'=>'ttt'] as $call=>$file) {
    $r=$REFLECTORS[$call];
    $s=parse_dplus_gateway($r,file_get_contents(__DIR__.'/fixtures/gateway-'.$file.'-dplus.html'),20,$r['urls'][0]);
    $before=$s['dplus_modules'];
    add_gateway_g2($s,$r,['ok'=>true,'body'=>file_get_contents(__DIR__.'/fixtures/gateway-'.$file.'-g2.html'),'response_ms'=>20,'url'=>$r['g2_urls'][0]]);
    expect($s['dplus_version']==='2.2t' && count($s['dplus_modules'])===5, "$call DPLUS version and modules");
    expect($s['g2_link_version']==='4.00' && count($s['g2_modules'])===1, "$call g2_link version and separate module");
    expect($before===$s['dplus_modules'] && $s['modules']===$before, "$call g2 does not overwrite DPLUS links");
    expect(count($s['dplus_last_heard'])>0 && count($s['g2_last_heard'])>0, "$call both activity sources parsed");
    $online=$s['online'];
    add_gateway_g2($s,$r,['ok'=>false,'error'=>'Timeout','http_code'=>0]);
    expect($s['online']===$online && isset($s['g2_error']), "$call optional g2 outage preserves DPLUS status");
}
$r=$REFLECTORS['WB1GOF'];
$s=parse_dplus_gateway($r,file_get_contents(__DIR__.'/fixtures/gateway-WB1GOF.html'),20,$r['urls'][0]);
expect(count($s['dplus_modules'])===5 && count($s['g2_modules'])>0, 'Existing WB1GOF combined dashboard unchanged');
$utc=normalize_network_activity(['time'=>'2026-09-30T16:00:00+00:00'],null);
$dplus=normalize_network_activity(['time'=>'2026/09/30 12:00:00'],'America/New_York');
$g2=normalize_network_activity(['time'=>'2026-09-30 12:00:00 EDT'],null);
expect($utc['timestamp']===$dplus['timestamp'] && $g2['timestamp']===$utc['timestamp'], 'DMR, DPLUS, and g2 timestamps resolve to identical instants');
expect($utc['display_time']==='2026-09-30 12:00:00 EDT', 'Uniform Eastern display format');
expect(normalize_network_activity(['time'=>'2026-01-01T17:00:00Z'],null)['display_time']==='2026-01-01 12:00:00 EST','Winter standard time');
expect(activity_datetime('2026/02/30 12:00:00','America/New_York')===null,'Invalid calendar date rejected');
expect(activity_datetime('yesterday','America/New_York')===null,'Relative time rejected');
expect(activity_datetime('2026/09/30 12:00:00',null)===null,'Missing source timezone not guessed');
$rows=network_last_heard([
 ['name'=>'WB1GOF','last_heard'=>[['callsign'=>'A1AAA','time'=>'2026/09/30 12:00:00']]],
 ['name'=>'WB1GOF DMR 312543','last_heard'=>[['callsign'=>'B1BBB','time'=>'2026-09-30T15:30:00Z']]],
]);
expect($rows[0]['callsign']==='A1AAA','Mixed-mode records sort by absolute timestamp');
expect(viewer_ipv4('::ffff:192.0.2.1')==='192.0.2.1','Mapped IPv6 converted to IPv4');
expect(viewer_ipv4('::ffff:c000:201')==='192.0.2.1','Hex mapped IPv6 converted to IPv4');
expect(viewer_ipv4('2001:db8::1')===null,'Native IPv6 not falsely converted');
$db=tempnam(sys_get_temp_dir(),'viewer-test-');
try {
    $a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);
    viewer_heartbeat($db,$a,'192.0.2.1',1000);
    $v=viewer_heartbeat($db,$a,'192.0.2.1',1010);
    expect($v['active_sessions']===1,'Repeated heartbeat does not double count');
    $v=viewer_heartbeat($db,$b,'::ffff:192.0.2.1',1020);
    expect($v['active_sessions']===2 && $v['unique_ips']===1,'Tabs counted separately; mapped addresses grouped');
    $v=viewer_heartbeat($db,$c,'2001:db8::1',1030);
    expect($v['active_sessions']===3 && $v['ipv6_sessions']===1 && count($v['addresses'])===2,'IPv4 and IPv6 both listed');
    expect($v['addresses'][1]['ip']==='2001:db8::1' && $v['addresses'][1]['sessions']===1,'Native IPv6 address and session count preserved');
    $v=viewer_heartbeat($db,$c,'2001:db8::1',1140);
    expect($v['active_sessions']===1 && count($v['addresses'])===1 && $v['addresses'][0]['ip']==='2001:db8::1','Stale sessions expire at two minutes');
} finally { unlink($db); }
echo "All v1.0.3 checks passed.\n";
