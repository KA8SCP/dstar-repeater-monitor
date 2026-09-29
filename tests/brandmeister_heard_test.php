<?php
declare(strict_types=1);
require_once __DIR__.'/../functions.php';
function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS: '.$message.PHP_EOL;
}
$packets = file_get_contents(__DIR__.'/fixtures/brandmeister-lh.txt');
$queue = array_merge(['0{"sid":"test"}', 'ok', '40{"sid":"connected"}', 'ok'], explode("\n", trim($packets)), ['ok']);
$requests = [];
$request = function($url, $body, $timeout) use (&$queue, &$requests) {
    $requests[] = [$url, $body];
    if (!$queue) throw new RuntimeException('Unexpected extra request');
    return array_shift($queue);
};
$s = brandmeister_heard_snapshot(312543, $request);
verify($s['error'] === null && count($s['rows']) === 10, 'Captured multi-packet Last Heard history decoded');
verify($s['rows'][0]['callsign'] === 'KC1TLF' && $s['rows'][0]['target'] === 'TG 3125', 'Callsign and talkgroup match published record');
verify($s['rows'][0]['duration'] === 10 && $s['rows'][0]['mode'] === 'DMR TS1', 'Duration and slot decoded');
verify(str_contains($requests[3][1], 'ContextID') && str_contains($requests[3][1], '312543'), 'Query restricted to requested repeater');
verify(end($requests)[1] === "41\x1e1", 'Session explicitly closed');
$raw = ['ContextID'=>312543, 'SessionID'=>'example', 'Start'=>1790346808, 'Stop'=>0, 'SourceID'=>1234567, 'Slot'=>2];
verify(brandmeister_heard_row($raw, 1) === null, 'Unrelated repeater records rejected');
$row = brandmeister_heard_row($raw, 312543);
verify($row['callsign'] === '1234567' && $row['duration'] === null, 'Missing callsign uses published ID; ongoing call has no invented duration');
verify(brandmeister_heard_row([], 312543) === null, 'Malformed activity rejected');
$failure = brandmeister_heard_snapshot(312543, fn() => throw new RuntimeException('HTTP 403'));
verify($failure['rows'] === [] && str_contains($failure['error'], '403'), 'Access failures reported independently');
$empty = ['0{"sid":"test"}', 'ok', '40{}', 'ok', '42["searchHouseComplete"]', 'ok'];
$result = brandmeister_heard_snapshot(312543, function() use (&$empty) { return array_shift($empty); });
verify($result['rows'] === [] && $result['error'] === null, 'Empty history distinguished from retrieval failure');
echo "All BrandMeister Last Heard tests passed.\n";
