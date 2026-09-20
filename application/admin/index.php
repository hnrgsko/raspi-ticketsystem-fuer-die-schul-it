<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');

app_start_session(SCHULIT_ADMIN_SESSIONS, 'schulit_admin');

function admin_post_filters(array $post): array
{
    $mapped = [];
    foreach (['view','q','status','priority','type','category','sort'] as $key) {
        $value = $post['filter_' . $key] ?? '';
        $mapped[$key] = is_string($value) ? $value : '';
    }
    return admin_filters($mapped);
}

function admin_filter_hidden(array $filters): string
{
    $html = '';
    foreach ($filters as $key => $value) {
        $html .= '<input type="hidden" name="filter_' . app_escape((string)$key)
            . '" value="' . app_escape((string)$value) . '">';
    }
    return $html;
}

function admin_filters(array $query): array
{
    $view = (($query['view'] ?? '') === 'archive') ? 'archive' : 'active';
    $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';
    if (mb_strlen($q) > 200) $q = '';

    $status = is_string($query['status'] ?? null)
        && in_array($query['status'], ['new','in_progress','awaiting_reply','done'], true)
        ? $query['status'] : '';

    $priority = is_string($query['priority'] ?? null)
        && in_array($query['priority'], ['low','normal','high'], true)
        ? $query['priority'] : '';

    $type = is_string($query['type'] ?? null)
        && in_array($query['type'], ['support','defect'], true)
        ? $query['type'] : '';

    $category = is_string($query['category'] ?? null)
        && preg_match('/\A[1-9][0-9]{0,18}\z/', $query['category']) === 1
        ? $query['category'] : '';

    $sort = is_string($query['sort'] ?? null)
        && in_array($query['sort'], ['priority','newest','oldest','updated'], true)
        ? $query['sort'] : 'priority';

    return compact('view','q','status','priority','type','category','sort');
}

function admin_url(array $filters, array $extra = []): string
{
    $params = array_merge($filters, $extra);
    if (($params['view'] ?? 'active') === 'active') unset($params['view']);
    if (($params['sort'] ?? 'priority') === 'priority') unset($params['sort']);
    foreach ($params as $key => $value) {
        if (!is_string($value) || $value === '') unset($params[$key]);
    }
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return '/admin/' . ($query === '' ? '' : '?' . $query);
}

function admin_icon(string $name): string
{
    $open = '<svg class="quick-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    return match ($name) {
        'in_progress' => $open . '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'awaiting_reply' => $open . '<path d="M4 5h16v12H9l-5 3V5z"/><path d="M9.5 9a2.5 2.5 0 1 1 3.8 2.1c-.9.6-1.3 1-1.3 1.9"/><path d="M12 16h.01"/></svg>',
        'done' => $open . '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.7 2.7L16.5 9"/></svg>',
        'archive' => $open . '<path d="M4 7h16v13H4z"/><path d="M3 4h18v3H3z"/><path d="M12 10v6m-2-2 2 2 2-2"/></svg>',
        default => '',
    };
}

$error = '';
$message = '';
$db = null;
$user = null;
$schoolName = 'Schul-IT Ticketsystem';

try {
    $db = app_database();
    $schoolName = app_setting($db, 'school_name', $schoolName);
} catch (Throwable $caught) {
    $error = $caught->getMessage();
}

$loginCsrf = app_csrf($_SESSION, 'admin_csrf');

if ($db instanceof PDO) {
    $user = app_admin_user($db);
}

$filters = admin_filters($_GET);
$section = is_string($_GET['section'] ?? null) ? $_GET['section'] : '';
if (!in_array($section, ['system','faq','stats'], true)) $section = '';

