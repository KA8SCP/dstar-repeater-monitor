<?php
declare(strict_types=1);

// Explicit secondary URLs avoid following arbitrary links from remote pages.
function add_gateway_g2(array &$s, array $r, array $raw): void {
    if (!$raw['ok']) {
        $s['g2_error'] = 'g2_link unavailable: '.($raw['error'] ?: 'HTTP '.$raw['http_code']);
        return;
    }
    $g2 = parse_dplus_gateway($r, $raw['body'], (int)$raw['response_ms'], $raw['url']);
    if (!$g2['g2_link_version']) {
        $s['g2_error'] = 'g2_link dashboard not recognized';
        return;
    }
    $s['g2_link_version'] = $g2['g2_link_version'];
    $s['g2_dashboard_version'] = $g2['g2_dashboard_version'];
    // A standalone g2 page has its link table first, unlike WB1GOF's combined page.
    $s['g2_modules'] = $g2['dplus_modules'];
    $s['g2_last_heard'] = $g2['g2_last_heard'];
    if (!$s['dplus_last_heard']) $s['last_heard'] = $s['g2_last_heard'];
}

function activity_datetime(string $value, ?string $sourceTimezone): ?DateTimeImmutable {
    $value = trim($value);
    // Only explicit absolute formats are accepted. Never resolve relative text
    // or timezone-less values with the PHP server's implicit timezone.
    $format = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        $format = '!Y-m-d\TH:i:sP';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} (?:UTC|GMT|EDT|EST|CDT|CST|MDT|MST|PDT|PST)$/D', $value)) {
        $format = '!Y-m-d H:i:s T';
    } elseif ($sourceTimezone !== null) {
        if (preg_match('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) $format = '!Y/m/d H:i:s';
        elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) $format = '!Y-m-d H:i:s';
        elseif (preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/D', $value)) $format = '!d.m.Y H:i';
    }
    if ($format === null) return null;
    try {
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone($sourceTimezone ?? 'UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) return null;
        return $date;
    } catch (Throwable $e) { return null; }
}

function normalize_network_activity(array $row, ?string $timezone): array {
    $raw = (string)($row['last_heard'] ?? $row['time'] ?? '');
    $date = activity_datetime($raw, $timezone);
    $row['source_time'] = $raw;
    $row['timestamp'] = $date ? $date->getTimestamp() : null;
    $row['display_time'] = $date
        ? $date->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i:s T')
        : 'Time unavailable';
    return $row;
}

// Apply after cache retrieval so cached and fresh cards share the same display.
function normalize_card_times(array $status, ?string $timezone): array {
    foreach (['last_heard', 'dplus_last_heard', 'g2_last_heard', 'local_rf_activity'] as $field) {
        if (isset($status[$field])) {
            $status[$field] = array_map(fn($row) => normalize_network_activity($row, $timezone), $status[$field]);
        }
    }
    $status['last_seen_display'] = normalize_network_activity(['time'=>$status['last_seen'] ?? ''], $timezone)['display_time'];
    return $status;
}
