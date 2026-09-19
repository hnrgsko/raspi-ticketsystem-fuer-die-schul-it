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
if ($section !== 'system') $section = '';

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
            header('Location: ' . admin_url(admin_filters($_POST)), true, 303);
            exit;
        }

        if ($action === 'quick_archive') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            app_admin_archive($db, $ticketId, true);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ' wurde archiviert.';
            header('Location: ' . admin_url(admin_filters($_POST)), true, 303);
            exit;
        }

        if ($action === 'update_ticket') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            app_admin_update_ticket($db, $ticketId, (string)$user['id'], $_POST);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ' wurde aktualisiert.';
            $next = admin_filters($_POST);
            header('Location: ' . admin_url($next, ['ticket'=>$ticketId]), true, 303);
            exit;
        }

        if ($action === 'archive_ticket' || $action === 'restore_ticket') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            $archive = $action === 'archive_ticket';
            app_admin_archive($db, $ticketId, $archive);
            $_SESSION['admin_notice'] = app_ticket_number($ticketId) . ($archive ? ' wurde archiviert.' : ' wurde wiederhergestellt.');
            $next = admin_filters($_POST);
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
$assistant = ($db instanceof PDO) ? app_assistant_settings($db) : ['enabled'=>false,'label'=>'KI-Assistent','url'=>''];
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
<?php if (($user['role'] ?? '') === 'system_admin'): ?><a class="<?= $section==='system' ? 'active' : '' ?>" href="/admin/?section=system">System</a><?php endif; ?>
<a href="/">Kollegiumsseite</a>
<?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?><a href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape((string)$assistant['label']) ?></a><?php endif; ?>
</nav>

<?php if ($section === 'system' && ($user['role'] ?? '') === 'system_admin'): ?>
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
<?php foreach ($filters as $key=>$value): ?><input type="hidden" name="<?= app_escape($key) ?>" value="<?= app_escape((string)$value) ?>"><?php endforeach; ?>
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

<section class="edit-box"><h2>Archiv</h2>
<?php if ($detail['archived_at'] !== null): ?>
<form method="post" action="<?= app_escape(admin_url($filters, ['ticket'=>(string)$detail['id']])) ?>">
<input type="hidden" name="action" value="restore_ticket"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<?php foreach ($filters as $key=>$value): ?><input type="hidden" name="<?= app_escape($key) ?>" value="<?= app_escape((string)$value) ?>"><?php endforeach; ?>
<button type="submit" class="secondary-button">Aus Archiv wiederherstellen</button></form>
<?php elseif ($detail['status']==='done'): ?>
<form method="post" action="<?= app_escape(admin_url($filters, ['ticket'=>(string)$detail['id']])) ?>">
<input type="hidden" name="action" value="archive_ticket"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<?php foreach ($filters as $key=>$value): ?><input type="hidden" name="<?= app_escape($key) ?>" value="<?= app_escape((string)$value) ?>"><?php endforeach; ?>
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
<?php foreach ($filters as $key=>$value): ?><input type="hidden" name="<?= app_escape($key) ?>" value="<?= app_escape((string)$value) ?>"><?php endforeach; ?>
<button type="submit" class="quick-action quick-<?= $quickValue ?><?= $row['status']===$quickValue?' is-current':'' ?>"<?= $row['status']===$quickValue?' disabled':'' ?>><?= admin_icon($quickValue) ?><span><?= $quickLabel ?></span></button>
</form>
<?php endforeach; ?>
<form method="post">
<input type="hidden" name="action" value="quick_archive"><input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= app_escape((string)$row['id']) ?>">
<?php foreach ($filters as $key=>$value): ?><input type="hidden" name="<?= app_escape($key) ?>" value="<?= app_escape((string)$value) ?>"><?php endforeach; ?>
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
