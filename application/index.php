<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

app_start_session(SCHULIT_PUBLIC_SESSIONS, 'schulit_public');
app_try_public_token();

$error = '';
$successTicket = null;
$statusResult = null;
$ready = false;
$db = null;
$schoolName = 'Schul-IT Ticketsystem';
$schoolId = '';
$assistant = ['enabled'=>false,'label'=>'KI-Assistent','url'=>''];

try {
    $db = app_database();
    $ready = app_tables_ready($db);
    if ($ready) {
        $schoolName = app_setting($db, 'school_name', $schoolName);
        $schoolId = app_setting($db, 'school_id');
        $assistant = app_assistant_settings($db);
    }
} catch (Throwable $caught) {
    $error = $caught->getMessage();
}

$authorized = app_public_authorized();

if ($authorized && $ready && $db instanceof PDO && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!app_csrf_valid($_SESSION, $_POST['csrf'] ?? null)) {
            throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
        }
        $action = $_POST['action'] ?? '';

        if ($action === 'create_ticket') {
            if (!app_session_rate($_SESSION, 'create', 10, 300)) {
                throw new RuntimeException('Zu viele Ticketversuche. Bitte einige Minuten warten.');
            }
            $type = (string)($_POST['type'] ?? '');
            $id = app_ticket_create($db, $_POST, $type);
            $successTicket = [
                'id' => $id,
                'number' => app_ticket_number($id),
                'school_id' => $schoolId,
            ];
        } elseif ($action === 'lookup_ticket') {
            if (!app_session_rate($_SESSION, 'lookup', 30, 300)) {
                throw new RuntimeException('Zu viele Statusabfragen. Bitte einige Minuten warten.');
            }
            $statusResult = app_ticket_lookup(
                $db,
                $_POST['ticket_number'] ?? null,
                $_POST['school_id'] ?? null
            );
            if ($statusResult === null) {
                throw new RuntimeException('Kein passendes Ticket gefunden. Bitte Schulkennung und Ticketnummer prüfen.');
            }
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
    }
}

$type = $_GET['type'] ?? '';
if (!is_string($type) || !in_array($type, ['support','defect'], true)) {
    $type = '';
}
$categories = ($authorized && $ready && $db instanceof PDO) ? app_categories($db, false) : [];
$csrf = $authorized ? app_csrf($_SESSION) : '';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= app_escape($schoolName) ?> – IT-Support</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="shell">
<header>
  <div>
    <div class="brand">Schul-IT Ticketsystem</div>
    <div class="school"><?= app_escape($schoolName) ?></div>
  </div>
  <?php if ($authorized): ?><nav><a href="/">Tickets</a><?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?><a href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape((string)$assistant['label']) ?></a><?php endif; ?></nav><?php endif; ?>
</header>

<?php if (!$authorized): ?>
<section class="card">
<h1>Zugang erforderlich</h1>
<p class="muted">Diese Supportseite ist nur über den von deiner Schule bereitgestellten Zugangslink erreichbar.</p>
<div class="notice">Bitte öffne den Link aus dem Schulportal bzw. der internen Schulwebsite erneut.</div>
</section>

<?php elseif (!$ready): ?>
<section class="card">
<h1>Einrichtung noch nicht abgeschlossen</h1>
<p class="muted">Die technische Serverbasis läuft, aber die Ticketdatenbank ist noch nicht vollständig eingerichtet.</p>
<a class="button" href="http://<?= app_escape(app_current_host()) ?>:8080/">Einrichtungsassistent öffnen</a>
</section>

<?php else: ?>

<?php if ($error !== ''): ?><div class="card error"><strong>Das hat noch nicht funktioniert:</strong><br><?= app_escape($error) ?></div><?php endif; ?>

<?php if ($successTicket !== null): ?>
<section class="card success">
<h2>Ticket wurde angelegt ✓</h2>
<p>Deine Ticketnummer lautet <strong><?= app_escape($successTicket['number']) ?></strong>.</p>
<p>Für die spätere Statusabfrage brauchst du die Schulkennung <strong><?= app_escape($successTicket['school_id']) ?></strong> und diese Ticketnummer.</p>
<div class="actions"><a class="button" href="/">Zur Startseite</a></div>
</section>
<?php elseif ($statusResult !== null): ?>
<section class="card">
<h2>Status <?= app_escape(app_ticket_number((string)$statusResult['id'])) ?></h2>
<div class="detail-grid">
  <div><div class="key">Status</div><div class="value"><?= app_escape(app_status_label((string)$statusResult['status'])) ?></div></div>
  <div><div class="key">Erstellt</div><div class="value"><?= app_escape(app_local_time((string)$statusResult['created_at'])) ?></div></div>
  <div><div class="key">Letzte Statusänderung</div><div class="value"><?= app_escape(app_local_time((string)$statusResult['status_changed_at'])) ?></div></div>
