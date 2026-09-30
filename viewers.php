<?php
declare(strict_types=1);

function viewer_ipv4(string $ip): ?string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
    $packed = @inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
        return inet_ntop(substr($packed, 12));
    }
    return null;
}

function viewer_heartbeat(string $path, string $session, string $ip, int $now): array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $session) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new InvalidArgumentException('Invalid viewer request');
    }
    $ip = viewer_ipv4($ip) ?? inet_ntop(inet_pton($ip));
    $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>2]);
    $db->exec('PRAGMA busy_timeout=2000');
    $db->exec('CREATE TABLE IF NOT EXISTS viewers (session TEXT PRIMARY KEY, ip TEXT NOT NULL, seen INTEGER NOT NULL)');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM viewers WHERE seen <= ?')->execute([$now-120]);
        $db->prepare('INSERT INTO viewers(session,ip,seen) VALUES(?,?,?) ON CONFLICT(session) DO UPDATE SET ip=excluded.ip,seen=excluded.seen')->execute([$session,$ip,$now]);
        $rows = $db->query('SELECT ip, COUNT(*) AS sessions, MAX(seen) AS last_seen FROM viewers GROUP BY ip ORDER BY ip')->fetchAll(PDO::FETCH_ASSOC);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    $addresses = []; $total = 0; $ipv6 = 0;
    foreach ($rows as $row) {
        $count = (int)$row['sessions'];
        $total += $count;
        $v4 = viewer_ipv4($row['ip']);
        if ($v4 === null) { $ipv6 += $count; continue; }
        $addresses[] = ['ip'=>$v4, 'sessions'=>$count, 'last_seen'=>gmdate('c', (int)$row['last_seen'])];
    }
    return ['ok'=>true, 'active_sessions'=>$total, 'unique_ips'=>count($rows), 'ipv6_sessions'=>$ipv6, 'addresses'=>$addresses];
}
