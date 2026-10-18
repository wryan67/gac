<?php

require_once __DIR__ . '/db.php';

const CATALOG_WEEK = 604800;
const CATALOG_LOCK = '/tmp/swgoh-catalog.lock';
const CATALOG_CHROME_DIR = '/tmp/swgoh-catalog-chrome';
const CATALOG_CHROME_PORT = 9223;

function catalog_due(PDO $pdo, string $kind): bool
{
    $stmt = $pdo->prepare('SELECT refreshed_at FROM catalog_refresh WHERE kind = ?');
    $stmt->execute([$kind]);
    $at = $stmt->fetchColumn();
    return $at === false || (int) $at < time() - CATALOG_WEEK;
}

function catalog_mark_refreshed(PDO $pdo, string $kind): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO catalog_refresh (kind, refreshed_at) VALUES (?, ?)
         ON CONFLICT(kind) DO UPDATE SET refreshed_at = excluded.refreshed_at'
    );
    $stmt->execute([$kind, time()]);
}

function catalog_busy(): bool
{
    $lock = fopen(CATALOG_LOCK, 'c');
    if ($lock === false) {
        return true;
    }
    $busy = !flock($lock, LOCK_EX | LOCK_NB);
    if (!$busy) {
        flock($lock, LOCK_UN);
    }
    fclose($lock);
    return $busy;
}

function catalog_start_background(): void
{
    if (catalog_busy()) {
        return;
    }
    $php = '/usr/bin/php';
    $script = escapeshellarg(__DIR__ . '/refresh_cli.php');
    $log = escapeshellarg('/tmp/swgoh-catalog-refresh.log');
    exec('/usr/bin/setsid ' . $php . ' ' . $script . ' >' . $log . ' 2>&1 < /dev/null &');
}

