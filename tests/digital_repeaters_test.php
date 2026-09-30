<?php
declare(strict_types=1);
require_once __DIR__.'/../functions.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$bm = $REFLECTORS['WB1GOF_DMR_312543'];
$pi = $REFLECTORS['W1ATD_MULTIMODE'];
$fixture = fn($name) => file_get_contents(__DIR__.'/fixtures/'.$name);
$json = $fixture('brandmeister.json');
$device = json_decode($json, true);
$now = strtotime($device['last_seen'].' UTC');
$s = parse_brandmeister($bm, $json, 15, $now);
check(count($REFLECTORS) === 12, 'Twelve distinct repeaters configured');
check(count(array_unique(array_column($REFLECTORS, 'name'))) === 12, 'Cache and history names are unique');
check($s['online'] && $s['device_id'] === 312543, 'Live BrandMeister device recognized');
check($s['url'] === 'https://brandmeister.network/#/device/312543', 'Dashboard link is separate from API URL');
check($s['radio']['Color code'] === 1 && count($s['slots']) === 2, 'DMR radio and slots parsed');
check(!parse_brandmeister($bm, $json, 15, $now+901)['online'], 'Stale repeater is offline even with HTTP success');
foreach (['{}', '<html>Error</html>', '{', '{"id":312541,"callsign":"W1ATD"}'] as $bad) {
    $failed = parse_brandmeister($bm, $bad, 15, $now);
    check(!$failed['online'] && $failed['error'] !== null, 'Reject invalid or wrong device response');
}
$device['last_seen'] = null;
check(!parse_brandmeister($bm, json_encode($device), 15, $now)['online'], 'Missing last seen does not report online');
add_brandmeister_profile($s, $fixture('brandmeister-profile.json'));
check(count($s['talkgroups']) === 3 && $s['talkgroups'][2]['slot'] === 2, 'Static talkgroups mapped to correct slots');
add_brandmeister_profile($s, '{}');
check(isset($s['profile_error']) && $s['online'], 'Profile failure does not mark repeater offline');
$p = parse_pistar($pi, $fixture('pistar.html'), 20, $pi['urls'][0], new DateTimeImmutable('2026-09-26T16:00:00-04:00'));
check($p['online'] && $p['version'] === 'Pi-Star 4.1.8', 'Live Pi-Star page recognized');
check($p['modes'] === ['D-Star', 'DMR', 'M17', 'NXDN', 'P25', 'YSF'], 'Only six enabled modes included');
check($p['radio']['DMR ID'] === '312541' && $p['radio']['Tx'] === '145.390000 MHz', 'Multimode identity and radio info preserved');
check(count($p['last_heard']) === 20, 'Gateway activity parsed without duplicate Local RF records');
check($p['last_heard'][0]['callsign'] === 'W1ATD', 'GPS decoration and D-STAR suffix excluded from callsign');
check($p['last_heard'][9]['mode'] === 'DMR TS2' && $p['last_heard'][9]['target'] === 'TG 3125', 'DMR mode, slot, and target preserved');
check($p['last_heard'][0]['time'] === '2026-09-26T14:24:43-04:00', 'Pi-Star timestamps normalized with source timezone');
check(pistar_time('23:59:00 Dec 31st', 'America/New_York', new DateTimeImmutable('2027-01-01T01:00:00-05:00')) === '2026-12-31T23:59:00-05:00', 'Year rollover handled');
check(pistar_time('nonsense', 'America/New_York') === null, 'Invalid timestamps rejected');
check(!parse_pistar($pi, '<html>Login required</html>', 20, $pi['urls'][0])['online'], 'Login/error page does not report online');
check(count(network_last_heard([$s, $p])) === 20, 'Combined Last Heard includes Pi-Star without invented DMR calls');
check(array_slice(array_keys($REFLECTORS), 0, 2) === ['WB1GOF', 'WB1GOF_DMR_312543'] && array_key_last($REFLECTORS) === 'W1ATD_MULTIMODE', 'WB1GOF cards adjacent and W1ATD last');
check(count($p['local_rf_activity']) === 2, 'Local RF records parsed separately');
check($p['local_rf_activity'][0]['callsign'] === 'KB1ZSW' && $p['local_rf_activity'][0]['rssi'] === 'S1 (-141 dBm)', 'Local RF callsign and signal strength preserved');
check($p['local_rf_activity'][0]['duration'] === '1.0' && $p['local_rf_activity'][0]['ber'] === '0.3%', 'Local RF duration and BER preserved');
echo "All digital repeater tests passed.\n";
