<?php

const SWGOH_USER_ID = 'wryan';
const SWGOH_USER_HASH = 'ea1d66175a6da75577369b34affb2b3ffdb76a5a4db79c92dc3a78248a3d418a';
const SWGOH_SESSION_LIFETIME = 172800;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:/var/www/dbs/swgoh.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->query('PRAGMA journal_mode = WAL')->fetch();
    $ready = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'squads'")->fetchColumn();
    if (!$ready) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS characters (
                name TEXT PRIMARY KEY,
                alias TEXT NOT NULL DEFAULT \'\',
                image BLOB NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ships (
                name TEXT PRIMARY KEY,
                alias TEXT NOT NULL DEFAULT \'\',
                image BLOB NOT NULL,
                capital INTEGER NOT NULL DEFAULT 0,
                captain TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                userid TEXT PRIMARY KEY,
                password TEXT NOT NULL,
                email TEXT NOT NULL DEFAULT \'\',
                theme TEXT NOT NULL DEFAULT \'light\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY,
                layout TEXT NOT NULL,
                skill TEXT NOT NULL,
                date TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS squads (
                id INTEGER PRIMARY KEY,
                campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                zone TEXT NOT NULL,
                slot INTEGER NOT NULL,
                opponent TEXT NOT NULL DEFAULT \'\',
                counter TEXT NOT NULL DEFAULT \'\',
                result TEXT,
                UNIQUE (campaign_id, zone, slot)
            )'
        );
    }
    ensure_user_columns($pdo);
    ensure_ship_captains($pdo);
    ensure_squad_leader_unique($pdo);
    ensure_campaign_date_unique($pdo);
    ensure_catalog_refresh($pdo);
    $user = $pdo->prepare('SELECT 1 FROM users WHERE userid = ?');
    $user->execute([SWGOH_USER_ID]);
    if (!$user->fetchColumn()) {
        $seed = $pdo->prepare('INSERT INTO users (userid, password) VALUES (?, ?)');
        $seed->execute([SWGOH_USER_ID, SWGOH_USER_HASH]);
    }
    $alias = $pdo->prepare('SELECT alias FROM characters WHERE name = ?');
    $alias->execute(['Third Sister']);
    if ($alias->fetchColumn() === '') {
        $fix = $pdo->prepare("UPDATE characters SET alias = 'Reva' WHERE name = ? AND alias = ''");
        $fix->execute(['Third Sister']);
    }
    return $pdo;
}

function capital_captains(): array
{
    return [
        'Chimaera' => 'Grand Admiral Thrawn',
        'Endurance' => 'Mace Windu',
        'Executor' => 'Admiral Piett',
        'Executrix' => 'Grand Moff Tarkin',
        'Finalizer' => 'General Hux',
        'Home One' => 'Admiral Ackbar',
        'Leviathan' => 'Darth Revan',
        'Malevolence' => 'General Grievous',
        'Negotiator' => 'General Kenobi',
        'Profundity' => 'Admiral Raddus',
        'Raddus' => 'Amilyn Holdo',
    ];
}

function ensure_ship_captains(PDO $pdo): void
{
    $names = [];
    foreach ($pdo->query('PRAGMA table_info(ships)') as $col) {
        $names[$col['name']] = true;
    }
    if (!isset($names['captain'])) {
        $pdo->exec("ALTER TABLE ships ADD COLUMN captain TEXT NOT NULL DEFAULT ''");
    }
    $current = [];
    foreach ($pdo->query('SELECT name, captain FROM ships WHERE capital = 1') as $row) {
        $current[$row['name']] = $row['captain'];
    }
    $update = $pdo->prepare('UPDATE ships SET captain = ? WHERE name = ?');
    foreach (capital_captains() as $ship => $captain) {
        if (!array_key_exists($ship, $current) || $current[$ship] === $captain) {
            continue;
        }
        $update->execute([$captain, $ship]);
    }
}

