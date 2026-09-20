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
$publicFaq = ($authorized && $ready && $db instanceof PDO) ? app_faq_public_entries($db, 12) : [];
$faqReady = $authorized && $ready && $db instanceof PDO && app_faq_tables_ready($db);
$csrf = $authorized ? app_csrf($_SESSION) : '';
$assistantWidgetActive = $authorized
    && $ready
    && ($assistant['enabled'] ?? false)
    && ($assistant['widget_enabled'] ?? false)
    && is_string($assistant['widget_url'] ?? null)
    && ($assistant['widget_url'] ?? '') !== '';
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
  <?php if ($authorized): ?><nav><a href="/">Tickets</a><?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?><a<?= $assistantWidgetActive ? ' data-assistant-open' : '' ?> href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer"><?= app_escape((string)$assistant['label']) ?></a><?php endif; ?></nav><?php endif; ?>
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
<?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?>
<p class="muted">Nutze zuerst den <?= app_escape((string)$assistant['label']) ?> für eine direkte Hilfestellung. Wenn dein Problem damit nicht gelöst wird, kannst du anschließend ein Ticket aufgeben.</p>
<section class="assistant-intro" aria-labelledby="assistant-title">
  <span class="label">Empfohlener erster Schritt</span>
  <h2 id="assistant-title"><?= app_escape((string)$assistant['label']) ?> fragen</h2>
  <p>Beschreibe dein Problem möglichst konkret. Der Assistent kann dir direkt bei typischen Fragen zu Anwendungen, Geräten oder Zugängen helfen.</p>
  <?php if ($assistantWidgetActive): ?>
  <div class="assistant-inline-wrap">
      <div class="assistant-inline-frame">
      <iframe
        title="<?= app_escape((string)$assistant['label']) ?>"
        src="<?= app_escape((string)$assistant['widget_url']) ?>"
        referrerpolicy="no-referrer"
        sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
        loading="eager"></iframe>
    </div>
    <div class="assistant-inline-actions">
      <a class="button secondary" href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer">Extern öffnen</a>
    </div>
  </div>
  <?php else: ?>
  <a class="button assistant-start" href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer">KI-Assistent starten</a>
  <?php endif; ?>
  <p class="assistant-disclosure"><strong>Hinweis:</strong> KI kann Fehler machen. Prüfe Antworten vor der Verwendung und gib keine Passwörter oder unnötigen personenbezogenen Daten ein.</p>
</section>
<h2 class="ticket-alternative-title">Problem nicht gelöst?</h2>
<p class="muted">Dann melde dein Anliegen direkt an die Schul-IT.</p>
<?php else: ?>
<p class="muted">Wähle, ob du Hilfe bei einem IT-Problem brauchst oder einen Defekt melden möchtest.</p>
<?php endif; ?>
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

<?php if ($faqReady): ?>
<section class="card public-faq" aria-labelledby="public-faq-title">
<h2 id="public-faq-title">Häufige Fragen</h2>
<p class="muted">Kurze Lösungen für wiederkehrende IT-Probleme aus bereits bearbeiteten Anfragen.</p>

<?php if ($publicFaq === []): ?>
<div class="notice">Noch wurden keine FAQ veröffentlicht.</div>
<?php else: ?>
<div class="faq-list">
<?php foreach ($publicFaq as $faq): ?>
<details class="faq-item">
<summary><?= app_escape((string)$faq['question']) ?></summary>
<div class="faq-answer">
<?php if (!empty($faq['category_name'])): ?><span class="label"><?= app_escape((string)$faq['category_name']) ?></span><?php endif; ?>
<p><?= nl2br(app_escape((string)$faq['answer'])) ?></p>
</div>
</details>
<?php endforeach; ?>
</div>
<?php endif; ?>

</section>
<?php endif; ?>

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

<label for="description">Problem / Frage *</label>
<textarea id="description" name="description" maxlength="5000" rows="6" required placeholder="Beschreibe dein Problem oder formuliere deine Frage möglichst konkret."></textarea>

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

<footer class="public-footer">
  <span>Schul-IT Ticketsystem</span>
  <a class="admin-entry" href="/admin/">Administration</a>
</footer>

<?php if ($assistantWidgetActive): ?>
<aside class="assistant-widget" aria-label="KI-Assistent">
<details id="assistant-widget">
  <summary aria-label="<?= app_escape((string)$assistant['label']) ?> öffnen"><svg class="assistant-bubble-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 4.5h14a2.5 2.5 0 0 1 2.5 2.5v8A2.5 2.5 0 0 1 19 17.5h-7.2L7 21v-3.5H5A2.5 2.5 0 0 1 2.5 15V7A2.5 2.5 0 0 1 5 4.5Z"/><path d="M7.5 9h9M7.5 13h6"/></svg></summary>
  <section class="assistant-widget-panel" aria-labelledby="assistant-widget-title">
    <div class="assistant-widget-heading">
      <div>
        <h2 id="assistant-widget-title"><?= app_escape((string)$assistant['label']) ?></h2>
      </div>
      <button type="button" id="assistant-widget-close" class="secondary" hidden>Schließen</button>
    </div>
    <p class="assistant-widget-note">KI kann Fehler machen. Bitte prüfe Antworten vor der Verwendung und gib keine Passwörter oder unnötigen personenbezogenen Daten ein.</p>
    <div id="assistant-widget-frame" data-chat-url="<?= app_escape((string)$assistant['widget_url']) ?>"></div>
    <noscript><p>Für die eingebettete Sprechblase ist JavaScript erforderlich. Der Assistent kann weiterhin über den normalen Link geöffnet werden.</p></noscript>
  </section>
</details>
</aside>
<script src="/assets/assistant-widget.js" defer></script>
<?php endif; ?>

</body>
</html>
