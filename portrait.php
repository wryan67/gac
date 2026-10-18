<?php

require __DIR__ . '/db.php';

require_login(true);

$name = $_GET['name'] ?? '';
$kind = $_GET['kind'] ?? 'characters';
$table = $kind === 'ships' || $kind === 'ship' ? 'ships' : 'characters';
$stmt = db()->prepare("SELECT image FROM {$table} WHERE name = ?");
$stmt->execute([$name]);
$image = $stmt->fetchColumn();
if (!is_string($image) || $image === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($image));
echo $image;
