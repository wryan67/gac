<?php

require __DIR__ . '/db.php';
require_once __DIR__ . '/catalog.php';

start_session();
if (empty($_SESSION['userid'])) {
    json_out(['error' => 'login'], 401);
}

$api = $_GET['api'] ?? '';
$pdo = db();

if ($api === 'suggest') {
    $kind = ($_GET['kind'] ?? '') === 'ship' ? 'ship' : 'character';
    json_out(['matches' => suggest_units($pdo, (string) ($_GET['q'] ?? ''), $kind)]);
}

if ($api === 'counters') {
    $campaignId = (int) ($_GET['campaign'] ?? 0);
    $layoutStmt = $pdo->prepare('SELECT layout FROM campaigns WHERE id = ?');
    $layoutStmt->execute([$campaignId]);
    json_out([
        'suggestions' => counter_history($pdo, trim((string) ($_GET['opponent'] ?? '')), (string) $layoutStmt->fetchColumn()),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST required'], 405);
}

$posted = $_POST['csrf'] ?? '';
if (!hash_equals(csrf_token(), $posted)) {
    json_out(['error' => 'Invalid request.'], 400);
}

if ($api === 'campaigns') {
    campaigns_grid($pdo);
}

if ($api === 'roster') {
    roster_grid($pdo);
}

if ($api === 'catalog_refresh') {
    if (!is_admin()) {
        json_out(['error' => 'Not allowed.'], 403);
    }
    $kind = ($_POST['kind'] ?? '') === 'ships' ? 'ships' : 'characters';
    set_time_limit(180);
    try {
        $result = catalog_sync($pdo, $kind);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 409);
    }
    json_out([
        'ok' => true,
        'added' => $result['added'],
        'failed' => $result['failed'],
    ]);
}

if ($api === 'campaign_create') {
    campaign_create($pdo);
}

if ($api === 'campaign_delete') {
    campaign_delete($pdo);
}

if ($api === 'squad_save') {
    squad_save($pdo);
}

json_out(['error' => 'Unknown request'], 404);

function bootgrid_page(): array
{
    $current = max(1, (int) ($_POST['current'] ?? 1));
    $rowCount = (int) ($_POST['rowCount'] ?? 10);
    if (!in_array($rowCount, [10, 25, 50, -1], true)) {
        $rowCount = 10;
    }
    $search = trim((string) ($_POST['searchPhrase'] ?? ''));
    return [$current, $rowCount, $search];
}

function campaigns_grid(PDO $pdo): void
{
    [$current, $rowCount, $search] = bootgrid_page();
    $layout = $_POST['layout'] ?? '';
    $skill = $_POST['skill'] ?? '';
    if (!in_array($layout, ['5v5', '3v3'], true)) {
        $layout = '';
    }
    if (!in_array($skill, ['kyber', 'aurodium'], true)) {
        $skill = '';
    }

    $where = [];
    $params = [];
    if ($layout !== '') {
        $where[] = 'layout = ?';
        $params[] = $layout;
    }
    if ($skill !== '') {
        $where[] = 'skill = ?';
        $params[] = $skill;
    }
    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = '(layout LIKE ? ESCAPE \'\\\' OR skill LIKE ? ESCAPE \'\\\' OR date LIKE ? ESCAPE \'\\\')';
        array_push($params, $like, $like, $like);
    }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderBy = 'date DESC, id DESC';
    $sort = $_POST['sort'] ?? null;
    if (is_array($sort)) {
        foreach ($sort as $field => $dir) {
            if (in_array($field, ['layout', 'skill', 'date'], true)) {
                $dir = strtolower((string) $dir) === 'desc' ? 'DESC' : 'ASC';
                $orderBy = "\"{$field}\" {$dir}, id DESC";
                break;
            }
        }
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns {$sqlWhere}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $limit = '';
    if ($rowCount !== -1) {
        $limit = 'LIMIT ' . (int) $rowCount . ' OFFSET ' . (int) (($current - 1) * $rowCount);
    }
    $stmt = $pdo->prepare("SELECT id, layout, skill, date FROM campaigns {$sqlWhere} ORDER BY {$orderBy} {$limit}");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'layout' => $row['layout'],
            'skill' => $row['skill'],
            'skill_label' => skill_label($row['skill']),
            'date' => $row['date'],
        ];
    }
    json_out([
        'current' => $current,
        'rowCount' => $rowCount,
        'rows' => $rows,
        'total' => $total,
    ]);
}