function ensure_catalog_refresh(PDO $pdo): void
{
    $exists = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'catalog_refresh'"
    )->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE catalog_refresh (
            kind TEXT PRIMARY KEY,
            refreshed_at INTEGER NOT NULL
        )'
    );
}

function ensure_user_columns(PDO $pdo): void
{
    $names = [];
    foreach ($pdo->query('PRAGMA table_info(users)') as $col) {
        $names[$col['name']] = true;
    }
    if (!isset($names['email'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    }
    if (!isset($names['theme'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'light'");
    }
    if (!isset($names['access_level'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN access_level TEXT NOT NULL DEFAULT ''");
    }
    $level = $pdo->prepare('SELECT access_level FROM users WHERE userid = ?');
    $level->execute([SWGOH_USER_ID]);
    if ($level->fetchColumn() === '') {
        $grant = $pdo->prepare("UPDATE users SET access_level = 'admin' WHERE userid = ? AND access_level = ''");
        $grant->execute([SWGOH_USER_ID]);
    }
}

function current_user(): array
{
    start_session();
    $stmt = db()->prepare('SELECT userid, email, theme, access_level FROM users WHERE userid = ?');
    $stmt->execute([(string) ($_SESSION['userid'] ?? '')]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'userid' => (string) ($_SESSION['userid'] ?? ''),
            'email' => '',
            'theme' => 'light',
            'access_level' => '',
        ];
    }
    if ($row['theme'] !== 'dark') {
        $row['theme'] = 'light';
    }
    return $row;
}

function is_admin(?array $user = null): bool
{
    $user = $user ?? current_user();
    return ($user['access_level'] ?? '') === 'admin';
}

function request_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function theme_cookie(string $theme): void
{
    if ($theme !== 'dark') {
        $theme = 'light';
    }
    if (($_COOKIE['swgoh_theme'] ?? '') === $theme || headers_sent()) {
        return;
    }
    setcookie('swgoh_theme', $theme, [
        'expires' => time() + 365 * 24 * 3600,
        'path' => '/swgoh/',
        'secure' => request_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['swgoh_theme'] = $theme;
}

function theme_from_cookie(): string
{
    return ($_COOKIE['swgoh_theme'] ?? '') === 'dark' ? 'dark' : 'light';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = SWGOH_SESSION_LIFETIME;
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    $https = request_https();
    $cookie = [
        'lifetime' => $lifetime,
        'path' => '/swgoh/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookie);
    session_start();
    if (!headers_sent()) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $lifetime,
            'path' => '/swgoh/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function require_login(bool $release = false): void
{
    start_session();
    if (empty($_SESSION['userid'])) {
        header('Location: login.php');
        exit;
    }
    if ($release) {
        session_write_close();
    }
}

function require_csrf(): void
{
    $posted = $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), $posted)) {
        http_response_code(400);
        echo 'Invalid request.';
        exit;
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function zone_plan(string $skill, string $layout): array
{
    $plans = [
        'kyber|5v5' => ['tl' => 4, 'bl' => 4, 'tr' => 3, 'br' => 3],
        'kyber|3v3' => ['tl' => 5, 'bl' => 5, 'tr' => 3, 'br' => 5],
        'aurodium|5v5' => ['tl' => 3, 'bl' => 3, 'tr' => 3, 'br' => 3],
        'aurodium|3v3' => ['tl' => 4, 'bl' => 4, 'tr' => 3, 'br' => 4],
    ];
    return $plans[$skill . '|' . $layout] ?? [];
}

function zone_label(string $zone): string
{
    return [
        'tl' => 'Top left',
        'bl' => 'Bottom left',
        'br' => 'Bottom right',
        'tr' => 'Ships',
    ][$zone] ?? $zone;
}

function skill_label(string $skill): string
{
    return $skill === 'aurodium' ? 'Aurodium' : 'Kyber';
}

function initials(string $name): string
{
    $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    foreach ($parts as $part) {
        $out .= $part[0];
    }
    return strtolower($out);
}

function match_score(string $q, string $name, string $alias, string $captain = ''): int
{
    $best = score_label($q, $name);
    if ($alias !== '') {
        $best = max($best, score_label($q, $alias));
    }
    if ($captain !== '') {
        $best = max($best, score_label($q, $captain));
    }
    return $best;
}

function score_label(string $q, string $label): int
{
    $text = strtolower($label);
    $ini = initials($label);
    if ($ini === $q) {
        return 100;
    }
    if (str_starts_with($ini, $q)) {
        return 80;
    }
    foreach (preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (str_starts_with($word, $q)) {
            return 70;
        }
    }
    if (str_starts_with($text, $q)) {
        return 60;
    }
    if (str_contains($text, $q)) {
        return 40;
    }
    return 0;
}

function suggest_units(PDO $pdo, string $q, string $kind): array
{
    $q = strtolower(trim($q));
    if ($q === '') {
        return [];
    }
    if ($kind === 'ship') {
        $rows = $pdo->query('SELECT name, alias, captain FROM ships WHERE capital = 1')->fetchAll();
    } else {
        $rows = $pdo->query("SELECT name, alias, '' AS captain FROM characters")->fetchAll();
    }
    $scored = [];
    foreach ($rows as $row) {
        $score = match_score($q, $row['name'], $row['alias'], (string) $row['captain']);
        if ($score > 0) {
            $scored[] = [
                'name' => $row['name'],
                'alias' => $row['alias'],
                'captain' => (string) $row['captain'],
                'score' => $score,
            ];
        }
    }
    usort($scored, function ($a, $b) {
        return $b['score'] <=> $a['score'] ?: strcasecmp($a['name'], $b['name']);
    });
    $scored = array_slice($scored, 0, 12);
    foreach ($scored as &$row) {
        unset($row['score']);
    }
    return $scored;
}

function ensure_campaign_date_unique(PDO $pdo): void
{
    $have = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'campaigns_date_unique'"
    )->fetchColumn();
    if ($have) {
        return;
    }
    $pdo->exec('CREATE UNIQUE INDEX campaigns_date_unique ON campaigns (date)');
}

function ensure_squad_leader_unique(PDO $pdo): void
{
    $have = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'squads'") as $row) {
        $have[$row['name']] = true;
    }
    if (!isset($have['squads_opponent_unique'])) {
        $pdo->exec(
            'CREATE UNIQUE INDEX squads_opponent_unique
             ON squads (campaign_id, opponent COLLATE NOCASE)
             WHERE opponent <> \'\''
        );
    }
    if (!isset($have['squads_counter_unique'])) {
        $pdo->exec(
            'CREATE UNIQUE INDEX squads_counter_unique
             ON squads (campaign_id, counter COLLATE NOCASE)
             WHERE counter <> \'\''
        );
    }
}

function unit_allowed(PDO $pdo, string $name, bool $ship): bool
{
    if ($name === '') {
        return true;
    }
    if ($ship) {
        $stmt = $pdo->prepare('SELECT 1 FROM ships WHERE name = ? AND capital = 1');
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM characters WHERE name = ?');
    }
    $stmt->execute([$name]);
    return (bool) $stmt->fetchColumn();
}

function counter_history(PDO $pdo, string $opponent, string $layout): array
{
    if ($opponent === '' || ($layout !== '5v5' && $layout !== '3v3')) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT s.counter AS name, COUNT(*) AS n
         FROM squads s
         JOIN campaigns c ON c.id = s.campaign_id
         WHERE s.opponent = ? AND s.result = \'success\' AND s.counter <> \'\'
           AND c.layout = ?
         GROUP BY s.counter
         ORDER BY n DESC, s.counter COLLATE NOCASE
         LIMIT 3'
    );
    $stmt->execute([$opponent, $layout]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = ['name' => $row['name'], 'count' => (int) $row['n']];
    }
    return $rows;
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
