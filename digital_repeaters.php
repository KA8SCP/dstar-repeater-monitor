<?php
declare(strict_types=1);

// BrandMeister's dashboard uses v2/device and a 15-minute last_seen window.
// A successful API request alone does not mean the repeater is connected.
function parse_brandmeister(array $r, string $json, int $ms, ?int $now = null): array {
    $s = blank_status($r);
    $s['response_ms'] = $ms;
    $s['http_code'] = 200;
    $d = json_decode($json, true);
    if (!is_array($d) || (string)($d['id'] ?? '') !== (string)$r['device_id'] || empty($d['callsign'])) {
        $s['error'] = 'Invalid BrandMeister device response';
        return $s;
    }
    $s['callsign'] = (string)$d['callsign'];
    $s['device_id'] = $r['device_id'];
    $s['radio'] = [
        'TX' => isset($d['tx']) ? $d['tx'].' MHz' : null,
        'RX' => isset($d['rx']) ? $d['rx'].' MHz' : null,
        'Color code' => $d['colorcode'] ?? null,
        'Master' => $d['lastKnownMaster'] ?? null,
        'Hardware' => $d['hardware'] ?? null,
        'Location' => $d['city'] ?? null,
    ];
    $s['version'] = $d['firmware'] ?? null;
    $states = [0 => 'Not linked', 1 => 'Slot 1 linked', 2 => 'Slot 2 linked', 3 => 'Both slots linked', 4 => 'Linked in DMO mode'];
    $status = filter_var($d['status'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    $s['connection_status'] = $states[$status ?? -1] ?? 'Unknown';
    $seen = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($d['last_seen'] ?? ''), new DateTimeZone('UTC'));
    $s['last_seen'] = $seen ? $seen->format('c') : null;
    $age = $seen ? ($now ?? time()) - $seen->getTimestamp() : null;
    $s['online'] = $age !== null && $age >= -60 && $age <= 900;
    $s['status_note'] = $age === null ? 'Last seen unavailable' : ($s['online'] ? $s['connection_status'] : 'Not seen by BrandMeister within 15 minutes');
    $s['slots'] = [];
    if ($status !== null && isset($states[$status])) {
        foreach ([1, 2] as $slot) {
            $s['slots'][] = ['slot' => $slot, 'linked' => in_array($status, [$slot, 3], true)];
        }
    }
    return $s;
}

function add_brandmeister_profile(array &$s, string $json): void {
    $d = json_decode($json, true);
    if (!is_array($d) || !isset($d['staticSubscriptions']) || !is_array($d['staticSubscriptions'])) {
        $s['profile_error'] = 'Talkgroup data unavailable';
        return;
    }
    $s['talkgroups'] = [];
    foreach (['staticSubscriptions' => 'Static', 'dynamicSubscriptions' => 'Dynamic'] as $key => $kind) {
        foreach (($d[$key] ?? []) as $row) {
            if (!is_array($row) || !isset($row['talkgroup'], $row['slot'])) continue;
            if (!ctype_digit((string)$row['talkgroup']) || !in_array((string)$row['slot'], ['1', '2'], true)) continue;
            $s['talkgroups'][] = ['talkgroup' => (string)$row['talkgroup'], 'slot' => (int)$row['slot'], 'kind' => $kind];
        }
    }
}

// Pi-Star embeds its public information in the initial page. Remove tooltip
// explanations before mapping columns; otherwise "Callsign" appears twice.
function parse_pistar(array $r, string $html, int $ms, string $url, ?DateTimeImmutable $now = null): array {
    $s = blank_status($r);
    $s['response_ms'] = $ms;
    $s['http_code'] = 200;
    $s['url'] = $url;
    $dom = dom_from_html($html);
    if (!$dom) { $s['error'] = 'Empty Pi-Star dashboard'; return $s; }
    $xp = new DOMXPath($dom);
    $info = $xp->query('//*[@id="repeaterInfo"]')->item(0);
    if (!$info || !preg_match('/Pi-Star/i', $dom->textContent)) {
        $s['error'] = 'Unrecognized Pi-Star dashboard';
        return $s;
    }
    $s['online'] = true;
    $s['status_note'] = 'Public dashboard reachable';
    $plain = clean_text($dom->textContent);
    if (preg_match('/Pi-Star:([\w.]+)\s*\/\s*Dashboard:\s*([\w.]+)/', $plain, $m)) {
        $s['version'] = 'Pi-Star '.$m[1];
        $s['dashboard_version'] = $m[2];
    }
    $s['modes'] = [];
    $s['networks'] = [];
    $s['radio'] = [];
    foreach ($xp->query('.//table', $info) as $table) {
        $heading = clean_text($xp->query('.//th', $table)->item(0)?->textContent ?? '');
        if (in_array($heading, ['Modes Enabled', 'Network Status'], true)) {
            foreach ($xp->query('.//td', $table) as $cell) {
                $label = clean_text($cell->textContent);
                $style = strtolower(preg_replace('/\s+/', '', $cell->getAttribute('style')) ?? '');
                // Pi-Star uses green for enabled, gray/aria-disabled for disabled.
                $enabled = !$cell->hasAttribute('aria-disabled') && (str_contains($style, 'background:#0b0') || str_contains($style, 'background:#00bb00'));
                if ($heading === 'Modes Enabled') {
                    if ($enabled) $s['modes'][] = $label;
                } else {
                    $s['networks'][] = ['mode' => $label, 'enabled' => $enabled];
                }
            }
        } elseif ($heading === 'Radio Info' || $heading === 'DMR Repeater') {
            foreach ($xp->query('.//tr', $table) as $tr) {
                $key = $xp->query('./th', $tr)->item(0);
                $value = $xp->query('./td', $tr)->item(0);
                if ($key && $value) $s['radio'][clean_text($key->textContent)] = clean_text($value->textContent);
            }
        }
    }
    // Keep local RF separate so network_last_heard does not duplicate calls.
    foreach (['lastHerd' => 'last_heard', 'localTxs' => 'local_rf_activity'] as $id => $field) {
    $s[$field] = [];
    $activity = $xp->query('//*[@id="'.$id.'"]')->item(0);
    if ($activity) {
        foreach ($xp->query('.//a[contains(@class,"tooltip")]/span', $activity) as $tooltip) $tooltip->parentNode->removeChild($tooltip);
        foreach ($xp->query('.//table', $activity) as $table) {
            $rows = $xp->query('.//tr', $table);
            $headers = [];
            foreach ($xp->query('./th', $rows->item(0)) as $i => $th) $headers[strtolower(clean_text($th->textContent))] = $i;
            $timeIndex = null;
            foreach ($headers as $key => $i) if (str_starts_with($key, 'time')) $timeIndex = $i;
            if ($timeIndex === null || !isset($headers['callsign'], $headers['mode'], $headers['target'])) continue;
            foreach ($rows as $row) {
                $cells = $xp->query('./td', $row);
                if (!$cells->length) continue;
                $callCell = $cells->item($headers['callsign']);
                if (!$callCell) continue;
                $call = clean_text($xp->query('.//a', $callCell)->item(0)?->textContent ?? $callCell->textContent);
                if (!preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,15}$/i', $call)) continue;
                $get = fn($i) => clean_text($cells->item($i)?->textContent ?? '');
                $rawTime = $get($timeIndex);
                $time = pistar_time($rawTime, $r['timezone'] ?? 'UTC', $now);
                $s[$field][] = [
                    'callsign' => $call, 'mode' => $get($headers['mode']),
                    'target' => $get($headers['target']),
                    'via' => isset($headers['src']) ? $get($headers['src']) : '',
                    'duration' => isset($headers['dur(s)']) ? $get($headers['dur(s)']) : '',
                    'ber' => isset($headers['ber']) ? $get($headers['ber']) : '',
                    'rssi' => isset($headers['rssi']) ? $get($headers['rssi']) : '',
                    'time' => $time ?? $rawTime, 'last_heard' => $time ?? $rawTime,
                    'type' => 'PISTAR', 'module' => '',
                ];
                if (count($s[$field]) >= MAX_LAST_HEARD) break 2;
            }
        }
    }
    }
    return $s;
}

function pistar_time(string $value, string $timezone, ?DateTimeImmutable $now = null): ?string {
    $tz = new DateTimeZone($timezone);
    $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $value = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $value) ?? $value;
    $dt = DateTimeImmutable::createFromFormat('!Y H:i:s M j', $now->format('Y').' '.$value, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count']))) return null;
    // A December record read in January belongs to the previous year.
    if ($dt > $now->modify('+1 day')) $dt = $dt->modify('-1 year');
    return $dt->format('c');
}

// The public Last Heard page uses Socket.IO, not the device REST API.
// Engine.IO polling lets PHP request a bounded history snapshot without a daemon.
function brandmeister_heard_row(array $d, int $deviceId): ?array {
    if ((string)($d['ContextID'] ?? '') !== (string)$deviceId) return null;
    $call = trim((string)($d['SourceCall'] ?? $d['SourceID'] ?? ''));
    $start = filter_var($d['Start'] ?? null, FILTER_VALIDATE_INT);
    if ($call === '' || !$start || $start < 0 || empty($d['SessionID'])) return null;
    $slot = (string)($d['Slot'] ?? '');
    $stop = filter_var($d['Stop'] ?? null, FILTER_VALIDATE_INT);
    return [
        'session_id' => (string)$d['SessionID'], 'callsign' => $call,
        'source_id' => (string)($d['SourceID'] ?? ''),
        'mode' => 'DMR'.(in_array($slot, ['1','2'], true) ? ' TS'.$slot : ''),
        'module' => '', 'type' => 'BRANDMEISTER',
        'target' => (in_array('Group', (array)($d['CallTypes'] ?? []), true) ? 'TG ' : 'ID ').($d['DestinationID'] ?? ''),
        'message' => (string)($d['TalkerAlias'] ?? ''),
        'time' => gmdate('c', $start), 'last_heard' => gmdate('c', $start),
        'duration' => $stop && $stop >= $start ? $stop - $start : null,
        'rssi' => is_numeric($d['RSSI'] ?? null) ? $d['RSSI'] : null,
        'ber' => is_numeric($d['BER'] ?? null) ? $d['BER'] : null,
    ];
}

function brandmeister_heard_snapshot(int $deviceId, ?callable $request = null): array {
    $deadline = microtime(true) + 8;
    $request ??= function(string $url, ?string $body, int $timeout): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeout, CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeout),
            CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=UTF-8']]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($data === false || $code !== 200) throw new RuntimeException($error ?: 'HTTP '.$code);
        return $data;
    };
    $send = function(string $url, ?string $body = null) use ($request, $deadline): string {
        $remaining = (int)(1000 * ($deadline - microtime(true)));
        if ($remaining <= 0) throw new RuntimeException('Last Heard request timed out');
        return $request($url, $body, $remaining);
    };
    $base = 'https://api.brandmeister.network/lh/?EIO=4&transport=polling';
    $url = null;
    $rows = [];
    try {
        $open = $send($base);
        $handshake = json_decode(substr($open, 1), true);
        if (!str_starts_with($open, '0') || empty($handshake['sid'])) throw new RuntimeException('Invalid Last Heard handshake');
        $url = $base.'&sid='.rawurlencode($handshake['sid']);
        $send($url, '40');
        if (!str_starts_with($send($url), '40')) throw new RuntimeException('Last Heard connection not accepted');
        $send($url, '42'.json_encode(['searchHouse', [
            'query' => ['condition' => 'AND', 'rules' => [['id' => 'ContextID', 'operator' => 'equal', 'value' => $deviceId]]],
            'amount' => MAX_LAST_HEARD,
        ]]));
        $complete = false;
        for ($i = 0; $i < 30 && !$complete; $i++) {
            foreach (explode("\x1e", $send($url)) as $packet) {
                if ($packet === '2') { $send($url, '3'); continue; }
                if (!str_starts_with($packet, '42')) continue;
                $event = json_decode(substr($packet, 2), true);
                if (($event[0] ?? '') === 'searchHouseComplete') { $complete = true; continue; }
                if (($event[0] ?? '') !== 'mqtt' || ($event[1]['topic'] ?? '') !== 'LH-Startup') continue;
                $d = json_decode((string)($event[1]['payload'] ?? ''), true);
                $row = is_array($d) ? brandmeister_heard_row($d, $deviceId) : null;
                if ($row) $rows[$row['session_id']] = $row;
            }
        }
        if (!$complete) throw new RuntimeException('Last Heard response incomplete');
        usort($rows, fn($a, $b) => strcmp($b['time'], $a['time']));
        return ['rows' => array_slice($rows, 0, MAX_LAST_HEARD), 'error' => null];
    } catch (Throwable $e) {
        return ['rows' => [], 'error' => 'Last Heard unavailable: '.$e->getMessage()];
    } finally {
        if ($url !== null) {
            try { $request($url, "41\x1e1", 500); } catch (Throwable $e) { /* Best-effort session close. */ }
        }
    }
}

function add_brandmeister_heard(array &$s, int $deviceId): void {
    $file = __DIR__.'/cache/bm-heard-'.$deviceId.'.json';
    $data = is_file($file) && time()-filemtime($file) < 60
        ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($data) || !isset($data['rows'])) {
        $data = brandmeister_heard_snapshot($deviceId);
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    $s['last_heard'] = $data['rows'];
    $s['last_heard_error'] = $data['error'] ?? null;
}