</div>
<div class="actions"><a class="button secondary" href="/">Zurück</a></div>
</section>
<?php elseif ($type === ''): ?>
<section class="card">
<h1>Wie können wir helfen?</h1>
<p class="muted">Wähle, ob du Hilfe bei einem IT-Problem brauchst oder einen Defekt melden möchtest.</p>
<div class="grid">
  <div class="choice">
    <h2>Hilfe / Problem</h2>
    <p class="muted">Software, Zugang, WLAN, Schulportal, Geräte oder andere IT-Fragen.</p>
    <a class="button" href="/?type=support">Hilfe anfordern</a>
  </div>
  <div class="choice">
    <h2>Defekt / Hardware</h2>
    <p class="muted">Ein Gerät oder Zubehör ist beschädigt oder funktioniert nicht mehr.</p>
    <a class="button" href="/?type=defect">Defekt melden</a>
  </div>
</div>
</section>

<section class="card">
<h2>Ticketstatus prüfen</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="lookup_ticket">
<div class="grid">
  <div><label for="school_id">Schulkennung</label><input id="school_id" name="school_id" required maxlength="32" value="<?= app_escape($schoolId) ?>"></div>
  <div><label for="ticket_number">Ticketnummer</label><input id="ticket_number" name="ticket_number" required maxlength="30" placeholder="#000001"></div>
</div>
<div class="actions"><button type="submit">Status anzeigen</button></div>
</form>
</section>

<?php else: ?>
<section class="card">
<h1><?= $type === 'defect' ? 'Defekt melden' : 'Ticket aufgeben' ?></h1>
<p class="muted"><?= $type === 'defect'
    ? 'Defektmeldungen werden automatisch mit hoher Priorität erfasst.'
    : 'Beschreibe dein Anliegen. Hilfekarten und Wissenssuche werden im nächsten Entwicklungsschritt vorgeschaltet.' ?></p>
<div class="notice"><strong>Datensparsam ausfüllen:</strong> Keine Passwörter, Zugangsdaten oder unnötigen personenbezogenen Daten von Schülerinnen und Schülern eintragen.</div>

<form method="post" action="/?type=<?= app_escape($type) ?>">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="create_ticket">
<input type="hidden" name="type" value="<?= app_escape($type) ?>">

<?php if ($type === 'support'): ?>
<label for="category_id">Kategorie *</label>
<select id="category_id" name="category_id" required>
<option value="">Bitte auswählen</option>
<?php foreach ($categories as $category): ?>
<option value="<?= app_escape((string)$category['id']) ?>"><?= app_escape((string)$category['name']) ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>

<div class="grid">
<div><label for="reporter_name">Vor- und Nachname *</label><input id="reporter_name" name="reporter_name" maxlength="100" required autocomplete="name"></div>
<div><label for="reporter_abbreviation">Kürzel *</label><input id="reporter_abbreviation" name="reporter_abbreviation" maxlength="20" required placeholder="z. B. HNR"></div>
</div>

<div class="grid">
<div><label for="location">Raum / Ort *</label><input id="location" name="location" maxlength="150" required></div>
<div><label for="device">Gerät / System *</label><input id="device" name="device" maxlength="255" required placeholder="z. B. iPad, Smartboard, Dienstlaptop"></div>
</div>

<?php if ($type === 'defect'): ?>
<label for="defect_subject">Was ist defekt? *</label>
<input id="defect_subject" name="defect_subject" maxlength="255" required>
<?php endif; ?>

<div class="grid">
<div><label for="inventory_number">Inventar-/Netzwerkgerätenummer</label><input id="inventory_number" name="inventory_number" maxlength="100"></div>
<div><label for="serial_number">Seriennummer</label><input id="serial_number" name="serial_number" maxlength="100"></div>
</div>

<label for="description">Problembeschreibung *</label>
<textarea id="description" name="description" maxlength="5000" rows="6" required></textarea>

<label for="occurrence_details">Seit wann / wann tritt es auf?</label>
<textarea id="occurrence_details" name="occurrence_details" maxlength="2000" rows="3"></textarea>

<div class="actions">
<button type="submit"><?= $type === 'defect' ? 'Defekt verbindlich melden' : 'Ticket verbindlich absenden' ?></button>
<a class="button secondary" href="/">Abbrechen</a>
</div>
</form>
</section>
<?php endif; ?>

<?php endif; ?>
</main>
</body>
</html>