function roster_characters_scored(PDO $pdo, int $current, int $rowCount, string $search): void
{
    $scored = [];
    foreach ($pdo->query('SELECT name, alias FROM characters') as $row) {
        $score = match_score($search, $row['name'], $row['alias']);
        if ($score > 0) {
            $scored[] = ['name' => $row['name'], 'alias' => $row['alias'], 'score' => $score];
        }
    }
    usort($scored, function ($a, $b) {
        return $b['score'] <=> $a['score'] ?: strcasecmp($a['name'], $b['name']);
    });
    $total = count($scored);
    if ($rowCount !== -1) {
        $scored = array_slice($scored, ($current - 1) * $rowCount, $rowCount);
    }
    $rows = [];
    foreach ($scored as $row) {
        $rows[] = [
            'id' => $row['name'],
            'name' => $row['name'],
            'alias' => $row['alias'],
        ];
    }
    json_out([
        'current' => $current,
        'rowCount' => $rowCount,
        'rows' => $rows,
        'total' => $total,
    ]);
}

function roster_grid(PDO $pdo): void
{
    [$current, $rowCount, $search] = bootgrid_page();
    $kind = ($_POST['kind'] ?? '') === 'ships' ? 'ships' : 'characters';
    $table = $kind === 'ships' ? 'ships' : 'characters';

    $where = '';
    $params = [];
    $filters = [];
    if ($kind === 'ships' && (($_POST['capital'] ?? '') === '1')) {
        $filters[] = 'capital = 1';
    }
    if ($search !== '' && $kind === 'ships') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $filters[] = "(name LIKE ? ESCAPE '\\' OR alias LIKE ? ESCAPE '\\' OR captain LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like, $like);
    }
    if ($filters) {
        $where = 'WHERE ' . implode(' AND ', $filters);
    }
    if ($search !== '' && $kind === 'characters') {
        roster_characters_scored($pdo, $current, $rowCount, strtolower($search));
    }

    $orderBy = 'name COLLATE NOCASE ASC';
    $sort = $_POST['sort'] ?? null;
    $sortable = $kind === 'ships' ? ['name', 'alias', 'capital', 'captain'] : ['name', 'alias'];
    if (is_array($sort)) {
        foreach ($sort as $field => $dir) {
            if (in_array($field, $sortable, true)) {
                $dir = strtolower((string) $dir) === 'desc' ? 'DESC' : 'ASC';
                $orderBy = "\"{$field}\" {$dir}";
                break;
            }
        }
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $cols = $kind === 'ships' ? 'name, alias, capital, captain' : 'name, alias';
    $limit = '';
    if ($rowCount !== -1) {
        $limit = 'LIMIT ' . (int) $rowCount . ' OFFSET ' . (int) (($current - 1) * $rowCount);
    }
    $stmt = $pdo->prepare("SELECT {$cols} FROM {$table} {$where} ORDER BY {$orderBy} {$limit}");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $item = [
            'id' => $row['name'],
            'name' => $row['name'],
            'alias' => $row['alias'],
        ];
        if ($kind === 'ships') {
            $item['capital'] = (int) $row['capital'];
            $item['captain'] = $row['captain'];
        }
        $rows[] = $item;
    }
    json_out([
        'current' => $current,
        'rowCount' => $rowCount,
        'rows' => $rows,
        'total' => $total,
    ]);
}

