<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/history.php';
$hours=(int)($_GET['hours']??24);
echo json_encode(history_payload($hours), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
