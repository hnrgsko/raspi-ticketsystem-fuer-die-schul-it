<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';

app_security_headers();
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
if (!in_array($section, ['system','faq','stats','account'], true)) $section = '';

if ($user !== null && (int)($user['must_change_password'] ?? 0) === 1
    && $_SERVER['REQUEST_METHOD'] === 'GET' && $section !== 'account') {
    header('Location: /admin/?section=account', true, 303);
    exit;
}

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

        if ((int)($user['must_change_password'] ?? 0) === 1
            && $action !== 'change_own_password') {
            throw new RuntimeException('Bitte zuerst das Startpasswort ändern.');
        }

        if ($action === 'change_own_password') {
            app_admin_change_own_password($db, (string)$user['id'], $_POST);
            $_SESSION['admin_notice'] = 'Passwort wurde geändert.';
            header('Location: /admin/?section=account', true, 303);
            exit;
        }

        if ($action === 'create_admin') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen weitere Admin-Konten anlegen.');
            }
            app_admin_create_user($db, $_POST);
            $_SESSION['admin_notice'] = 'Administratorkonto wurde angelegt. Beim ersten Login muss das Startpasswort geändert werden.';
            header('Location: /admin/?section=system#admin-accounts', true, 303);
            exit;
        }

        if ($action === 'update_admin') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen Admin-Konten ändern.');
            }
            app_admin_update_user(
                $db,
                (string)($_POST['admin_id'] ?? ''),
                (string)$user['id'],
                $_POST
            );
            $_SESSION['admin_notice'] = 'Administratorkonto wurde aktualisiert.';
            header('Location: /admin/?section=system#admin-accounts', true, 303);
            exit;
        }

        if ($action === 'reset_admin_password') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen Passwörter zurücksetzen.');
            }
            app_admin_reset_password(
                $db,
                (string)($_POST['admin_id'] ?? ''),
                (string)$user['id'],
                $_POST
            );
            $_SESSION['admin_notice'] = 'Startpasswort wurde gesetzt. Das Konto muss es beim nächsten Login ändern.';
            header('Location: /admin/?section=system#admin-accounts', true, 303);
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

        if ($action === 'reset_ticket_status_code') {
            $ticketId = (string)($_POST['ticket_id'] ?? '');
            $newCode = app_admin_reset_ticket_status_code($db, $ticketId);
            $_SESSION['admin_status_code'] = ['ticket_id'=>$ticketId,'code'=>$newCode];
            $_SESSION['admin_notice'] = 'Neuer Statuscode wurde erzeugt. Der vorherige Code ist ab sofort ungültig.';
            header('Location: /admin/?ticket=' . rawurlencode($ticketId), true, 303);
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

        if ($action === 'configure_tunnel') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen den öffentlichen Zugang ändern.');
            }
            app_system_request('configure_tunnel', [
                'hostname'=>(string)($_POST['tunnel_hostname'] ?? ''),
                'token'=>(string)($_POST['tunnel_token'] ?? ''),
            ], 210);
            $_SESSION['admin_notice'] = 'Cloudflare Tunnel wurde eingerichtet und gestartet.';
            header('Location: /admin/?section=system#public-access', true, 303);
            exit;
        }

        if ($action === 'test_tunnel') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen den öffentlichen Zugang testen.');
            }
            $result = app_system_request('test_tunnel', [], 30);
            if (($result['reachable'] ?? false) === true) {
                $_SESSION['admin_notice'] = 'Öffentlicher Zugang ist erreichbar'
                    . (!empty($result['http_status']) ? ' (HTTP ' . $result['http_status'] . ')' : '') . '.';
            } else {
                $_SESSION['admin_notice'] = 'Der Tunnel-Dienst läuft, aber der öffentliche Hostname ist noch nicht erreichbar. '
                    . 'Bitte DNS/Published Application bei Cloudflare prüfen.';
            }
            header('Location: /admin/?section=system#public-access', true, 303);
            exit;
        }

        if ($action === 'rotate_public_access_token') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen den Kollegiumslink erneuern.');
            }
            if ((string)($_POST['confirm_access_rotation'] ?? '') !== '1') {
                throw new RuntimeException('Zum Erneuern des Kollegiumslinks muss die Bestätigung gesetzt werden.');
            }
            $result = app_system_request('rotate_public_access_token', [], 20);
            $ended = (int)($result['invalidated_sessions'] ?? 0);
            $_SESSION['admin_notice'] = 'Kollegiumslink wurde erneuert. Der alte Link ist ab sofort ungültig'
                . ($ended > 0 ? '; ' . $ended . ' bestehende Sitzung(en) wurden beendet.' : '.');
            header('Location: /admin/?section=system#public-access', true, 303);
            exit;
        }

        if ($action === 'disable_tunnel') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen den öffentlichen Zugang entfernen.');
            }
            if ((string)($_POST['confirm_tunnel_remove'] ?? '') !== '1') {
                throw new RuntimeException('Zum Entfernen des Tunnels muss die Bestätigung gesetzt werden.');
            }
            app_system_request('disable_tunnel', [], 45);
            $_SESSION['admin_notice'] = 'Cloudflare Tunnel wurde getrennt; lokale Ticketseite bleibt erhalten.';
            header('Location: /admin/?section=system#public-access', true, 303);
            exit;
        }

        if ($action === 'check_updates') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen eine Updateprüfung auslösen.');
            }
            $result = app_system_request('check_updates', [], 40);
            if (!empty($result['error'])) {
                $_SESSION['admin_notice'] = 'Updateprüfung abgeschlossen, Releasequelle derzeit nicht verfügbar.';
            } elseif (($result['available'] ?? false) === true) {
                $_SESSION['admin_notice'] = 'Neue Version ' . (string)$result['latest_version'] . ' ist verfügbar.';
            } else {
                $_SESSION['admin_notice'] = 'Keine neuere stabile Version gefunden.';
            }
            header('Location: /admin/?section=system#updates', true, 303);
            exit;
        }

        if ($action === 'start_development_update') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen die Entwicklerversion aktualisieren.');
            }
            $result = app_system_request('start_development_update', [], 20);
            $_SESSION['admin_notice'] = (($result['state'] ?? '') === 'running')
                ? 'Entwicklungsupdate wurde gestartet. Die Installation läuft im Hintergrund; bitte diese Seite nach kurzer Zeit neu laden.'
                : 'Entwicklungsupdate wurde angefordert.';
            header('Location: /admin/?section=system#updates', true, 303);
            exit;
        }

        if ($action === 'create_backup') {
            if (($user['role'] ?? '') !== 'system_admin') {
                throw new RuntimeException('Nur System-Administratoren dürfen manuelle Sicherungen starten.');
            }
            $result = app_system_request('create_backup', [], 240);
            $archive = is_array($result['last_backup'] ?? null)
                ? (string)($result['last_backup']['archive'] ?? '') : '';
            $_SESSION['admin_notice'] = 'Verschlüsseltes Backup wurde erstellt'
                . ($archive !== '' ? ': ' . $archive : '') . '.';
            header('Location: /admin/?section=system#backups', true, 303);
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

        if ($action === 'faq_link_existing') {
            $proposalId = (string)($_POST['proposal_id'] ?? '');
            app_faq_admin_link_existing($db, $proposalId, (string)$user['id']);
            $_SESSION['admin_notice'] = 'Ticket wurde mit der bestehenden FAQ verknüpft. Der öffentliche FAQ-Text blieb unverändert; neue Suchbegriffe wurden intern ergänzt.';
            header('Location: /admin/?section=faq', true, 303);
            exit;
        }

        if ($action === 'faq_merge') {
            $proposalId = (string)($_POST['proposal_id'] ?? '');
            app_faq_admin_merge($db, $proposalId, (string)$user['id'], $_POST);
            $_SESSION['admin_notice'] = 'Bestehende FAQ wurde kontrolliert ergänzt und mit dem Ticket verknüpft.';
            header('Location: /admin/?section=faq', true, 303);
            exit;
        }

        if ($action === 'faq_entry_edit') {
            app_faq_admin_edit_entry(
                $db,
                (string)($_POST['entry_id'] ?? ''),
                (string)$user['id'],
                $_POST
            );
            $_SESSION['admin_notice'] = 'FAQ wurde bearbeitet. Die vorherige Fassung wurde in der Versionshistorie gesichert.';
            header('Location: /admin/?section=faq#faq-entry-' . rawurlencode((string)($_POST['entry_id'] ?? '')), true, 303);
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
$oneTimeStatusCode = is_array($_SESSION['admin_status_code'] ?? null)
    ? $_SESSION['admin_status_code'] : null;
unset($_SESSION['admin_status_code']);

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
$adminUsers = ($user !== null && ($user['role'] ?? '') === 'system_admin' && $section === 'system')
    ? app_admin_user_list($db) : [];
$backupStatus = null;
$backupStatusError = '';
if ($user !== null && ($user['role'] ?? '') === 'system_admin' && $section === 'system') {
    try {
        $backupStatus = app_system_request('backup_status', [], 10);
    } catch (Throwable $backupCaught) {
        $backupStatusError = $backupCaught->getMessage();
    }
}
$updateStatus = null;
if ($user !== null) {
    try {
        $updateStatus = app_system_request('update_status', [], 5);
    } catch (Throwable) {
        $updateStatus = null;
    }
}
$devUpdateStatus = null;
if ($user !== null && ($user['role'] ?? '') === 'system_admin' && $section === 'system') {
    try {
        $devUpdateStatus = app_system_request('development_update_status', [], 5);
    } catch (Throwable) {
        $devUpdateStatus = null;
    }
}
$tunnelStatus = null;
$tunnelStatusError = '';
if ($user !== null && ($user['role'] ?? '') === 'system_admin' && $section === 'system') {
    try {
        $tunnelStatus = app_system_request('tunnel_status', [], 10);
    } catch (Throwable $tunnelCaught) {
        $tunnelStatusError = $tunnelCaught->getMessage();
    }
}
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
<a class="<?= $section==='account' ? 'active' : '' ?>" href="/admin/?section=account">Passwort ändern</a>
<a href="<?= app_escape(app_public_access_path()) ?>">Kollegiumsseite</a>
<?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?><a href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape((string)$assistant['label']) ?></a><?php endif; ?>
</nav>

<?php if (is_array($updateStatus) && ($updateStatus['available'] ?? false) === true): ?>
<aside class="update-banner" role="status">
<div>
<strong>Neue Version <?= app_escape((string)$updateStatus['latest_version']) ?> verfügbar</strong>
<span>Eine neuere stabile Version des Schul-IT Ticketsystems wurde veröffentlicht.</span>
</div>
<?php if (($user['role'] ?? '') === 'system_admin'): ?><a class="button secondary" href="/admin/?section=system#updates">Update ansehen</a><?php else: ?><span class="muted">Installation durch einen System-Admin.</span><?php endif; ?>
</aside>
<?php endif; ?>

<?php if ($section === 'stats'): ?>
<?php
$afterAssistant = $usageSummary['ticket_after_assistant'] ?? [];
$faqPublicOpen = $usageSummary['faq_public_open'] ?? [];
$faqSuggestionOpen = $usageSummary['faq_suggestion_open'] ?? [];
$faqHelpful = $usageSummary['faq_suggestion_helpful'] ?? [];
$ticketAfterFaq = $usageSummary['ticket_after_faq'] ?? [];

$chartDaily = [];
foreach ($usageDaily as $day) {
    $chartDaily[] = [
        'label'=>(new DateTimeImmutable((string)$day['date']))->format('d.m.'),
        'tickets'=>(int)$day['ticket_created'],
        'faqOpened'=>(int)$day['faq_suggestion_open'],
        'faqHelpful'=>(int)$day['faq_suggestion_helpful'],
    ];
}
$chartPayload = [
    'daily'=>$chartDaily,
    'ticketStatus'=>[
        ['label'=>'Offen','value'=>(int)($ticketStats['open'] ?? 0)],
        ['label'=>'Erledigt','value'=>(int)($ticketStats['done'] ?? 0)],
    ],
    'assistant30'=>[
        ['label'=>'Inline-Chat','value'=>(int)($usageSummary['assistant_inline_use']['30d']['events'] ?? 0)],
        ['label'=>'Sprechblase','value'=>(int)($usageSummary['assistant_bubble_open']['30d']['events'] ?? 0)],
        ['label'=>'Extern geöffnet','value'=>(int)($usageSummary['assistant_external_open']['30d']['events'] ?? 0)],
        ['label'=>'Ticket nach KI','value'=>(int)($afterAssistant['30d']['events'] ?? 0)],
    ],
    'faq30'=>[
        ['label'=>'FAQ auf Startseite geöffnet','value'=>(int)($faqPublicOpen['30d']['events'] ?? 0)],
        ['label'=>'Vorschlag geöffnet','value'=>(int)($faqSuggestionOpen['30d']['events'] ?? 0)],
        ['label'=>'Hat geholfen','value'=>(int)($faqHelpful['30d']['events'] ?? 0)],
        ['label'=>'Ticket nach FAQ','value'=>(int)($ticketAfterFaq['30d']['events'] ?? 0)],
    ],
];
$chartJson = json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($chartJson)) $chartJson = '{}';
?>
<section class="panel stats-panel" data-stats-charts="<?= app_escape($chartJson) ?>">
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

<div class="stats-chart-grid">
<article class="stats-chart-card stats-chart-card-wide">
  <div class="stats-chart-heading"><div><span class="label">30 Tage</span><h3>Ticketverlauf</h3></div><p>Neue Tickets pro Tag</p></div>
  <div class="stats-chart-canvas" data-chart="ticket-trend"></div>
</article>
<article class="stats-chart-card">
  <div class="stats-chart-heading"><div><span class="label">Bestand</span><h3>Offen / erledigt</h3></div></div>
  <div class="stats-chart-canvas" data-chart="ticket-status"></div>
</article>
</div>

<h2>KI-Assistent</h2>
<?php if (!$usageReady): ?>
<p class="notice">Die Nutzungsstatistik wird nach Anwendung der Statistik-Migration verfügbar.</p>
<?php else: ?>
<p class="muted stats-explainer">„Nutzungen“ sind Öffnungen bzw. tatsächliche Einstiege. „Sitzungen“ werden pro Zugangsweg und Kalendertag höchstens einmal je Browsersitzung gezählt. Dieselbe Sitzung kann mehrere Zugangswege verwenden.</p>

<div class="stats-chart-grid">
<article class="stats-chart-card stats-chart-card-wide">
  <div class="stats-chart-heading">
    <div><span class="label">Letzte 30 Tage</span><h3>KI-Nutzung nach Zugangsweg</h3></div>
    <p><?= (int)($afterAssistant['30d']['events'] ?? 0) ?> Ticket(s) nach vorheriger KI-Nutzung</p>
  </div>
  <div class="stats-chart-canvas" data-chart="assistant"></div>
</article>
<article class="stats-chart-card stats-kpi-side">
  <div class="stats-side-kpi"><span>Ticket nach KI · 30 Tage</span><strong><?= (int)($afterAssistant['30d']['events'] ?? 0) ?></strong></div>
  <div class="stats-side-kpi"><span>Ticket nach KI · Gesamt</span><strong><?= (int)($afterAssistant['all']['events'] ?? 0) ?></strong></div>
  <p>Gezählt wird nur, wenn in derselben Browsersitzung zuvor einer der Assistenten-Zugänge genutzt wurde.</p>
</article>
</div>

<details class="stats-details">
<summary>KI-Detailwerte nach Zeitraum anzeigen</summary>
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
</details>

<h2>FAQ & Selbsthilfe</h2>
<div class="stats-chart-grid">
<article class="stats-chart-card">
  <div class="stats-chart-heading"><div><span class="label">Letzte 30 Tage</span><h3>Self-Service-Wirkung</h3></div></div>
  <div class="stats-chart-canvas" data-chart="faq"></div>
</article>
<article class="stats-chart-card stats-chart-card-wide">
  <div class="stats-chart-heading"><div><span class="label">30 Tage</span><h3>FAQ-Nutzung im Verlauf</h3></div><p>Vorschlag geöffnet vs. „hat geholfen“</p></div>
  <div class="stats-chart-canvas" data-chart="self-service-trend"></div>
</article>
</div>

<div class="stats-highlight faq-stats-highlight">
  <div><span>FAQ-Vorschläge geöffnet · 30 Tage</span><strong><?= (int)($faqSuggestionOpen['30d']['events'] ?? 0) ?></strong></div>
  <div><span>„Hat geholfen“ · 30 Tage</span><strong><?= (int)($faqHelpful['30d']['events'] ?? 0) ?></strong></div>
  <div><span>Tickets nach FAQ-Nutzung · 30 Tage</span><strong><?= (int)($ticketAfterFaq['30d']['events'] ?? 0) ?></strong></div>
  <div><span>FAQ auf Startseite geöffnet · 30 Tage</span><strong><?= (int)($faqPublicOpen['30d']['events'] ?? 0) ?></strong></div>
  <p>Damit lässt sich abschätzen, ob veröffentlichte FAQ und die automatische Vorschaltung tatsächlich Supportfälle abfangen.</p>
</div>

<details class="stats-details stats-raw-details">
<summary>Rohdaten der letzten 30 Tage anzeigen</summary>
<div class="stats-table-wrap">
<table class="stats-table">
<thead><tr><th>Tag</th><th>Tickets</th><th>Inline-Chat</th><th>Sprechblase</th><th>Extern</th><th>Ticket nach KI</th><th>FAQ-Vorschlag</th><th>FAQ hilfreich</th><th>Ticket nach FAQ</th></tr></thead>
<tbody>
<?php foreach (array_reverse($usageDaily) as $day): ?>
<tr>
<td><?= app_escape((new DateTimeImmutable((string)$day['date']))->format('d.m.Y')) ?></td>
<td><?= (int)$day['ticket_created'] ?></td>
<td><?= (int)$day['assistant_inline_use'] ?></td>
<td><?= (int)$day['assistant_bubble_open'] ?></td>
<td><?= (int)$day['assistant_external_open'] ?></td>
<td><?= (int)$day['ticket_after_assistant'] ?></td>
<td><?= (int)$day['faq_suggestion_open'] ?></td>
<td><?= (int)$day['faq_suggestion_helpful'] ?></td>
<td><?= (int)$day['ticket_after_faq'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</details>
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
<?php
$recommendation = (string)($proposal['recommendation'] ?? 'new');
$recommendationLabel = match ($recommendation) {
    'merge' => 'Bestehende FAQ wahrscheinlich',
    'review' => 'Bitte kurz prüfen',
    default => 'Neue FAQ sinnvoll',
};
$mergeQuestion = !empty($proposal['suggested_question'])
    ? (string)$proposal['suggested_question']
    : (string)$proposal['question'];
$mergeAnswer = app_faq_combined_answer(
    (string)($proposal['suggested_answer'] ?? ''),
    (string)($proposal['answer_draft'] ?? '')
);
$mergeCategoryId = (string)($proposal['suggested_category_id'] ?? '');
if ($mergeCategoryId === '') $mergeCategoryId = (string)($proposal['category_id'] ?? '');
?>
<article class="faq-moderation-card faq-recommendation-<?= app_escape($recommendation) ?>" id="faq-proposal-<?= app_escape((string)$proposal['id']) ?>">
<div class="faq-moderation-meta">
<div class="faq-meta-left">
<span class="label"><?= $proposal['source_type']==='ticket' ? 'Aus Ticket' : 'Admin-Vorschlag' ?></span>
<span class="faq-autopilot-badge faq-autopilot-<?= app_escape($recommendation) ?>"><?= app_escape($recommendationLabel) ?></span>
</div>
<?php if ($proposal['source_ticket_id'] !== null): ?><a href="/admin/?ticket=<?= rawurlencode((string)$proposal['source_ticket_id']) ?>"><?= app_escape(app_ticket_number((string)$proposal['source_ticket_id'])) ?> öffnen</a><?php endif; ?>
</div>

<?php if (!empty($proposal['recommendation_reason'])): ?>
<p class="faq-autopilot-reason"><?= app_escape((string)$proposal['recommendation_reason']) ?></p>
<?php endif; ?>

<?php if (!empty($proposal['suggested_entry_id']) && !empty($proposal['suggested_question'])): ?>
<aside class="faq-duplicate-hint">
<div class="faq-duplicate-heading">
<strong>Ähnliche bestehende FAQ</strong>
<?php if ($proposal['similarity_score'] !== null): ?><span><?= number_format((float)$proposal['similarity_score'], 0, ',', '.') ?> % Ähnlichkeit</span><?php endif; ?>
</div>
<h3><?= app_escape((string)$proposal['suggested_question']) ?></h3>
<p class="preserve"><?= nl2br(app_escape((string)$proposal['suggested_answer'])) ?></p>
<small>Bisher mit <?= (int)($proposal['suggested_ticket_count'] ?? 0) ?> Ticket(s) verknüpft.</small>
</aside>

<div class="faq-synergy-actions">
<form method="post" class="faq-link-form">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_link_existing">
<input type="hidden" name="proposal_id" value="<?= app_escape((string)$proposal['id']) ?>">
<button type="submit" class="faq-link-button">Mit bestehender FAQ verknüpfen</button>
<p class="muted">Empfohlen, wenn die vorhandene FAQ das Problem bereits ausreichend beantwortet. Öffentlicher Text bleibt unverändert; die neue Formulierung verbessert nur die interne Suche und der Ticketzähler steigt.</p>
</form>

<details class="faq-supplement-box">
<summary>Bestehende FAQ inhaltlich ergänzen</summary>
<div class="faq-supplement-body">
<div class="faq-merge-comparison">
<div>
<span class="label">Bisher öffentlich</span>
<strong><?= app_escape((string)$proposal['suggested_question']) ?></strong>
<p class="preserve"><?= nl2br(app_escape((string)$proposal['suggested_answer'])) ?></p>
</div>
<div>
<span class="label">Neuer Supportfall</span>
<strong><?= app_escape((string)$proposal['question']) ?></strong>
<p class="preserve"><?= nl2br(app_escape((string)($proposal['answer_draft'] ?? ''))) ?></p>
</div>
</div>

<form class="admin-form faq-merge-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_merge">
<input type="hidden" name="proposal_id" value="<?= app_escape((string)$proposal['id']) ?>">
<label for="merge-question-<?= app_escape((string)$proposal['id']) ?>">Gemeinsame öffentliche Problemfrage</label>
<textarea id="merge-question-<?= app_escape((string)$proposal['id']) ?>" name="merge_question" maxlength="400" rows="3" required><?= app_escape($mergeQuestion) ?></textarea>
<label for="merge-answer-<?= app_escape((string)$proposal['id']) ?>">Gemeinsame öffentliche Antwort</label>
<textarea id="merge-answer-<?= app_escape((string)$proposal['id']) ?>" name="merge_answer" maxlength="8000" rows="9" required><?= app_escape($mergeAnswer) ?></textarea>
<label for="merge-category-<?= app_escape((string)$proposal['id']) ?>">Kategorie</label>
<select id="merge-category-<?= app_escape((string)$proposal['id']) ?>" name="merge_category_id">
<option value="">Keine feste Kategorie</option>
<?php foreach ($categories as $category): ?>
<option value="<?= app_escape((string)$category['id']) ?>"<?= $mergeCategoryId===(string)$category['id'] ? ' selected' : '' ?>><?= app_escape((string)$category['name']) ?></option>
<?php endforeach; ?>
</select>
<p class="warning"><strong>Kontrollierter Merge:</strong> Die bisherige FAQ bleibt Ausgangspunkt. Nur zusätzliche allgemeine Informationen übernehmen. Vor dem Speichern kann die gemeinsame Fassung vollständig bearbeitet werden.</p>
<button type="submit" class="faq-merge-button">Geprüfte Ergänzung speichern</button>
</form>
</div>
</details>
</div>
<?php endif; ?>

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
<button type="submit" name="action" value="faq_publish"><?= !empty($proposal['suggested_entry_id']) ? 'Trotzdem neue FAQ veröffentlichen' : 'Prüfen & veröffentlichen' ?></button>
<button type="submit" name="action" value="faq_reject" class="secondary-button" formnovalidate>Kein FAQ-Fall</button>
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
<article class="faq-entry-admin" id="faq-entry-<?= app_escape((string)$entry['id']) ?>">
<div class="faq-entry-meta">
<span class="status <?= $entry['status']==='published' ? 'status-done' : 'status-archived' ?>"><?= $entry['status']==='published' ? 'Veröffentlicht' : 'Inaktiv' ?></span>
<?php if (!empty($entry['category_name'])): ?> <span class="label"><?= app_escape((string)$entry['category_name']) ?></span><?php endif; ?>
<span class="faq-ticket-count"><?= (int)($entry['ticket_count'] ?? 0) ?> Ticket(s)</span>
<?php if ((int)($entry['search_term_count'] ?? 0) > 0): ?><span class="faq-search-count"><?= (int)$entry['search_term_count'] ?> interne Suchbegriffe</span><?php endif; ?>
<?php if ((int)($entry['revision_count'] ?? 0) > 0): ?><span class="faq-revision-count"><?= (int)$entry['revision_count'] ?> ältere Fassung(en)</span><?php endif; ?>
</div>
<h3><?= app_escape((string)$entry['question']) ?></h3>
<p class="preserve"><?= nl2br(app_escape((string)$entry['answer'])) ?></p>
<div class="faq-entry-actions">
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_entry_status">
<input type="hidden" name="entry_id" value="<?= app_escape((string)$entry['id']) ?>">
<input type="hidden" name="entry_status" value="<?= $entry['status']==='published' ? 'inactive' : 'published' ?>">
<button type="submit" class="secondary-button"><?= $entry['status']==='published' ? 'Ausblenden' : 'Wieder veröffentlichen' ?></button>
</form>

<details class="faq-entry-edit">
<summary>FAQ bearbeiten</summary>
<form class="admin-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="faq_entry_edit">
<input type="hidden" name="entry_id" value="<?= app_escape((string)$entry['id']) ?>">
<label>Öffentliche Problemfrage
<textarea name="entry_question" maxlength="400" rows="3" required><?= app_escape((string)$entry['question']) ?></textarea></label>
<label>Öffentliche Antwort
<textarea name="entry_answer" maxlength="8000" rows="7" required><?= app_escape((string)$entry['answer']) ?></textarea></label>
<label>Kategorie
<select name="entry_category_id">
<option value="">Keine feste Kategorie</option>
<?php foreach ($categories as $category): ?>
<option value="<?= app_escape((string)$category['id']) ?>"<?= (string)($entry['category_id'] ?? '')===(string)$category['id'] ? ' selected' : '' ?>><?= app_escape((string)$category['name']) ?></option>
<?php endforeach; ?>
</select></label>
<p class="muted">Vor jeder Änderung wird die bisherige Fassung intern als Revision gesichert.</p>
<button type="submit">Änderungen speichern</button>
</form>
</details>
</div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<?php elseif ($section === 'account'): ?>
<section class="panel account-panel">
<span class="label">Konto</span>
<h1>Passwort ändern</h1>
<?php if ((int)($user['must_change_password'] ?? 0) === 1): ?>
<p class="notice"><strong>Startpasswort ändern:</strong> Bevor du den Adminbereich weiter nutzt, lege bitte ein eigenes Passwort fest.</p>
<?php else: ?>
<p>Hier kannst du dein persönliches Admin-Passwort ändern.</p>
<?php endif; ?>
<form class="admin-form" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="change_own_password">
<label for="current_password">Aktuelles Passwort</label>
<input id="current_password" name="current_password" type="password" required autocomplete="current-password">
<label for="new_password">Neues Passwort</label>
<input id="new_password" name="new_password" type="password" minlength="14" maxlength="1024" required autocomplete="new-password">
<label for="new_password_repeat">Neues Passwort wiederholen</label>
<input id="new_password_repeat" name="new_password_repeat" type="password" minlength="14" maxlength="1024" required autocomplete="new-password">
<p class="muted">Mindestens 14 Zeichen. Es gibt keine erzwungenen Sonderzeichenregeln; ein langes, einzigartiges Passwort ist wichtiger.</p>
<button type="submit">Passwort ändern</button>
</form>
</section>

<?php elseif ($section === 'system' && ($user['role'] ?? '') === 'system_admin'): ?>
<section class="panel system-update-panel" id="updates">
<span class="label">Software</span>
<h1>Updates</h1>
<?php if (!is_array($updateStatus)): ?>
<p class="notice">Der lokale Update-Prüfdienst ist derzeit nicht erreichbar.</p>
<?php else: ?>
<div class="system-status-grid">
<div><span>Installierte Version</span><strong><?= app_escape((string)($updateStatus['installed_version'] ?: 'unbekannt')) ?></strong></div>
<div><span>Updatekanal</span><strong><?= app_escape((string)($updateStatus['channel'] ?: 'development')) ?></strong></div>
<div><span>Letzte Prüfung</span><strong><?= !empty($updateStatus['checked_at']) ? app_escape(app_local_time((string)$updateStatus['checked_at'])) : 'Noch nicht geprüft' ?></strong></div>
<div><span>Status</span><strong><?= ($updateStatus['available'] ?? false) ? 'Update verfügbar' : 'Kein neueres Release erkannt' ?></strong></div>
</div>

<?php if (!empty($updateStatus['error'])): ?>
<p class="notice"><?= app_escape((string)$updateStatus['error']) ?></p>
<?php endif; ?>

<?php if (($updateStatus['available'] ?? false) === true): ?>
<div class="update-available-card">
<h2>Version <?= app_escape((string)$updateStatus['latest_version']) ?> verfügbar</h2>
<?php if (!empty($updateStatus['published_at'])): ?><p class="muted">Veröffentlicht: <?= app_escape(app_local_time((string)$updateStatus['published_at'])) ?></p><?php endif; ?>
<?php if (!empty($updateStatus['release_notes'])): ?><details><summary>Änderungen ansehen</summary><div class="release-notes"><?= nl2br(app_escape((string)$updateStatus['release_notes'])) ?></div></details><?php endif; ?>
<p class="notice"><strong>Noch nicht automatisch installierbar:</strong> Die Installationsfunktion wird erst aktiviert, sobald Releasepakete mit der geplanten Signaturprüfung veröffentlicht werden. Bis dahin lädt das System bewusst keinen Anwendungscode automatisch herunter.</p>
</div>
<?php else: ?>
<p>Der Prüfdienst fragt regelmäßig ausschließlich die öffentliche Releasequelle ab. Es werden dabei keine Ticket-, Schul- oder Benutzerdaten übertragen.</p>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="check_updates">
<button type="submit" class="secondary-button">Jetzt nach Updates suchen</button>
</form>

<?php if (($updateStatus['channel'] ?? '') === 'development'): ?>
<div class="development-update-card">
<span class="label">Nur Testsystem</span>
<h2>Entwicklerversion</h2>
<p>Diese Entwicklungsinstanz kann direkt auf den aktuellen Stand des GitHub-Branches <code>main</code> aktualisiert werden. Das ist bewusst getrennt vom späteren signierten Release-Update für Schulen.</p>

<?php if (is_array($devUpdateStatus)): ?>
<?php $devState = (string)($devUpdateStatus['state'] ?? 'idle'); ?>
<div class="development-update-status development-update-<?= app_escape($devState) ?>">
<strong><?=
    $devState === 'running' ? 'Update läuft' :
    ($devState === 'success' ? 'Letztes Entwicklungsupdate erfolgreich' :
    ($devState === 'failed' ? 'Letztes Entwicklungsupdate fehlgeschlagen' : 'Bereit'))
?></strong>
<?php if (!empty($devUpdateStatus['message'])): ?><span><?= app_escape((string)$devUpdateStatus['message']) ?></span><?php endif; ?>
<?php if (!empty($devUpdateStatus['finished_at'])): ?><small>Abgeschlossen: <?= app_escape(app_local_time((string)$devUpdateStatus['finished_at'])) ?></small><?php endif; ?>
<?php if ($devState === 'failed' && !empty($devUpdateStatus['detail'])): ?><details><summary>Fehlerdetails</summary><pre><?= app_escape((string)$devUpdateStatus['detail']) ?></pre></details><?php endif; ?>
</div>

<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="start_development_update">
<button type="submit"<?= $devState === 'running' ? ' disabled' : '' ?>><?= $devState === 'running' ? 'Update läuft …' : 'Entwicklerversion aus GitHub aktualisieren' ?></button>
</form>
<?php else: ?>
<p class="notice">Der Entwicklungs-Updater ist auf diesem Stand noch nicht installiert. Einmalig den normalen Installer ausführen; danach sind weitere main-Updates über diesen Button möglich.</p>
<?php endif; ?>

<p class="muted"><strong>Entwicklungsmodus:</strong> Dieser Weg folgt einem veränderlichen GitHub-Branch und besitzt bewusst nicht die Signaturgarantien des späteren Stable-Updaters. Er erscheint ausschließlich auf Entwicklungsinstanzen.</p>
</div>
<?php endif; ?>
<?php endif; ?>
</section>

<section class="panel system-backup-panel" id="backups">
<span class="label">Datensicherung</span>
<h2>Backups</h2>
<?php if ($backupStatusError !== ''): ?>
<p class="error notice"><?= app_escape($backupStatusError) ?></p>
<?php elseif (!is_array($backupStatus)): ?>
<p class="notice">Backup-Status ist derzeit nicht verfügbar.</p>
<?php else: ?>
<div class="system-status-grid">
<div><span>USB-Backup</span><strong><?= ($backupStatus['configured'] ?? false) ? 'Eingerichtet' : 'Noch nicht eingerichtet' ?></strong></div>
<div><span>Backupmedium</span><strong><?= ($backupStatus['present'] ?? false) ? 'Angeschlossen' : (($backupStatus['configured'] ?? false) ? 'Nicht angeschlossen' : '—') ?></strong></div>
<div><span>Verschlüsselung</span><strong><?= ($backupStatus['encryption_configured'] ?? false) ? 'Aktiv' : 'Noch nicht aktiviert' ?></strong></div>
<?php
$lastBackup = is_array($backupStatus['last_backup'] ?? null) ? $backupStatus['last_backup'] : null;
?>
<div><span>Letzte Sicherung</span><strong><?= $lastBackup !== null && !empty($lastBackup['created_at']) ? app_escape(app_local_time((string)$lastBackup['created_at'])) : 'Noch keine' ?></strong></div>
</div>

<?php if ($lastBackup !== null): ?>
<div class="backup-last">
<strong>Letztes verschlüsseltes Archiv</strong>
<code><?= app_escape((string)($lastBackup['archive'] ?? '')) ?></code>
<?php if (isset($lastBackup['size_bytes'])): ?><span><?= number_format(((int)$lastBackup['size_bytes']) / 1024 / 1024, 1, ',', '.') ?> MB</span><?php endif; ?>
</div>
<?php endif; ?>

<?php if (($backupStatus['configured'] ?? false) && ($backupStatus['encryption_configured'] ?? false)): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="create_backup">
<button type="submit"<?= ($backupStatus['present'] ?? false) ? '' : ' disabled' ?>>Backup jetzt erstellen</button>
</form>
<?php elseif (!($backupStatus['configured'] ?? false)): ?>
<p class="notice">Das USB-Backupmedium wird einmalig während der Einrichtung registriert. Danach kann es vollständig hier überwacht und manuell gesichert werden.</p>
<?php else: ?>
<p class="notice">Das Backupmedium ist registriert, aber die Recovery-Code-geschützte Verschlüsselung wurde noch nicht aktiviert.</p>
<?php endif; ?>
<p class="muted">Automatische Sicherungen laufen zusätzlich über den täglichen Backup-Timer. Der Recovery-Code und der USB-Datenträger gehören getrennt aufbewahrt.</p>
<?php endif; ?>
</section>

<section class="panel">
<span class="label">Optional</span>
<h2>KI-Assistent</h2>
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

<section class="panel system-public-access" id="public-access">
<span class="label">Öffentlicher Zugang</span>
<h2>Domain / Cloudflare Tunnel</h2>
<p>Damit die Kollegiumsseite auch außerhalb des lokalen Netzes erreichbar ist, kann ein Cloudflare Tunnel verwendet werden. Am Router ist dafür keine Portfreigabe nötig.</p>

<?php if ($tunnelStatusError !== ''): ?>
<p class="error notice"><?= app_escape($tunnelStatusError) ?></p>
<?php elseif (is_array($tunnelStatus)): ?>
<div class="system-status-grid">
<div><span>Konfiguration</span><strong><?= ($tunnelStatus['configured'] ?? false) ? 'Eingerichtet' : 'Noch nicht eingerichtet' ?></strong></div>
<div><span>Tunnel-Dienst</span><strong><?= ($tunnelStatus['service_active'] ?? false) ? 'Aktiv' : 'Nicht aktiv' ?></strong></div>
<div><span>cloudflared</span><strong><?= ($tunnelStatus['cloudflared_installed'] ?? false) ? 'Installiert' : 'Wird bei Aktivierung installiert' ?></strong></div>
<?php if (!empty($tunnelStatus['hostname'])): ?><div><span>Hostname</span><strong><?= app_escape((string)$tunnelStatus['hostname']) ?></strong></div><?php endif; ?>
</div>

<?php if (($tunnelStatus['configured'] ?? false) === true && !empty($tunnelStatus['hostname'])): ?>
<?php $staffPublicUrl = app_public_access_url((string)$tunnelStatus['hostname']); ?>
<div class="public-access-ready">
<strong>Öffentlicher Kollegiumslink</strong>
<?php if ($staffPublicUrl !== ''): ?><code><?= app_escape($staffPublicUrl) ?></code><?php endif; ?>
<p class="muted">Dieser Link enthält das Zugangstoken und sollte nur innerhalb der Schule bzw. im geschützten Schulportal weitergegeben werden.</p>
<div class="system-inline-actions">
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="test_tunnel">
<button type="submit">Verbindung testen</button>
</form>
<?php if ($staffPublicUrl !== ''): ?><a class="button secondary" href="<?= app_escape($staffPublicUrl) ?>" target="_blank" rel="noopener noreferrer">Kollegiumsseite öffnen</a><?php endif; ?>
</div>

<details class="access-token-rotation">
<summary>Kollegiumslink erneuern</summary>
<form class="admin-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="rotate_public_access_token">
<label class="check-row"><input type="checkbox" name="confirm_access_rotation" value="1" required> Alten Kollegiumslink sofort ungültig machen und bestehende Kollegiumssitzungen beenden</label>
<p class="muted">Es wird ein neues zufälliges Zugangstoken erzeugt. Der Cloudflare Tunnel und die Domain bleiben unverändert. Anschließend muss nur der neue Kollegiumslink im Schulportal bzw. intern verteilt werden.</p>
<button type="submit" class="secondary-button">Neuen Kollegiumslink erzeugen</button>
</form>
</details>
</div>
<?php endif; ?>
<?php endif; ?>

<details class="system-guide"<?= !is_array($tunnelStatus) || !($tunnelStatus['configured'] ?? false) ? ' open' : '' ?>>
<summary>Tunnel einrichten oder ändern</summary>
<div class="system-guide-body">
<ol>
<li>Die gewünschte Domain muss bei Cloudflare verwaltet werden.</li>
<li>Im Cloudflare-Dashboard einen <strong>Tunnel</strong> anlegen.</li>
<li>Als öffentliche Anwendung den gewünschten Hostnamen eintragen, z. B. <code>support.schule.de</code>.</li>
<li>Als Ziel/Service <code>http://localhost:8081</code> eintragen.</li>
<li>Den angezeigten Tunnel-Token oder den kompletten <code>cloudflared service install …</code>-Befehl hier einfügen.</li>
</ol>

<form class="admin-form" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="configure_tunnel">
<label for="tunnel_hostname">Öffentlicher Hostname</label>
<input id="tunnel_hostname" name="tunnel_hostname" maxlength="253" required
       value="<?= app_escape((string)($tunnelStatus['hostname'] ?? '')) ?>"
       placeholder="support.schule.de" inputmode="url">
<label for="tunnel_token">Cloudflare Tunnel-Token oder Installationsbefehl</label>
<textarea id="tunnel_token" name="tunnel_token" maxlength="8192" rows="4" required
          autocomplete="new-password" spellcheck="false"
          placeholder="eyJ… oder: sudo cloudflared service install eyJ…"></textarea>
<p class="muted">Der Token ist ein Zugangsschlüssel. Er wird root-only auf dem Raspberry Pi gespeichert und danach hier nicht mehr angezeigt.</p>
<button type="submit"><?= ($tunnelStatus['configured'] ?? false) ? 'Tunnel neu verbinden' : 'Tunnel installieren & aktivieren' ?></button>
</form>
</div>
</details>

<?php if (is_array($tunnelStatus) && ($tunnelStatus['configured'] ?? false)): ?>
<details class="danger-zone">
<summary>Öffentlichen Zugang entfernen</summary>
<form class="admin-form" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="disable_tunnel">
<label class="check-row"><input type="checkbox" name="confirm_tunnel_remove" value="1" required> Tunnel auf diesem Raspberry Pi trennen und gespeicherten Token löschen</label>
<p class="muted">Tickets, FAQ und lokale Zugriffe bleiben unverändert. Die Cloudflare-Konfiguration im Cloudflare-Konto wird dadurch nicht gelöscht.</p>
<button type="submit" class="danger-button">Tunnel trennen</button>
</form>
</details>
<?php endif; ?>
</section>

<section class="panel admin-accounts-panel" id="admin-accounts">
<span class="label">Berechtigungen</span>
<h2>Administratorkonten</h2>
<p>System-Admins verwalten Technik, Tunnel und Systemeinstellungen. Ticket-Admins bearbeiten Tickets, FAQ und Statistiken, aber keine Systemkonfiguration.</p>

<details class="admin-create-account">
<summary>Neues Administratorkonto anlegen</summary>
<form class="admin-form" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="create_admin">
<label for="admin_display_name">Anzeigename</label>
<input id="admin_display_name" name="admin_display_name" maxlength="100" required>
<label for="admin_username">Benutzername</label>
<input id="admin_username" name="admin_username" maxlength="100" minlength="3" required autocomplete="off" placeholder="z. B. lep">
<label for="admin_role">Rolle</label>
<select id="admin_role" name="admin_role">
<option value="ticket_admin">Ticket-Admin</option>
<option value="system_admin">System-Admin</option>
</select>
<label for="admin_password">Startpasswort</label>
<input id="admin_password" name="admin_password" type="password" minlength="14" maxlength="1024" required autocomplete="new-password">
<p class="muted">Beim ersten Login muss dieses Startpasswort geändert werden.</p>
<button type="submit">Admin anlegen</button>
</form>
</details>

<div class="admin-account-list">
<?php foreach ($adminUsers as $adminAccount): ?>
<article class="admin-account-card">
<div class="admin-account-heading">
<div>
<strong><?= app_escape((string)$adminAccount['display_name']) ?></strong>
<span>@<?= app_escape((string)$adminAccount['username']) ?></span>
</div>
<div>
<span class="status <?= (int)$adminAccount['is_active'] === 1 ? 'status-done' : 'status-archived' ?>"><?= (int)$adminAccount['is_active'] === 1 ? 'Aktiv' : 'Gesperrt' ?></span>
<span class="label"><?= $adminAccount['role']==='system_admin' ? 'System-Admin' : 'Ticket-Admin' ?></span>
</div>
</div>
<p class="muted">Letzter Login: <?= app_escape(app_local_time(is_string($adminAccount['last_login_at']) ? $adminAccount['last_login_at'] : null)) ?><?php if ((int)$adminAccount['must_change_password'] === 1): ?> · Startpasswort muss geändert werden<?php endif; ?></p>

<?php if ((string)$adminAccount['id'] === (string)$user['id']): ?>
<p class="notice">Das ist dein eigenes Konto. Rolle und Aktivstatus werden hier nicht verändert.</p>
<?php else: ?>
<form class="admin-account-controls" method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="update_admin">
<input type="hidden" name="admin_id" value="<?= app_escape((string)$adminAccount['id']) ?>">
<div><label>Rolle
<select name="admin_role">
<option value="ticket_admin"<?= $adminAccount['role']==='ticket_admin'?' selected':'' ?>>Ticket-Admin</option>
<option value="system_admin"<?= $adminAccount['role']==='system_admin'?' selected':'' ?>>System-Admin</option>
</select></label></div>
<label class="check-row"><input type="checkbox" name="admin_active" value="1"<?= (int)$adminAccount['is_active']===1?' checked':'' ?>> Konto aktiv</label>
<button type="submit" class="secondary-button">Konto aktualisieren</button>
</form>

<details class="admin-password-reset">
<summary>Startpasswort neu setzen</summary>
<form class="admin-form" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="reset_admin_password">
<input type="hidden" name="admin_id" value="<?= app_escape((string)$adminAccount['id']) ?>">
<label>Neues Startpasswort
<input name="reset_password" type="password" minlength="14" maxlength="1024" required autocomplete="new-password"></label>
<label>Startpasswort wiederholen
<input name="reset_password_repeat" type="password" minlength="14" maxlength="1024" required autocomplete="new-password"></label>
<button type="submit">Passwort zurücksetzen</button>
</form>
</details>
<?php endif; ?>
</article>
<?php endforeach; ?>
</div>
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

<section class="edit-box ticket-access-admin">
<h2>Statusabfrage</h2>
<p>Für die Kollegiumsseite benötigt die meldende Person Ticketnummer und den geheimen Statuscode. Der aktuelle Code ist nicht auslesbar.</p>
<?php if (is_array($oneTimeStatusCode)
    && (string)($oneTimeStatusCode['ticket_id'] ?? '') === (string)$detail['id']
    && is_string($oneTimeStatusCode['code'] ?? null)): ?>
<div class="one-time-code">
<span>Neuer Statuscode – jetzt weitergeben oder sicher notieren</span>
<strong><?= app_escape((string)$oneTimeStatusCode['code']) ?></strong>
<small>Nach Verlassen dieser Seite wird der Code nicht erneut angezeigt.</small>
</div>
<?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="reset_ticket_status_code">
<input type="hidden" name="ticket_id" value="<?= app_escape((string)$detail['id']) ?>">
<button type="submit" class="secondary-button">Neuen Statuscode erzeugen</button>
</form>
</section>

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
<footer class="admin-footer">Schul-IT Ticketsystem · lokale Raspberry-Pi-Instanz<?php if (is_array($updateStatus) && !empty($updateStatus['installed_version'])): ?> · Version <?= app_escape((string)$updateStatus['installed_version']) ?><?= ($updateStatus['available'] ?? false) ? ' · Update verfügbar' : '' ?><?php endif; ?></footer>
<?php if ($user !== null && $section === 'stats'): ?><script src="/assets/stats-charts.js" defer></script><?php endif; ?>
</body>
</html>
