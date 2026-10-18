<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/catalog.php';

$pdo = db();
foreach (['characters', 'ships'] as $kind) {
    if (!catalog_due($pdo, $kind)) {
        continue;
    }
    try {
        catalog_sync($pdo, $kind);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'A refresh is already running.') {
            exit(0);
        }
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