function catalog_sync(PDO $pdo, string $kind): array
{
    if ($kind !== 'characters' && $kind !== 'ships') {
        throw new InvalidArgumentException('Unknown catalog.');
    }
    $lock = fopen(CATALOG_LOCK, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('A refresh is already running.');
    }
    try {
        if ($kind === 'characters') {
            $units = swgoh_units('https://swgoh.gg/api/characters/');
            catalog_expect_count($units, 300);
            $result = catalog_add_characters($pdo, $units);
        } else {
            $ships = swgoh_units('https://swgoh.gg/api/ships/');
            catalog_expect_count($ships, 60);
            $captains = [];
            foreach ($ships as $ship) {
                if (!empty($ship['capital_ship']) && catalog_is_new($pdo, 'ships', (string) ($ship['name'] ?? ''))) {
                    $crew = swgoh_units('https://swgoh.gg/api/characters/');
                    catalog_expect_count($crew, 300);
                    $captains = catalog_captains($crew);
                    break;
                }
            }
            $result = catalog_add_ships($pdo, $ships, $captains);
        }
        $result['seen'] = $kind === 'characters' ? count($units) : count($ships);
        if ($result['failed'] === []) {
            catalog_mark_refreshed($pdo, $kind);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function catalog_expect_count(array $units, int $minimum): void
{
    if (count($units) < $minimum) {
        throw new RuntimeException('Could not read the swgoh.gg catalog.');
    }
}

function catalog_is_new(PDO $pdo, string $table, string $name): bool
{
    if ($name === '') {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE name = ?");
    $stmt->execute([$name]);
    return !$stmt->fetchColumn();
}

function catalog_add_characters(PDO $pdo, array $units): array
{
    $insert = $pdo->prepare('INSERT INTO characters (name, alias, image) VALUES (?, \'\', ?)');
    $added = [];
    $failed = [];
    foreach ($units as $unit) {
        $name = trim((string) ($unit['name'] ?? ''));
        if ($name === '' || !catalog_is_new($pdo, 'characters', $name)) {
            continue;
        }
        $png = catalog_png((string) ($unit['image'] ?? ''));
        if ($png === null) {
            $failed[] = $name;
            continue;
        }
        $insert->execute([$name, $png]);
        $added[] = $name;
    }
    $pdo->exec("UPDATE characters SET alias = 'Reva' WHERE name = 'Third Sister' AND alias = ''");
    return ['added' => $added, 'failed' => $failed];
}

function catalog_captains(array $characters): array
{
    $captains = [];
    foreach ($characters as $unit) {
        $ship = (string) ($unit['ship'] ?? '');
        if ($ship !== '' && isset($unit['ship_slot']) && (int) $unit['ship_slot'] === 0) {
            $captains[$ship] = trim((string) ($unit['name'] ?? ''));
        }
    }
    return $captains;
}

function catalog_add_ships(PDO $pdo, array $units, array $captains): array
{
    $insert = $pdo->prepare(
        'INSERT INTO ships (name, alias, image, capital, captain) VALUES (?, \'\', ?, ?, ?)'
    );
    $added = [];
    $failed = [];
    foreach ($units as $unit) {
        $name = trim((string) ($unit['name'] ?? ''));
        if ($name === '' || !catalog_is_new($pdo, 'ships', $name)) {
            continue;
        }
        $png = catalog_png((string) ($unit['image'] ?? ''));
        if ($png === null) {
            $failed[] = $name;
            continue;
        }
        $capital = !empty($unit['capital_ship']) ? 1 : 0;
        $captain = $capital ? ($captains[(string) ($unit['base_id'] ?? '')] ?? '') : '';
        $insert->execute([$name, $png, $capital, $captain]);
        $added[] = $name;
    }
    return ['added' => $added, 'failed' => $failed];
}

function catalog_png(string $url): ?string
{
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (!str_starts_with($url, 'https://game-assets.swgoh.gg/')) {
        return null;
    }
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || !str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
        return null;
    }
    return $body;
}

function swgoh_units(string $url): array
{
    $decoded = json_decode(swgoh_page_text($url), true);
    if (!is_array($decoded) || $decoded === [] || !isset($decoded[0]['name'])) {
        throw new RuntimeException('Could not read the swgoh.gg catalog.');
    }
    return $decoded;
}

function swgoh_page_text(string $url): string
{
    catalog_stop_chrome();
    @mkdir('/tmp/swgoh-chrome-home', 0700, true);
    $cmd = [
        'google-chrome',
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-blink-features=AutomationControlled',
        '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        '--remote-debugging-port=' . CATALOG_CHROME_PORT,
        '--user-data-dir=' . CATALOG_CHROME_DIR,
        'about:blank',
    ];
    $proc = proc_open($cmd, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/tmp/swgoh-catalog-chrome.log', 'a'],
    ], $pipes, null, ['HOME' => '/tmp/swgoh-chrome-home']);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not open swgoh.gg.');
    }
    try {
        $version = null;
        $deadline = time() + 15;
        while (time() < $deadline) {
            $version = @file_get_contents('http://127.0.0.1:' . CATALOG_CHROME_PORT . '/json/version');
            if (is_string($version) && $version !== '') {
                break;
            }
            usleep(200000);
        }
        if (!is_string($version) || $version === '') {
            throw new RuntimeException('Could not open swgoh.gg.');
        }
        $context = stream_context_create(['http' => ['method' => 'PUT', 'timeout' => 5]]);
        $tab = json_decode((string) file_get_contents(
            'http://127.0.0.1:' . CATALOG_CHROME_PORT . '/json/new?about:blank',
            false,
            $context
        ), true);
        $ws = parse_url((string) ($tab['webSocketDebuggerUrl'] ?? ''));
        $sock = fsockopen($ws['host'], (int) $ws['port'], $errno, $err, 5);
        if ($sock === false) {
            throw new RuntimeException('Could not open swgoh.gg.');
        }
        stream_set_timeout($sock, 60);
        $key = base64_encode(random_bytes(16));
        fwrite($sock, "GET {$ws['path']} HTTP/1.1\r\nHost: {$ws['host']}:{$ws['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $header = '';
        while (!str_contains($header, "\r\n\r\n")) {
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Could not open swgoh.gg.');
            }
            $header .= $chunk;
        }
        $id = 0;
        $call = function (string $method, array $params = []) use ($sock, &$id) {
            $my = ++$id;
            catalog_ws_send($sock, ['id' => $my, 'method' => $method, 'params' => $params]);
            $deadline = time() + 30;
            while (time() < $deadline) {
                $raw = catalog_ws_read($sock);
                if ($raw === null) {
                    continue;
                }
                $msg = json_decode($raw, true);
                if (is_array($msg) && ($msg['id'] ?? null) === $my) {
                    return $msg['result'] ?? null;
                }
            }
            throw new RuntimeException('Could not read the swgoh.gg catalog.');
        };
        $call('Page.enable');
        $call('Page.addScriptToEvaluateOnNewDocument', [
            'source' => 'Object.defineProperty(navigator, "webdriver", {get: () => undefined})',
        ]);
        $call('Page.navigate', ['url' => $url]);
        $offset = 0;
        $assembled = '';
        $seenLen = null;
        $deadline = time() + 75;
        while (time() < $deadline) {
            $expression = '(() => {'
                . 'const t = (document.body && document.body.innerText) ? document.body.innerText.trim() : "";'
                . 'if (!t.startsWith("[")) return JSON.stringify({ready:false,len:0,start:0,b64:""});'
                . 'const bytes = new TextEncoder().encode(t);'
                . 'const start = ' . $offset . ';'
                . 'const slice = bytes.subarray(start, Math.min(bytes.length, start + 24000));'
                . 'let bin = "";'
                . 'for (let i = 0; i < slice.length; i++) bin += String.fromCharCode(slice[i]);'
                . 'return JSON.stringify({ready:true,len:bytes.length,start:start,b64:btoa(bin)});'
                . '})()';
            $res = $call('Runtime.evaluate', [
                'expression' => $expression,
                'returnByValue' => true,
            ]);
            $info = json_decode((string) ($res['result']['value'] ?? ''), true);
            if (!is_array($info) || empty($info['ready'])) {
                $offset = 0;
                $assembled = '';
                $seenLen = null;
                usleep(500000);
                continue;
            }
            $len = (int) $info['len'];
            if ($seenLen !== null && $len !== $seenLen) {
                $offset = 0;
                $assembled = '';
                $seenLen = null;
                continue;
            }
            $seenLen = $len;
            if ((int) $info['start'] !== $offset) {
                $offset = 0;
                $assembled = '';
                $seenLen = null;
                continue;
            }
            $piece = base64_decode((string) ($info['b64'] ?? ''), true);
            if ($piece === false) {
                $offset = 0;
                $assembled = '';
                $seenLen = null;
                continue;
            }
            $assembled .= $piece;
            $offset += strlen($piece);
            if ($offset < $len) {
                continue;
            }
            if ($offset === $len) {
                $decoded = json_decode($assembled, true);
                if (is_array($decoded) && $decoded !== [] && isset($decoded[0]['name'])) {
                    fclose($sock);
                    return $assembled;
                }
            }
            $offset = 0;
            $assembled = '';
            $seenLen = null;
            usleep(500000);
        }
        fclose($sock);
        throw new RuntimeException('Could not read the swgoh.gg catalog.');
    } finally {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        catalog_stop_chrome();
    }
}

function catalog_stop_chrome(): void
{
    exec('pkill -f ' . escapeshellarg('user-data-dir=' . CATALOG_CHROME_DIR) . ' >/dev/null 2>&1');
}

function catalog_ws_send($sock, array $obj): void
{
    $data = json_encode($obj);
    $len = strlen($data);
    $mask = random_bytes(4);
    if ($len < 126) {
        $header = chr(0x81) . chr(0x80 | $len);
    } elseif ($len < 65536) {
        $header = chr(0x81) . chr(0xFE) . pack('n', $len);
    } else {
        $header = chr(0x81) . chr(0xFF) . pack('N', ($len >> 32) & 0xFFFFFFFF) . pack('N', $len & 0xFFFFFFFF);
    }
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $data[$i] ^ $mask[$i % 4];
    }
    fwrite($sock, $header . $mask . $masked);
}

