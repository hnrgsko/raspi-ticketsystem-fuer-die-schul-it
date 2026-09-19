<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

app_start_session(SCHULIT_ADMIN_SESSIONS, 'schulit_admin');

$error = '';
$message = '';
$db = null;
$schoolName = 'Schul-IT Ticketsystem';
$user = null;

try {
    $db = app_database();
    $schoolName = app_setting($db, 'school_name', $schoolName);
} catch (Throwable $caught) {
    $error = $caught->getMessage();
}

if ($db instanceof PDO && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            if (!app_session_rate($_SESSION, 'admin_login', 12, 900)) {
                throw new RuntimeException('Zu viele Anmeldeversuche. Bitte später erneut versuchen.');
            }
            $user = app_admin_login($db, $_POST['username'] ?? null, $_POST['password'] ?? null);
            if ($user === null) throw new RuntimeException('Benutzername oder Passwort ist nicht korrekt.');
            header('Location: /admin/', true, 303);
            exit;
        }

        $user = app_admin_user($db);
        if ($user === null) throw new RuntimeException('Bitte erneut anmelden.');
        if (!app_csrf_valid($_SESSION, $_POST['csrf'] ?? null, 'admin_csrf')) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: /admin/', true, 303);
            exit;
        }

        if ($action === 'update_ticket') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            app_admin_update_ticket($db, $ticketId, (string)$user['id'], $_POST);
            $message = 'Ticket wurde aktualisiert.';
            $returnView = (string)($_POST['return_view'] ?? '');
            $target = '/admin/?ticket=' . rawurlencode($ticketId) . '&saved=1';
            if ($returnView === 'archive') {
                $target .= '&view=archive';
            }
            header('Location: ' . $target, true, 303);
            exit;
        }

        if ($action === 'archive_ticket' || $action === 'restore_ticket') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            $archive = $action === 'archive_ticket';
            app_admin_archive($db, $ticketId, $archive);
            $target = '/admin/?ticket=' . rawurlencode($ticketId) . '&saved=1';
            if ($archive) {
                $target .= '&view=archive';
            }
            header('Location: ' . $target, true, 303);
            exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
    }
}

if ($db instanceof PDO && $user === null) {
    $user = app_admin_user($db);
}
if (($_GET['saved'] ?? '') === '1') $message = 'Änderung gespeichert.';

$csrf = $user !== null ? app_csrf($_SESSION, 'admin_csrf') : '';
$ticketId = is_string($_GET['ticket'] ?? null) ? $_GET['ticket'] : '';
$detail = ($user !== null && $db instanceof PDO && $ticketId !== '') ? app_admin_ticket($db, $ticketId) : null;
$filters = [
    'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
    'priority' => is_string($_GET['priority'] ?? null) ? $_GET['priority'] : '',
    'view' => is_string($_GET['view'] ?? null) ? $_GET['view'] : '',
];
$currentView = $filters['view'] === 'archive' ? 'archive' : '';
$tickets = ($user !== null && $db instanceof PDO && $detail === null) ? app_admin_tickets($db, $filters) : [];
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ticket-Admin – <?= app_escape($schoolName) ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="shell">
<header>
  <div><div class="brand">Ticket-Admin</div><div class="school"><?= app_escape($schoolName) ?></div></div>
  <?php if ($user !== null): ?>
  <nav><a href="/admin/">Aktive Tickets</a><a href="/admin/?view=archive">Archiv</a><a href="/">Kollegiumsseite</a>
  <?php if (($user['role'] ?? '') === 'system_admin'): ?><span class="badge">System-Admin</span><?php else: ?><span class="badge">Ticket-Admin</span><?php endif; ?></nav>
  <?php endif; ?>
</header>

<?php if ($error !== ''): ?><div class="card error"><strong>Fehler:</strong> <?= app_escape($error) ?></div><?php endif; ?>
<?php if ($message !== ''): ?><div class="card success"><?= app_escape($message) ?></div><?php endif; ?>