function campaign_create(PDO $pdo): void
{
    $layout = $_POST['layout'] ?? '';
    $skill = $_POST['skill'] ?? '';
    $date = $_POST['date'] ?? '';
    $plan = zone_plan($skill, $layout);
    if (!$plan || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_out(['error' => 'Choose a layout, a skill level, and a date.'], 400);
    }
    $pdo->beginTransaction();
    try {
        $taken = $pdo->prepare('SELECT 1 FROM campaigns WHERE date = ?');
        $taken->execute([$date]);
        if ($taken->fetchColumn()) {
            $pdo->rollBack();
            json_out(['error' => 'A campaign already uses ' . $date . '.'], 409);
        }
        $insert = $pdo->prepare('INSERT INTO campaigns (layout, skill, date) VALUES (?, ?, ?)');
        $insert->execute([$layout, $skill, $date]);
        $id = (int) $pdo->lastInsertId();
        $squad = $pdo->prepare(
            'INSERT INTO squads (campaign_id, zone, slot, opponent, counter, result) VALUES (?, ?, ?, \'\', \'\', NULL)'
        );
        foreach ($plan as $zone => $count) {
            for ($slot = 1; $slot <= $count; $slot++) {
                $squad->execute([$id, $zone, $slot]);
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (($e->errorInfo[0] ?? '') === '23000') {
            json_out(['error' => 'A campaign already uses ' . $date . '.'], 409);
        }
        throw $e;
    }
    json_out(['ok' => true, 'id' => $id]);
}

function campaign_delete(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM campaigns WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() !== 1) {
        json_out(['error' => 'Campaign not found.'], 404);
    }
    json_out(['ok' => true]);
}

function squad_save(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $opponent = trim((string) ($_POST['opponent'] ?? ''));
    $counter = trim((string) ($_POST['counter'] ?? ''));
    $result = $_POST['result'] ?? '';
    if (!in_array($result, ['', 'success', 'fail'], true)) {
        json_out(['error' => 'Unknown result.'], 400);
    }
    $stmt = $pdo->prepare(
        'SELECT s.id, s.zone, s.campaign_id FROM squads s WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $squad = $stmt->fetch();
    if (!$squad) {
        json_out(['error' => 'Squad not found.'], 404);
    }
    $ship = $squad['zone'] === 'tr';
    if (!unit_allowed($pdo, $opponent, $ship) || !unit_allowed($pdo, $counter, $ship)) {
        json_out(['error' => $ship ? 'Choose a capital ship.' : 'Choose a character from the list.'], 400);
    }
    $campaignId = (int) $squad['campaign_id'];
    $pdo->beginTransaction();
    try {
        $messages = [];
        if (squad_leader_taken($pdo, $campaignId, $id, $opponent, 'opponent')) {
            $messages[] = $opponent . ' is already an opponent in this campaign.';
        }
        if (squad_leader_taken($pdo, $campaignId, $id, $counter, 'counter')) {
            $messages[] = $counter . ' is already used as this counter in this campaign.';
        }
        if ($messages) {
            $pdo->rollBack();
            json_out(['error' => implode("\n", $messages)], 409);
        }
        $update = $pdo->prepare('UPDATE squads SET opponent = ?, counter = ?, result = ? WHERE id = ?');
        $update->execute([$opponent, $counter, $result === '' ? null : $result, $id]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'squads_opponent_unique') || str_contains($e->getMessage(), 'squads_counter_unique')) {
            json_out(['error' => 'That leader is already used in this campaign.'], 409);
        }
        throw $e;
    }
    $layoutStmt = $pdo->prepare('SELECT layout FROM campaigns WHERE id = ?');
    $layoutStmt->execute([$campaignId]);
    json_out([
        'ok' => true,
        'suggestions' => counter_history($pdo, $opponent, (string) $layoutStmt->fetchColumn()),
    ]);
}

function squad_leader_taken(PDO $pdo, int $campaignId, int $squadId, string $name, string $column): bool
{
    if ($name === '') {
        return false;
    }
    $sql = $column === 'opponent'
        ? 'SELECT 1 FROM squads WHERE campaign_id = ? AND id <> ? AND opponent = ? COLLATE NOCASE LIMIT 1'
        : 'SELECT 1 FROM squads WHERE campaign_id = ? AND id <> ? AND counter = ? COLLATE NOCASE LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$campaignId, $squadId, $name]);
    $taken = (bool) $stmt->fetchColumn();
    $stmt->closeCursor();
    return $taken;
}
