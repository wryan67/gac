<?php

require __DIR__ . '/db.php';
require_once __DIR__ . '/catalog.php';

start_session();
if (!empty($_SESSION['userid'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), $posted)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $login = trim($_POST['userid'] ?? '');
        $password = $_POST['password'] ?? '';
        $hash = hash('sha256', $password);
        $stmt = db()->prepare(
            'SELECT userid, password FROM users
             WHERE userid = ?
                OR (email <> \'\' AND email = ? COLLATE NOCASE)'
        );
        $stmt->execute([$login, $login]);
        $rows = $stmt->fetchAll();
        $row = null;
        foreach ($rows as $candidate) {
            if ($candidate['userid'] === $login) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null && count($rows) === 1) {
            $row = $rows[0];
        }
        if ($row && hash_equals($row['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['userid'] = $row['userid'];
            if (catalog_due(db(), 'characters') || catalog_due(db(), 'ships')) {
                catalog_start_background();
            }
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid userid, email, or password.';
    }
}

$token = csrf_token();
$theme = theme_from_cookie();
?>
<!DOCTYPE html>
<html lang="en" class="theme-<?= h($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - SWGOH</title>
    <link rel="stylesheet" href="assets/vendor/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/vendor/css/source-sans-pro.css">
    <link rel="stylesheet" href="swgoh.css">
    <style>
        body {
            font-family: 'Source Sans Pro', Arial, sans-serif;
            background: #35312f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .login-panel { width: min(380px, calc(100% - 32px)); border-top: 3px solid #337ab7; }
        .login-panel h1 {
            font-weight: 900; letter-spacing: 0.1em; text-transform: uppercase;
            font-size: 1.3em; margin: 0 0 16px;
        }
    </style>
</head>
<body>
    <div class="panel panel-default login-panel">
        <div class="panel-body">
            <h1>SWGOH</h1>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post" action="login.php">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <div class="form-group">
                    <label for="userid">Userid or email</label>
                    <input class="form-control" id="userid" name="userid" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Log in</button>
            </form>
        </div>
    </div>
</body>
</html>