function catalog_ws_need($sock, string &$buf, int $need): bool
{
    while (strlen($buf) < $need) {
        $chunk = fread($sock, 65536);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $buf .= $chunk;
    }
    return true;
}

function catalog_ws_read($sock): ?string
{
    $buf = '';
    $payload = '';
    while (true) {
        if (!catalog_ws_need($sock, $buf, 2)) {
            return null;
        }
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $opcode = $b0 & 0x0F;
        $len = $b1 & 0x7F;
        $masked = ($b1 & 0x80) !== 0;
        $off = 2;
        if ($len === 126) {
            if (!catalog_ws_need($sock, $buf, 4)) {
                return null;
            }
            $len = unpack('n', substr($buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if (!catalog_ws_need($sock, $buf, 10)) {
                return null;
            }
            $parts = unpack('Nhi/Nlo', substr($buf, 2, 8));
            $len = ($parts['hi'] * 4294967296) + $parts['lo'];
            $off = 10;
        }
        $maskLen = $masked ? 4 : 0;
        if ($len < 0 || $len > 20000000) {
            throw new RuntimeException('Could not read the swgoh.gg catalog.');
        }
        if (!catalog_ws_need($sock, $buf, $off + $maskLen + $len)) {
            return null;
        }
        $piece = substr($buf, $off + $maskLen, $len);
        if ($masked) {
            $mask = substr($buf, $off, 4);
            $plain = '';
            for ($i = 0; $i < $len; $i++) {
                $plain .= $piece[$i] ^ $mask[$i % 4];
            }
            $piece = $plain;
        }
        $buf = substr($buf, $off + $maskLen + $len);
        if ($opcode === 1 || $opcode === 0) {
            $payload .= $piece;
        }
        if (($b0 & 0x80) !== 0 && ($opcode === 1 || $opcode === 0)) {
            return $payload;
        }
        if ($opcode === 8 || $opcode === 9) {
            if ($opcode === 8) {
                return null;
            }
            continue;
        }
    }
}