if ($db instanceof PDO && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if ($action === 'login' && $user === null) {
            if (!app_csrf_valid($_SESSION, $_POST['csrf'] ?? null, 'admin_csrf')) {
                throw new RuntimeException('Die Anmeldung ist abgelaufen. Bitte die Seite neu laden.');
            }
            if (!app_session_rate($_SESSION, 'admin_login', 12, 900)) {
                throw new RuntimeException('Zu viele Anmeldeversuche. Bitte später erneut versuchen.');
            }
            $user = app_admin_login($db, $_POST['username'] ?? null, $_POST['password'] ?? null);
            if ($user === null) {
                throw new RuntimeException('Benutzername oder Passwort ist nicht korrekt.');
            }
            header('Location: /admin/', true, 303);
            exit;
        }

        if ($user === null) {
            throw new RuntimeException('Bitte erneut anmelden.');
        }
        if (!app_csrf_valid($_SESSION, $_POST['csrf'] ?? null, 'admin_csrf')) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: /admin/', true, 303);
            exit;
        }

        if ($action === 'quick_status') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            $status = (string)($_POST['status'] ?? '');
            app_admin_quick_status($db, $ticketId, (string)$user['id'], $status);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ' → ' . app_status_label($status) . '.';
            header('Location: ' . admin_url(admin_post_filters($_POST)), true, 303);
            exit;
        }

        if ($action === 'quick_archive') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            app_admin_archive($db, $ticketId, true);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ' wurde archiviert.';
            header('Location: ' . admin_url(admin_post_filters($_POST)), true, 303);
            exit;
        }

        if ($action === 'update_ticket') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            app_admin_update_ticket($db, $ticketId, (string)$user['id'], $_POST);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ' wurde aktualisiert.';
            $next = admin_post_filters($_POST);
            header('Location: ' . admin_url($next, ['ticket'=>$ticketId]), true, 303);
            exit;
        }

        if ($action === 'archive_ticket' || $action === 'restore_ticket') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            $archive = $action === 'archive_ticket';
            app_admin_archive($db, $ticketId, $archive);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ($archive ? ' wurde archiviert.' : ' wurde wiederhergestellt.');
            $next = admin_post_filters($_POST);
            $next['view'] = $archive ? 'archive' : 'active';
            header('Location: ' . admin_url($next, ['ticket'=>$ticketId]), true, 303);
            exit;
        }

        if ($action === 'save_assistant') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen Systemeinstellungen ändern.');
            }
            app_admin_save_assistant_settings($db, $_POST);
            $_SESSION['admin_notice'] = 'Einstellungen des KI-Assistenten wurden gespeichert.';
            header('Location: /admin/?section=system', true, 303);
            exit;
        }

        if ($action === 'faq_settings') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen FAQ-Systemeinstellungen ändern.');
            }
            app_faq_admin_save_settings($db, $_POST);
            $_SESSION['admin_notice'] = 'FAQ-Automatik wurde gespeichert.';
            header('Location: /admin/?section=faq', true, 303);
            exit;
        }

        if ($action === 'faq_from_ticket') {
            $proposalId = app_faq_ticket_proposal(
                $db,
                (string)($_POST['ticket_id'] ?? ''),
                (string)$user['id']
            );
            $_SESSION['admin_notice'] = 'FAQ-Entwurf wurde aus dem erledigten Ticket erzeugt.';
            header('Location: /admin/?section=faq#faq-proposal-' . rawurlencode($proposalId), true, 303);
            exit;
        }

        if ($action === 'faq_create') {
            $proposalId = app_faq_admin_create_proposal($db, (string)$user['id'], $_POST);
            $_SESSION['admin_notice'] = 'FAQ-Entwurf wurde angelegt und wartet auf Freigabe.';
            header('Location: /admin/?section=faq#faq-proposal-' . rawurlencode($proposalId), true, 303);
            exit;
        }

        if ($action === 'faq_publish') {
            $proposalId = (string)($_POST['proposal_id'] ?? '');
            app_faq_admin_publish($db, $proposalId, (string)$user['id'], $_POST);
            $_SESSION['admin_notice'] = 'FAQ wurde veröffentlicht.';
            header('Location: /admin/?section=faq', true, 303);
            exit;
        }

        if ($action === 'faq_reject') {
            app_faq_admin_reject(
                $db,
                (string)($_POST['proposal_id'] ?? ''),
                (string)$user['id']
            );
            $_SESSION['admin_notice'] = 'FAQ-Vorschlag wurde verworfen.';
            header('Location: /admin/?section=faq', true, 303);
            exit;
        }

        if ($action === 'faq_entry_status') {
            app_faq_admin_set_entry_status(
                $db,
                (string)($_POST['entry_id'] ?? ''),
                (string)$user['id'],
                (string)($_POST['entry_status'] ?? '')
            );
            $_SESSION['admin_notice'] = 'FAQ-Status wurde geändert.';
            header('Location: /admin/?section=faq', true, 303);
            exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
    }
}

if ($db instanceof PDO && $user !== null) {
    $user = app_admin_user($db);
}
$csrf = $user !== null ? app_csrf($_SESSION, 'admin_csrf') : $loginCsrf;
$message = is_string($_SESSION['admin_notice'] ?? null) ? $_SESSION['admin_notice'] : '';
unset($_SESSION['admin_notice']);

$categories = ($user !== null && $db instanceof PDO) ? app_admin_category_list($db) : [];
$assistant = ($db instanceof PDO) ? app_assistant_settings($db) : [
    'enabled'=>false,
    'label'=>'KI-Assistent',
    'url'=>'',
    'widget_enabled'=>false,
    'widget_url'=>null,
];
$faqReady = $db instanceof PDO && app_faq_tables_ready($db);
$faqAutoFromDone = $db instanceof PDO ? app_faq_auto_from_done($db) : true;
$faqPending = ($user !== null && $faqReady && $section === 'faq') ? app_faq_admin_pending($db) : [];
$faqEntries = ($user !== null && $faqReady && $section === 'faq') ? app_faq_admin_entries($db) : [];
$usageReady = $db instanceof PDO && app_usage_tables_ready($db);
$usageSummary = ($user !== null && $usageReady && $section === 'stats') ? app_admin_usage_summary($db) : [];
$ticketStats = ($user !== null && $db instanceof PDO && $section === 'stats') ? app_admin_ticket_statistics($db) : [];
$usageDaily = ($user !== null && $usageReady && $section === 'stats') ? app_admin_usage_daily($db, 30) : [];
$ticketId = is_string($_GET['ticket'] ?? null) ? $_GET['ticket'] : '';
$detail = ($user !== null && $db instanceof PDO && $ticketId !== '' && $section === '')
    ? app_admin_ticket($db, $ticketId) : null;
