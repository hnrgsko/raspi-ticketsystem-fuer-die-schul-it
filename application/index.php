<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

app_security_headers();

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
            $created = app_ticket_create($db, $_POST, $type);
            $successTicket = [
                'id' => (string)$created['id'],
                'number' => app_ticket_number((string)$created['id']),
                'status_code' => (string)$created['status_code'],
            ];
        } elseif ($action === 'lookup_ticket') {
            if (!app_session_rate($_SESSION, 'lookup', 30, 300)) {
                throw new RuntimeException('Zu viele Statusabfragen. Bitte einige Minuten warten.');
            }
            $statusResult = app_ticket_lookup(
                $db,
                $_POST['ticket_number'] ?? null,
                $_POST['status_code'] ?? null
            );
            if ($statusResult === null) {
                throw new RuntimeException('Kein passendes Ticket gefunden. Bitte Ticketnummer und Statuscode prüfen.');
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
<body<?= $authorized ? ' data-usage-csrf="' . app_escape($csrf) . '"' : '' ?>>
<main class="shell">
<header>
  <div>
    <div class="brand">Schul-IT Ticketsystem</div>
    <div class="school"><?= app_escape($schoolName) ?></div>
  </div>
</header>

<?php if ($authorized && $ready && $type === ''): ?>
<nav class="public-anchor-nav" aria-label="Schnellnavigation auf dieser Seite">
  <?php if (($assistant['enabled'] ?? false) && ($assistant['url'] ?? '') !== ''): ?><a class="anchor-assistant" href="#assistant-section">KI-Assistent</a><?php endif; ?>
  <a class="anchor-ticket" href="#ticket-section">Ticket aufgeben</a>
  <?php if ($faqReady): ?><a class="anchor-faq" href="#faq-section">FAQ</a><?php endif; ?>
  <a class="anchor-status" href="#ticketstatus-section">Ticketstatus</a>
</nav>
<?php endif; ?>

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
<div class="ticket-status-code">
  <span>Statuscode</span>
  <strong><?= app_escape($successTicket['status_code']) ?></strong>
</div>
<p>Für die spätere Statusabfrage brauchst du <strong>Ticketnummer und Statuscode</strong>. Bitte beide Angaben speichern. Der Statuscode wird aus Sicherheitsgründen nicht erneut angezeigt.</p>
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
<section class="assistant-intro landing-zone landing-zone-assistant page-anchor-target" id="assistant-section" aria-labelledby="assistant-title">
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
        loading="eager"
        data-usage-focus="assistant_inline_use"></iframe>
    </div>
    <div class="assistant-inline-actions">
      <a class="button secondary" data-usage-event="assistant_external_open" href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer">Extern öffnen</a>
    </div>
  </div>
  <?php else: ?>
  <a class="button assistant-start" data-usage-event="assistant_external_open" href="<?= app_escape((string)$assistant['url']) ?>" target="_blank" rel="noopener noreferrer">KI-Assistent starten</a>
  <?php endif; ?>
  <p class="assistant-disclosure"><strong>Hinweis:</strong> KI kann Fehler machen. Prüfe Antworten vor der Verwendung und gib keine Passwörter oder unnötigen personenbezogenen Daten ein.</p>
</section>
<h2 class="ticket-alternative-title">Problem nicht gelöst?</h2>
<p class="muted">Dann melde dein Anliegen direkt an die Schul-IT.</p>
<?php else: ?>
<p class="muted">Wähle, ob du Hilfe bei einem IT-Problem brauchst oder einen Defekt melden möchtest.</p>
<?php endif; ?>
<section class="landing-zone landing-zone-ticket page-anchor-target" id="ticket-section" aria-labelledby="ticket-section-title">
  <div class="landing-zone-heading">
    <span class="landing-zone-kicker">Schul-IT kontaktieren</span>
    <h2 id="ticket-section-title">Ticket aufgeben</h2>
  </div>
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
</section>

<?php if ($faqReady): ?>
<section class="card public-faq landing-zone landing-zone-faq page-anchor-target" id="faq-section" aria-labelledby="public-faq-title">
<h2 id="public-faq-title">Häufige Fragen</h2>
<p class="muted">Kurze Lösungen für wiederkehrende IT-Probleme aus bereits bearbeiteten Anfragen.</p>

<?php if ($publicFaq === []): ?>
<div class="notice">Noch wurden keine FAQ veröffentlicht.</div>
<?php else: ?>
<div class="faq-list">
<?php foreach ($publicFaq as $faq): ?>
<details class="faq-item" data-usage-open="faq_public_open">
<summary><span class="faq-question-text"><?= app_escape((string)$faq['question']) ?></span></summary>
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

<section class="card landing-zone landing-zone-status page-anchor-target" id="ticketstatus-section">
<h2>Ticketstatus prüfen</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?= app_escape($csrf) ?>">
<input type="hidden" name="action" value="lookup_ticket">
<div class="grid">
  <div><label for="ticket_number">Ticketnummer</label><input id="ticket_number" name="ticket_number" required maxlength="30" placeholder="#000001"></div>
  <div><label for="status_code">Statuscode</label><input id="status_code" name="status_code" required maxlength="64" autocomplete="off" autocapitalize="characters" placeholder="7K4M-P9Q2"></div>
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

<form method="post" action="/?type=<?= app_escape($type) ?>"<?= $type === 'support' ? ' data-support-ticket-form' : '' ?>>
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

<?php if ($type === 'support' && $faqReady): ?>
<section id="faq-ticket-suggestions" class="faq-ticket-suggestions" hidden aria-live="polite">
  <span class="label">Vielleicht schon gelöst</span>
  <h2>Passt eine dieser Lösungen?</h2>
  <p class="muted">Die Vorschläge werden nur lokal aus den veröffentlichten FAQ dieser Schule ermittelt.</p>
  <p class="faq-search-status muted" data-faq-search-status hidden></p>
  <div data-faq-suggestion-list class="faq-ticket-suggestion-list"></div>
  <p class="success faq-solved-message" data-faq-solved hidden><strong>Super.</strong> Wenn das Problem damit gelöst ist, musst du dieses Ticket nicht absenden.</p>
</section>
<?php endif; ?>

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

<?php if ($authorized): ?><script src="/assets/usage.js" defer></script><?php endif; ?>
<?php if ($authorized && $ready && $type === 'support' && $faqReady): ?><script src="/assets/faq-suggest.js" defer></script><?php endif; ?>

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
