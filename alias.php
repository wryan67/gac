<?php

require __DIR__ . '/db.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$posted = $_POST['csrf'] ?? '';
if (!hash_equals(csrf_token(), $posted)) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$name = $_POST['name'] ?? '';
$alias = trim($_POST['alias'] ?? '');
if (strlen($alias) > 80) {
    $alias = substr($alias, 0, 80);
}
$kind = ($_POST['kind'] ?? '') === 'ships' ? 'ships' : 'characters';
$table = $kind === 'ships' ? 'ships' : 'characters';

$stmt = db()->prepare("UPDATE {$table} SET alias = ? WHERE name = ?");
$stmt->execute([$alias, $name]);

if (!empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'alias' => $alias]);
    exit;
}

header('Location: roster.php?kind=' . $kind, true, 303);
exit;