<?php if ($user !== null): ?>
<div class="admin-tabs">
  <a class="admin-tab<?= $currentView === '' ? ' active' : '' ?>" href="/admin/">Aktive Tickets</a>
  <a class="admin-tab<?= $currentView === 'archive' ? ' active' : '' ?>" href="/admin/?view=archive">Archiv</a>
</div>
<?php endif; ?>

<?php if (!$db instanceof PDO): ?>
<section class="card"><h1>System nicht bereit</h1><p class="muted">Die lokale Anwendungsdatenbank ist noch nicht verfügbar.</p></section>

<?php elseif ($user === null): ?>
<section class="card login-card">
<h1>Admin-Anmeldung</h1>
<p class="muted">Melde dich mit dem im Einrichtungsassistenten angelegten Administratorkonto an.</p>
<form method="post">
<input type="hidden" name="action" value="login">
<label for="username">Benutzername</label>
<input id="username" name="username" maxlength="100" required autofocus autocomplete="username">
<label for="password">Passwort</label>
<input id="password" type="password" name="password" required autocomplete="current-password">
<div class="actions"><button type="submit">Anmelden</button></div>
</form>
</section>

<?php elseif ($detail !== null): ?>
<section class="card">
<div class="actions"><a class="button secondary" href="<?= $detail['archived_at'] !== null ? '/admin/?view=archive' : '/admin/' ?>">← <?= $detail['archived_at'] !== null ? 'Zum Archiv' : 'Zur Ticketliste' ?></a></div>
<h1><?= app_escape(app_ticket_number((string)$detail['id'])) ?></h1>
<div class="detail-grid">
<div><div class="key">Meldende Person</div><div class="value"><?= app_escape((string)$detail['reporter_name']) ?> (<?= app_escape((string)$detail['reporter_abbreviation']) ?>)</div></div>
<div><div class="key">Kategorie</div><div class="value"><?= app_escape((string)$detail['category_name']) ?></div></div>
<div><div class="key">Raum / Ort</div><div class="value"><?= app_escape((string)$detail['location']) ?></div></div>
<div><div class="key">Gerät / System</div><div class="value"><?= app_escape((string)$detail['device']) ?></div></div>
<div><div class="key">Inventar-/Netzwerknummer</div><div class="value"><?= app_escape((string)($detail['inventory_number'] ?? '–')) ?></div></div>
<div><div class="key">Seriennummer</div><div class="value"><?= app_escape((string)($detail['serial_number'] ?? '–')) ?></div></div>
<div><div class="key">Erstellt</div><div class="value"><?= app_escape(app_local_time((string)$detail['created_at'])) ?></div></div>
<div><div class="key">Ticketart</div><div class="value"><?= $detail['type'] === 'defect' ? 'Defekt' : 'Support' ?></div></div>
</div>

<?php if ($detail['type'] === 'defect'): ?>
<div class="notice"><strong>Defekt:</strong> <?= app_escape((string)$detail['defect_subject']) ?></div>
<?php endif; ?>

<h3>Problembeschreibung</h3>
<div class="notice"><?= nl2br(app_escape((string)$detail['description'])) ?></div>
<?php if (!empty($detail['occurrence_details'])): ?><p><strong>Seit wann / wann:</strong><br><?= nl2br(app_escape((string)$detail['occurrence_details'])) ?></p><?php endif; ?>

<h2>Bearbeitung</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="update_ticket">
<input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<input type="hidden" name="return_view" value="<?= $detail['archived_at'] !== null ? 'archive' : '' ?>">
<div class="grid">
<div><label for="status">Status</label>
<select id="status" name="status">
<?php foreach (['new'=>'Neu','in_progress'=>'In Bearbeitung','awaiting_reply'=>'Rückfrage','done'=>'Erledigt'] as $value=>$label): ?>
<option value="<?= $value ?>"<?= $detail['status']===$value?' selected':'' ?>><?= $label ?></option>
<?php endforeach; ?>
</select></div>
<div><label for="priority">Priorität</label>
<select id="priority" name="priority"<?= $detail['type']==='defect'?' disabled':'' ?>>
<?php foreach (['low'=>'Niedrig','normal'=>'Normal','high'=>'Hoch'] as $value=>$label): ?>
<option value="<?= $value ?>"<?= $detail['priority']===$value?' selected':'' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
<?php if ($detail['type']==='defect'): ?><input type="hidden" name="priority" value="high"><?php endif; ?>
</div>
</div>
<label for="comment">Interne Notiz</label>
<textarea id="comment" name="comment" maxlength="5000" rows="4" placeholder="Optional"></textarea>
<div class="actions"><button type="submit">Änderungen speichern</button></div>
</form>

