<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

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

    $metric = $_POST['metric'] ?? null;
    if (!is_string($metric) || !in_array($metric, [
        'assistant_inline_use',
        'assistant_bubble_open',
        'assistant_external_open',
        'faq_public_open',
        'faq_suggestion_open',
        'faq_suggestion_helpful',
    ], true)) {
        http_response_code(400);
        exit;
    }

    if (!app_session_rate($_SESSION, 'usage_events', 120, 900)) {
        http_response_code(429);
        exit;
    }

    app_usage_record(app_database(), $metric);
    http_response_code(204);
} catch (Throwable) {
    http_response_code(204);
}