$tickets = ($user !== null && $db instanceof PDO && $detail === null && $section === '')
    ? app_admin_tickets($db, $filters) : [];
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schul-IT · Adminbereich</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Schul-IT · Adminbereich</a>
  <?php if ($user !== null): ?>
  <div class="admin-user">
    <span><?= app_escape((string)$user['display_name']) ?> · <?= ($user['role'] ?? '') === 'system_admin' ? 'System-Admin' : 'Ticket-Admin' ?></span>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="link-button" type="submit">Abmelden</button>
    </form>
  </div>
  <?php endif; ?>
</header>

<main class="admin-main">
<?php if ($error !== ''): ?><p class="error admin-message" role="alert"><?= app_escape($error) ?></p><?php endif; ?>
<?php if ($message !== ''): ?><p class="success admin-message" role="status"><?= app_escape($message) ?></p><?php endif; ?>

<?php if (!$db instanceof PDO): ?>
<section class="panel"><h1>Adminbereich nicht verfügbar</h1><p>Die lokale Datenbank ist gerade nicht erreichbar.</p></section>

<?php elseif ($user === null): ?>
<section class="panel login-panel">
<span class="label">Getrennter Zugang</span>
<h1>Admin-Anmeldung</h1>
<p>Dieser Zugang ist unabhängig vom privaten Kollegiumslink.</p>
<form class="admin-form" method="post">
<input type="hidden" name="action" value="login">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<label for="username">Benutzername</label>
<input id="username" name="username" maxlength="100" autocomplete="username" required autofocus>
<label for="password">Passwort</label>
<input id="password" name="password" type="password" maxlength="1024" autocomplete="current-password" required>
<button type="submit">Anmelden</button>
</form>
</section>

<?php else: ?>
<nav class="admin-primary-nav" aria-label="Adminbereiche">
<a class="<?= $section==='' && $filters['view']==='active' ? 'active' : '' ?>" href="/admin/">Aktive Tickets</a>
<a class="<?= $section==='' && $filters['view']==='archive' ? 'active' : '' ?>" href="/admin/?view=archive">Archiv</a>
<a class="<?= $section==='stats' ? 'active' : '' ?>" href="/admin/?section=stats">Statistik</a>
<?php if ($faqReady): ?><a class="<?= $section==='faq' ? 'active' : '' ?>" href="/admin/?section=faq">FAQ<?php if ($faqPending !== []): ?> (<?= count($faqPending) ?>)<?php endif; ?></a><?php endif; ?>
<?php if (($user['role'] ?? '') === 'system_admin'): ?><a class="<?= $section==='system' ? 'active' : '' ?>" href="/admin/?section=system">System</a><?php endif; ?>
<a href="/">Kollegiumsseite</a>
<?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?><a href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape((string)$assistant['label']) ?></a><?php endif; ?>
</nav>

<?php if ($section === 'stats'): ?>
<section class="panel stats-panel">
<span class="label">Übersicht</span>
<h1>Statistik</h1>
<p>Die Statistik zählt ausschließlich aggregierte Nutzungen. Es werden keine IP-Adressen, Namen, Browserkennungen oder Chat-Inhalte gespeichert.</p>

<h2>Tickets</h2>
<div class="stats-cards">
  <article class="stats-card"><span>Heute</span><strong><?= (int)($ticketStats['today'] ?? 0) ?></strong><small>neue Tickets</small></article>
  <article class="stats-card"><span>Letzte 7 Tage</span><strong><?= (int)($ticketStats['7d'] ?? 0) ?></strong><small>neue Tickets</small></article>
  <article class="stats-card"><span>Letzte 30 Tage</span><strong><?= (int)($ticketStats['30d'] ?? 0) ?></strong><small>neue Tickets</small></article>
  <article class="stats-card"><span>Gesamt</span><strong><?= (int)($ticketStats['all'] ?? 0) ?></strong><small>Tickets</small></article>
  <article class="stats-card"><span>Aktuell offen</span><strong><?= (int)($ticketStats['open'] ?? 0) ?></strong><small>nicht erledigt</small></article>
  <article class="stats-card"><span>Erledigt</span><strong><?= (int)($ticketStats['done'] ?? 0) ?></strong><small>Tickets</small></article>
</div>

