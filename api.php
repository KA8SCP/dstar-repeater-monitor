<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__.'/functions.php';
require_once __DIR__.'/history.php';

$statuses = all_reflectors($REFLECTORS);
history_record($statuses);
$online = count(array_filter($statuses, fn($s)=>$s['online']));
$users = 0; $modules = 0;
foreach ($statuses as $s) {
    $users += count($s['users']);
    $modules += count($s['modules']);
}

echo json_encode([
    'ok'=>true,
    'updated'=>gmdate('c'),
    'summary'=>[
        'total'=>count($statuses),
        'online'=>$online,
        'offline'=>count($statuses)-$online,
        'users'=>$users,
        'modules'=>$modules,
    ],
    'reflectors'=>$statuses,
    'last_heard'=>network_last_heard($statuses),
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