<?php if ($detail['comments'] !== []): ?>
<h2>Interne Notizen</h2>
<?php foreach ($detail['comments'] as $comment): ?>
<div class="ticket"><strong><?= app_escape((string)($comment['author_name'] ?? 'Ehemaliger Admin')) ?></strong> · <?= app_escape(app_local_time((string)$comment['created_at'])) ?><br><?= nl2br(app_escape((string)$comment['body'])) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<?php if ($detail['archived_at'] === null): ?>
<input type="hidden" name="action" value="archive_ticket">
<?php if ($detail['status'] === 'done'): ?><div class="actions"><button class="secondary" type="submit">Ticket archivieren</button></div><?php endif; ?>
<?php else: ?>
<input type="hidden" name="action" value="restore_ticket">
<div class="actions"><button class="secondary" type="submit">Aus Archiv zurückholen</button></div>
<?php endif; ?>
</form>
</section>

<?php else: ?>
<section class="card">
<h1><?= $currentView === 'archive' ? 'Archiv' : 'Aktive Tickets' ?></h1>
<p class="muted"><?= $currentView === 'archive' ? 'Hier findest du archivierte, erledigte Tickets.' : 'Hier findest du alle aktuell nicht archivierten Tickets.' ?></p>
<form method="get" class="grid">
<input type="hidden" name="view" value="<?= app_escape($currentView) ?>">
<div><label for="status">Status</label><select id="status" name="status"><option value="">Alle</option>
<?php foreach (['new'=>'Neu','in_progress'=>'In Bearbeitung','awaiting_reply'=>'Rückfrage','done'=>'Erledigt'] as $value=>$label): ?><option value="<?= $value ?>"<?= $filters['status']===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?>
</select></div>
<div><label for="priority">Priorität</label><select id="priority" name="priority"><option value="">Alle</option>
<?php foreach (['low'=>'Niedrig','normal'=>'Normal','high'=>'Hoch'] as $value=>$label): ?><option value="<?= $value ?>"<?= $filters['priority']===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?>
</select></div>
<div class="actions filter-action"><button type="submit">Filtern</button></div>
</form>

<?php if ($tickets === []): ?><div class="notice">Keine Tickets in dieser Ansicht.</div><?php endif; ?>
<?php foreach ($tickets as $ticket): ?>
<a class="ticket-link" href="/admin/?ticket=<?= rawurlencode((string)$ticket['id']) ?><?= $currentView === 'archive' ? '&view=archive' : '' ?>">
<div class="ticket ticket-row">
<strong><?= app_escape(app_ticket_number((string)$ticket['id'])) ?></strong>
<span><?= app_escape((string)$ticket['reporter_name']) ?> (<?= app_escape((string)$ticket['reporter_abbreviation']) ?>)</span>
<span><?= app_escape((string)$ticket['category_name']) ?><br><span class="muted"><?= app_escape((string)$ticket['location']) ?></span></span>
<span class="badge<?= $ticket['priority']==='high'?' high':'' ?>"><?= app_escape(app_priority_label((string)$ticket['priority'])) ?></span>
<span class="badge<?= $ticket['status']==='done'?' done':'' ?>"><?= app_escape(app_status_label((string)$ticket['status'])) ?></span>
</div>
</a>
<?php endforeach; ?>
</section>

<form method="post" class="card">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="logout">
<button class="secondary" type="submit">Abmelden</button>
</form>
<?php endif; ?>
</main>
</body>
</html>