<h2>KI-Assistent</h2>
<?php if (!$usageReady): ?>
<p class="notice">Die Nutzungsstatistik wird nach Anwendung der Statistik-Migration verfügbar.</p>
<?php else: ?>
<p class="muted stats-explainer">„Nutzungen“ sind Öffnungen bzw. tatsächliche Einstiege. „Sitzungen“ werden pro Zugangsweg und Kalendertag höchstens einmal je Browsersitzung gezählt. Dieselbe Sitzung kann mehrere Zugangswege verwenden.</p>
<?php $afterAssistant = $usageSummary['ticket_after_assistant'] ?? []; ?>
<div class="stats-highlight">
  <div><span>Tickets nach Assistent-Nutzung · 30 Tage</span><strong><?= (int)($afterAssistant['30d']['events'] ?? 0) ?></strong></div>
  <div><span>Tickets nach Assistent-Nutzung · Gesamt</span><strong><?= (int)($afterAssistant['all']['events'] ?? 0) ?></strong></div>
  <p>Gezählt wird nur, wenn in derselben Browsersitzung zuvor einer der Assistenten-Zugänge genutzt wurde. Es werden keine Personen identifiziert.</p>
</div>
<div class="stats-assistant-grid">
<?php
$statLabels = [
    'assistant_inline_use' => ['Direkt eingebetteter Chat','Tatsächlich in den Inline-iframe geklickt'],
    'assistant_bubble_open' => ['Sprechblase','Chatblase geöffnet'],
    'assistant_external_open' => ['Extern geöffnet','Assistent in neuem Tab/Fenster geöffnet'],
];
foreach ($statLabels as $metric=>$meta):
    $m = $usageSummary[$metric] ?? [];
?>
<article class="stats-channel">
<h3><?= app_escape($meta[0]) ?></h3>
<p><?= app_escape($meta[1]) ?></p>
<div class="stats-periods">
<div><span>Heute</span><strong><?= (int)($m['today']['events'] ?? 0) ?></strong><small><?= (int)($m['today']['sessions'] ?? 0) ?> Sitzungen</small></div>
<div><span>7 Tage</span><strong><?= (int)($m['7d']['events'] ?? 0) ?></strong><small><?= (int)($m['7d']['sessions'] ?? 0) ?> Sitzungen</small></div>
<div><span>30 Tage</span><strong><?= (int)($m['30d']['events'] ?? 0) ?></strong><small><?= (int)($m['30d']['sessions'] ?? 0) ?> Sitzungen</small></div>
<div><span>Gesamt</span><strong><?= (int)($m['all']['events'] ?? 0) ?></strong><small><?= (int)($m['all']['sessions'] ?? 0) ?> Sitzungen</small></div>
</div>
</article>
<?php endforeach; ?>
</div>

