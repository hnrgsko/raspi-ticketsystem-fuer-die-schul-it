<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';

app_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

app_start_session(SCHULIT_ADMIN_SESSIONS, 'schulit_admin');

try {
    $db = app_database();
    $user = app_admin_user($db);
    if ($user === null || ($user['role'] ?? '') !== 'system_admin') {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Nicht berechtigt.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $path = '/var/lib/schulit/update-state/development.json';
    $status = [];
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) $status = $decoded;
    }

    $allowed = [
        'state','started_at','finished_at','progress','current_step','current_label',
        'completed_steps','steps','message'
    ];
    $safe = ['ok'=>true];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $status)) $safe[$key] = $status[$key];
    }

    echo json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Status derzeit nicht verfügbar.'], JSON_UNESCAPED_UNICODE);
}
