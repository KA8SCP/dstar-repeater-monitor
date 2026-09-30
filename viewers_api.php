<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/viewers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); header('Allow: POST'); echo json_encode(['ok'=>false]); exit;
}
$session = $_POST['session'] ?? '';
// Do not trust client-controlled X-Forwarded-For. A trusted reverse proxy
// must be configured at the web-server level to set REMOTE_ADDR correctly.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!is_string($session) || !preg_match('/^[a-f0-9]{32}$/D', $session) || !filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(400); echo json_encode(['ok'=>false]); exit;
}
try {
    echo json_encode(viewer_heartbeat(__DIR__.'/data/viewers.sqlite', $session, $ip, time()), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Viewer tracking unavailable: '.$e->getMessage());
    http_response_code(503); echo json_encode(['ok'=>false, 'error'=>'Viewer information temporarily unavailable.']);
}