<h2>Verlauf der letzten 30 Tage</h2>
<div class="stats-table-wrap">
<table class="stats-table">
<thead><tr><th>Tag</th><th>Tickets</th><th>Inline-Chat</th><th>Sprechblase</th><th>Extern</th><th>Ticket nach KI</th></tr></thead>
<tbody>
<?php foreach (array_reverse($usageDaily) as $day): ?>
<tr>
<td><?= app_escape((new DateTimeImmutable((string)$day['date']))->format('d.m.Y')) ?></td>
<td><?= (int)$day['ticket_created'] ?></td>
<td><?= (int)$day['assistant_inline_use'] ?></td>
<td><?= (int)$day['assistant_bubble_open'] ?></td>
<td><?= (int)$day['assistant_external_open'] ?></td>
<td><?= (int)$day['ticket_after_assistant'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>

<?php elseif ($section === 'faq' && $faqReady): ?>
<section class="panel faq-admin-panel">
<span class="label">Moderation</span>
<h1>FAQ & Wissensaufbau</h1>
<p>Aus erledigten Support-Tickets entstehen automatisch FAQ-Entwürfe. Erst nach kurzer Prüfung werden Frage und Antwort für das Kollegium veröffentlicht. Zusätzlich können Admins bei Bedarf selbst eine FAQ-Frage anlegen.</p>

<?php if (($user['role'] ?? '') === 'system_admin'): ?>
<form class="faq-auto-setting" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_settings">
<label class="check-row"><input type="checkbox" name="faq_auto_from_done" value="1"<?= $faqAutoFromDone ? ' checked' : '' ?>> Bei erstmaligem Status „Erledigt“ automatisch einen FAQ-Entwurf erzeugen</label>
<p class="muted">Der Entwurf wird nicht veröffentlicht. Er landet nur in der Moderationsliste.</p>
<button type="submit" class="secondary-button">Automatik speichern</button>
</form>
<?php endif; ?>

<details class="faq-create-box">
<summary>Neue FAQ-Frage direkt formulieren</summary>
<form class="admin-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_create">
<label for="new-faq-question">Problemfrage</label>
<textarea id="new-faq-question" name="faq_question" maxlength="400" rows="3" required placeholder="z. B. Wie verbinde ich mein Dienst-iPad wieder mit dem WLAN?"></textarea>
<label for="new-faq-answer">Lösungsentwurf (optional)</label>
<textarea id="new-faq-answer" name="faq_answer" maxlength="8000" rows="6" placeholder="Kann auch erst in der Moderation ergänzt werden."></textarea>
<label for="new-faq-category">Kategorie (optional)</label>
<select id="new-faq-category" name="faq_category_id"><option value="">Keine feste Kategorie</option><?php foreach ($categories as $category): ?><option value="<?= app_escape((string)$category['id']) ?>"><?= app_escape((string)$category['name']) ?></option><?php endforeach; ?></select>
<button type="submit">Als Entwurf anlegen</button>
</form>
</details>

<h2>Zu moderieren<?php if ($faqPending !== []): ?> · <?= count($faqPending) ?><?php endif; ?></h2>
<?php if ($faqPending === []): ?>
<p class="notice">Aktuell warten keine FAQ-Vorschläge auf Moderation.</p>
<?php else: ?>
<div class="faq-moderation-list">
<?php foreach ($faqPending as $proposal): ?>
<article class="faq-moderation-card" id="faq-proposal-<?= app_escape((string)$proposal['id']) ?>">
<div class="faq-moderation-meta">
<span class="label"><?= $proposal['source_type']==='ticket' ? 'Aus Ticket' : 'Admin-Vorschlag' ?></span>
<?php if ($proposal['source_ticket_id'] !== null): ?><a href="/admin/?ticket=<?= rawurlencode((string)$proposal['source_ticket_id']) ?>"><?= app_escape(app_ticket_number((string)$proposal['source_ticket_id'])) ?> öffnen</a><?php endif; ?>
</div>
<form class="admin-form faq-review-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="proposal_id" value="<?= app_escape((string)$proposal['id']) ?>">
<label for="faq-question-<?= app_escape((string)$proposal['id']) ?>">Öffentliche Problemfrage</label>
<textarea id="faq-question-<?= app_escape((string)$proposal['id']) ?>" name="faq_question" maxlength="400" rows="3" required><?= app_escape((string)$proposal['question']) ?></textarea>
<label for="faq-answer-<?= app_escape((string)$proposal['id']) ?>">Öffentliche Antwort</label>
<textarea id="faq-answer-<?= app_escape((string)$proposal['id']) ?>" name="faq_answer" maxlength="8000" rows="7" required><?= app_escape((string)($proposal['answer_draft'] ?? '')) ?></textarea>
<?php if ($proposal['source_type']==='ticket' && !empty($proposal['answer_draft'])): ?><p class="warning faq-review-warning"><strong>Prüfen:</strong> Dieser Antwortentwurf kann aus einer internen Ticketnotiz stammen. Entferne Namen, interne Angaben und alles, was nicht öffentlich ins FAQ gehört.</p><?php endif; ?>
<label for="faq-category-<?= app_escape((string)$proposal['id']) ?>">Kategorie</label>
<select id="faq-category-<?= app_escape((string)$proposal['id']) ?>" name="faq_category_id"><option value="">Keine feste Kategorie</option><?php foreach ($categories as $category): ?><option value="<?= app_escape((string)$category['id']) ?>"<?= (string)($proposal['category_id'] ?? '')===(string)$category['id'] ? ' selected' : '' ?>><?= app_escape((string)$category['name']) ?></option><?php endforeach; ?></select>
<div class="faq-review-actions">
<button type="submit" name="action" value="faq_publish">Prüfen & veröffentlichen</button>
<button type="submit" name="action" value="faq_reject" class="secondary-button" formnovalidate>Verwerfen</button>
</div>
</form>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Veröffentlichte FAQ</h2>
<?php if ($faqEntries === []): ?><p class="notice">Noch keine FAQ veröffentlicht.</p><?php else: ?>
<div class="faq-admin-entries">
<?php foreach ($faqEntries as $entry): ?>
<article class="faq-entry-admin">
<div><span class="status <?= $entry['status']==='published' ? 'status-done' : 'status-archived' ?>"><?= $entry['status']==='published' ? 'Veröffentlicht' : 'Inaktiv' ?></span><?php if (!empty($entry['category_name'])): ?> <span class="label"><?= app_escape((string)$entry['category_name']) ?></span><?php endif; ?></div>
<h3><?= app_escape((string)$entry['question']) ?></h3>
<p class="preserve"><?= nl2br(app_escape((string)$entry['answer'])) ?></p>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_entry_status">
<input type="hidden" name="entry_id" value="<?= app_escape((string)$entry['id']) ?>">
<input type="hidden" name="entry_status" value="<?= $entry['status']==='published' ? 'inactive' : 'published' ?>">
<button type="submit" class="secondary-button"><?= $entry['status']==='published' ? 'Ausblenden' : 'Wieder veröffentlichen' ?></button>
</form>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<?php elseif ($section === 'system' && ($user['role'] ?? '') === 'system_admin'): ?>
<section class="panel">
<span class="label">Optional</span>
<h1>KI-Assistent</h1>
<p>Hier kann eine Schule einen eigenen AIS.chat- oder anderen KI-Assistenten hinterlegen. Ohne Aktivierung erscheint im Ticketsystem kein Assistenten-Link.</p>
<form class="admin-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="save_assistant">
<label class="check-row"><input type="checkbox" name="assistant_enabled" value="1"<?= ($assistant['enabled'] ?? false) ? ' checked' : '' ?>> KI-Assistent aktivieren</label>
<label for="assistant_label">Bezeichnung</label>
<input id="assistant_label" name="assistant_label" maxlength="80" required value="<?= app_escape((string)($assistant['label'] ?? 'KI-Assistent')) ?>" placeholder="z. B. gsKI">
<label for="assistant_url">URL des Assistenten</label>
<input id="assistant_url" name="assistant_url" type="url" maxlength="2048" value="<?= app_escape((string)($assistant['url'] ?? '')) ?>" placeholder="https://…">

<div class="experimental-option">
  <label class="check-row"><input type="checkbox" name="assistant_widget_enabled" value="1"<?= ($assistant['widget_enabled'] ?? false) ? ' checked' : '' ?>> Schwebende Sprechblase aktivieren <span class="experimental-badge">Experimentell</span></label>
  <p>Die Sprechblase öffnet den Assistenten direkt innerhalb der Supportseite. <strong>Getestet wurde die Einbettung bisher mit AIS.chat-Dialogpartnern.</strong> Andere HTTPS-Chats und Assistenten können ebenfalls verwendet werden, sofern der jeweilige Anbieter die Einbettung in einem iframe erlaubt.</p>
</div>

<p class="muted">Die Funktion ist schulindividuell. Der Raspberry-Pi-Installer bringt keine feste AIS.chat-Instanz mit.</p>
<button type="submit">Assistenten-Einstellungen speichern</button>
</form>
</section>

<?php elseif ($detail !== null): ?>
<p><a href="<?= app_escape(admin_url($filters)) ?>">← Zur Ticketübersicht</a></p>
<article class="panel">
<div class="ticket-heading">
<div><span class="label<?= $detail['type']==='defect' ? ' defect' : '' ?>"><?= $detail['type']==='defect' ? 'Defekt' : 'Support' ?></span><h1><?= app_escape(app_ticket_number((string)$detail['id'])) ?></h1></div>
<div class="ticket-heading-badges"><span class="status status-<?= app_escape((string)$detail['status']) ?>"><?= app_escape(app_status_label((string)$detail['status'])) ?></span><?php if ($detail['archived_at'] !== null): ?><span class="status status-archived">Archiviert</span><?php endif; ?></div>
</div>

<dl class="admin-detail-grid">
<div><dt>Priorität</dt><dd class="priority priority-<?= app_escape((string)$detail['priority']) ?>"><?= app_escape(app_priority_label((string)$detail['priority'])) ?></dd></div>
<div><dt>Kategorie</dt><dd><?= app_escape((string)$detail['category_name']) ?></dd></div>
<div><dt>Name und Kürzel</dt><dd><?= app_escape((string)$detail['reporter_name']) ?> (<?= app_escape((string)$detail['reporter_abbreviation']) ?>)</dd></div>
<div><dt>Raum / Ort</dt><dd><?= app_escape((string)$detail['location']) ?></dd></div>
<div><dt>Gerät / System</dt><dd><?= app_escape((string)$detail['device']) ?></dd></div>
<div><dt>Inventar-/Netzwerknummer</dt><dd><?= app_escape((string)($detail['inventory_number'] ?: '—')) ?></dd></div>
<div><dt>Seriennummer</dt><dd><?= app_escape((string)($detail['serial_number'] ?: '—')) ?></dd></div>
<div><dt>Eingang</dt><dd><?= app_escape(app_local_time((string)$detail['created_at'])) ?></dd></div>
<div><dt>Status geändert</dt><dd><?= app_escape(app_local_time((string)$detail['status_changed_at'])) ?></dd></div>
<div><dt>Zuletzt bearbeitet</dt><dd><?= app_escape(app_local_time((string)$detail['updated_at'])) ?></dd></div>
</dl>

<?php if ($detail['type']==='defect'): ?><section><h2>Defekt</h2><p class="preserve"><?= nl2br(app_escape((string)$detail['defect_subject'])) ?></p></section><?php endif; ?>
<section><h2>Problembeschreibung</h2><p class="preserve"><?= nl2br(app_escape((string)$detail['description'])) ?></p></section>
<?php if (!empty($detail['occurrence_details'])): ?><section><h2>Zusatzangaben</h2><p class="preserve"><?= nl2br(app_escape((string)$detail['occurrence_details'])) ?></p></section><?php endif; ?>

<section><h2>Interne Notizen</h2>
<?php if ($detail['comments'] === []): ?><p>Noch keine internen Notizen.</p><?php else: ?>
<ol class="comments"><?php foreach ($detail['comments'] as $comment): ?><li><p class="preserve"><?= nl2br(app_escape((string)$comment['body'])) ?></p><small><?= app_escape((string)($comment['author_name'] ?? 'Gelöschtes Konto')) ?> · <?= app_escape(app_local_time((string)$comment['created_at'])) ?></small></li><?php endforeach; ?></ol>
<?php endif; ?></section>

<?php if ($detail['archived_at'] === null): ?>
<section class="edit-box"><h2>Ticket bearbeiten</h2>
<form class="admin-form" method="post" action="<?= app_escape(admin_url($filters, ['ticket'=>(string)$detail['id']])) ?>">
<input type="hidden" name="action" value="update_ticket">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<?= admin_filter_hidden($filters) ?>
<label for="status">Status</label>
<select id="status" name="status"><?php foreach (['new','in_progress','awaiting_reply','done'] as $status): ?><option value="<?= $status ?>"<?= $detail['status']===$status?' selected':'' ?>><?= app_escape(app_status_label($status)) ?></option><?php endforeach; ?></select>
<label for="priority">Priorität</label>
<?php if ($detail['type']==='defect'): ?><input type="hidden" name="priority" value="high"><p class="fixed-value">Hoch <small>Defektmeldungen bleiben immer hoch priorisiert.</small></p>
<?php else: ?><select id="priority" name="priority"><?php foreach (['low','normal','high'] as $priority): ?><option value="<?= $priority ?>"<?= $detail['priority']===$priority?' selected':'' ?>><?= app_escape(app_priority_label($priority)) ?></option><?php endforeach; ?></select><?php endif; ?>
<label for="comment">Interne Notiz hinzufügen (optional)</label>
<textarea id="comment" name="comment" maxlength="5000" rows="5"></textarea>
<button type="submit">Änderungen speichern</button>
</form></section>
<?php endif; ?>

<?php if ($faqReady): ?>
<section class="edit-box"><h2>FAQ aus diesem Ticket</h2>
<?php if ($detail['status'] === 'done'): ?>
<p>Erzeuge aus Problem und letzter interner Notiz einen moderierbaren FAQ-Entwurf. Es wird nichts automatisch veröffentlicht.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_from_ticket">
<input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<button type="submit">FAQ-Entwurf erzeugen</button>
</form>
<?php else: ?>
<p class="notice">Die automatische FAQ-Erstellung wird verfügbar, sobald das Ticket erledigt ist.</p>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="edit-box"><h2>Archiv</h2>
<?php if ($detail['archived_at'] !== null): ?>
<form method="post" action="<?= app_escape(admin_url($filters, ['ticket'=>(string)$detail['id']])) ?>">
<input type="hidden" name="action" value="restore_ticket"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<?= admin_filter_hidden($filters) ?>
<button type="submit" class="secondary-button">Aus Archiv wiederherstellen</button></form>
<?php elseif ($detail['status']==='done'): ?>
<form method="post" action="<?= app_escape(admin_url($filters, ['ticket'=>(string)$detail['id']])) ?>">
<input type="hidden" name="action" value="archive_ticket"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<?= admin_filter_hidden($filters) ?>
<button type="submit" class="secondary-button">Ticket archivieren</button></form>
<?php else: ?><p class="notice">Ein Ticket kann archiviert werden, sobald es „Erledigt“ ist.</p><?php endif; ?>
</section>
</article>

<?php else: ?>
<section aria-labelledby="overview-title">
<div class="overview-heading">
<div><h1 id="overview-title"><?= $filters['view']==='archive' ? 'Archiv' : 'Tickets' ?></h1><p><?= $filters['view']==='archive' ? 'Archivierte Tickets können gefiltert, geöffnet und wiederhergestellt werden.' : 'Filtern, sortieren und Tickets direkt über Schnellaktionen bearbeiten.' ?></p></div>
<nav class="view-tabs"><a class="<?= $filters['view']==='active'?'active':'' ?>" href="<?= app_escape(admin_url(array_merge($filters,['view'=>'active']))) ?>">Aktive Tickets</a><a class="<?= $filters['view']==='archive'?'active':'' ?>" href="<?= app_escape(admin_url(array_merge($filters,['view'=>'archive']))) ?>">Archiv</a></nav>
</div>

<form class="filter-form" method="get">
<?php if ($filters['view']==='archive'): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
<div class="filter-search"><label for="filter-q">Suche</label><input id="filter-q" name="q" type="search" maxlength="200" value="<?= app_escape($filters['q']) ?>" placeholder="Ticketnummer, Name, Gerät, Stichwort …"></div>
<div><label for="filter-status">Status</label><select id="filter-status" name="status"><option value="">Alle</option><?php foreach (['new','in_progress','awaiting_reply','done'] as $status): ?><option value="<?= $status ?>"<?= $filters['status']===$status?' selected':'' ?>><?= app_escape(app_status_label($status)) ?></option><?php endforeach; ?></select></div>
<div><label for="filter-priority">Priorität</label><select id="filter-priority" name="priority"><option value="">Alle</option><?php foreach (['low','normal','high'] as $priority): ?><option value="<?= $priority ?>"<?= $filters['priority']===$priority?' selected':'' ?>><?= app_escape(app_priority_label($priority)) ?></option><?php endforeach; ?></select></div>
<div><label for="filter-type">Art</label><select id="filter-type" name="type"><option value="">Alle</option><option value="support"<?= $filters['type']==='support'?' selected':'' ?>>Support</option><option value="defect"<?= $filters['type']==='defect'?' selected':'' ?>>Defekt</option></select></div>
<div><label for="filter-category">Kategorie</label><select id="filter-category" name="category"><option value="">Alle</option><?php foreach ($categories as $category): ?><option value="<?= app_escape((string)$category['id']) ?>"<?= $filters['category']===(string)$category['id']?' selected':'' ?>><?= app_escape((string)$category['name']) ?></option><?php endforeach; ?></select></div>
<div><label for="filter-sort">Sortierung</label><select id="filter-sort" name="sort"><option value="priority"<?= $filters['sort']==='priority'?' selected':'' ?>>Priorität</option><option value="newest"<?= $filters['sort']==='newest'?' selected':'' ?>>Neueste zuerst</option><option value="oldest"<?= $filters['sort']==='oldest'?' selected':'' ?>>Älteste zuerst</option><option value="updated"<?= $filters['sort']==='updated'?' selected':'' ?>>Zuletzt geändert</option></select></div>
<div class="filter-actions"><button type="submit">Anwenden</button><a href="<?= app_escape(admin_url(['view'=>$filters['view'],'q'=>'','status'=>'','priority'=>'','type'=>'','category'=>'','sort'=>'priority'])) ?>">Zurücksetzen</a></div>
</form>

<?php if ($tickets === []): ?><div class="panel"><p>Für diese Auswahl wurden keine Tickets gefunden.</p></div><?php else: ?>
<div class="ticket-table-wrap">
<table>
<thead><tr><th>Ticket</th><th>Art</th><th>Status</th><th>Priorität</th><th>Kategorie</th><th>Name und Kürzel</th><th>Raum/Ort</th><th>Gerät/System</th><th>Eingang</th><th>Status geändert</th><th>Schnellaktionen</th></tr></thead>
<tbody>
<?php foreach ($tickets as $row): ?>
<tr class="<?= $row['type']==='defect' ? 'row-defect' : '' ?>">
<td data-label="Ticket" class="ticket-primary"><a href="<?= app_escape(admin_url($filters, ['ticket'=>(string)$row['id']])) ?>"><?= app_escape(app_ticket_number((string)$row['id'])) ?></a></td>
<td data-label="Art"><?= $row['type']==='defect' ? 'Defekt' : 'Support' ?></td>
<td data-label="Status"><span class="status status-<?= app_escape((string)$row['status']) ?>"><?= app_escape(app_status_label((string)$row['status'])) ?></span></td>
<td data-label="Priorität"><span class="priority priority-<?= app_escape((string)$row['priority']) ?>"><?= app_escape(app_priority_label((string)$row['priority'])) ?></span></td>
<td data-label="Kategorie"><?= app_escape((string)$row['category_name']) ?></td>
<td data-label="Name und Kürzel"><?= app_escape((string)$row['reporter_name']) ?> (<?= app_escape((string)$row['reporter_abbreviation']) ?>)</td>
<td data-label="Raum/Ort"><?= app_escape((string)$row['location']) ?></td>
<td data-label="Gerät/System"><?= app_escape((string)$row['device']) ?></td>
<td data-label="Eingang"><?= app_escape(app_local_time((string)$row['created_at'])) ?></td>
<td data-label="Status geändert"><?= app_escape(app_local_time((string)$row['status_changed_at'])) ?></td>
<td data-label="Schnellaktionen" class="quick-actions-cell">
<?php if ($filters['view']==='active'): ?>
<div class="quick-actions">
<?php foreach ([['in_progress','In Bearbeitung'],['awaiting_reply','Rückfrage'],['done','Erledigt']] as [$quickValue,$quickLabel]): ?>
<form method="post">
<input type="hidden" name="action" value="quick_status"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$row['id']) ?>"><input type="hidden" name="status" value="<?= $quickValue ?>">
<?= admin_filter_hidden($filters) ?>
<button type="submit" class="quick-action quick-<?= $quickValue ?><?= $row['status']===$quickValue?' is-current':'' ?>"<?= $row['status']===$quickValue?' disabled':'' ?>><?= admin_icon($quickValue) ?><span><?= $quickLabel ?></span></button>
</form>
<?php endforeach; ?>
<form method="post">
<input type="hidden" name="action" value="quick_archive"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$row['id']) ?>">
<?= admin_filter_hidden($filters) ?>
<button type="submit" class="quick-action quick-archive"<?= $row['status']!=='done'?' disabled':'' ?> title="<?= $row['status']==='done' ? 'Ticket archivieren' : 'Erst nach Erledigung verfügbar' ?>"><?= admin_icon('archive') ?><span>Archivieren</span></button>
</form>
</div>
<?php else: ?>—<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
</main>
<footer class="admin-footer">Schul-IT Ticketsystem · lokale Raspberry-Pi-Instanz</footer>
</body>
</html>
