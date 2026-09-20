<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

app_security_headers();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    app_start_session(SCHULIT_PUBLIC_SESSIONS, 'schulit_public');
    if (!app_public_authorized()) {
        http_response_code(403);
        exit;
    }
    if (!app_csrf_valid($_SESSION, $_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit;
    }
    if (!app_session_rate($_SESSION, 'faq_suggest_search', 80, 300)) {
        http_response_code(429);
        echo json_encode(['ok'=>false,'error'=>'Zu viele Suchanfragen.']);
        exit;
    }

    $db = app_database();
    $suggestions = app_faq_suggestions(
        $db,
        $_POST['category_id'] ?? null,
        $_POST['q'] ?? null,
        3
    );

    $public = [];
    foreach ($suggestions as $entry) {
        $public[] = [
            'id'=>(string)$entry['id'],
            'question'=>(string)$entry['question'],
            'answer'=>(string)$entry['answer'],
            'category_name'=>(string)($entry['category_name'] ?? ''),
        ];
    }

    echo json_encode(
        ['ok'=>true,'suggestions'=>$public],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'FAQ-Vorschläge sind gerade nicht verfügbar.']);
}
