<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

schulit_start_session();
schulit_headers();
schulit_try_bootstrap_token();

$authorized = schulit_authorized();
$error = null;
$phase1 = $authorized ? schulit_phase1_status() : [];
$setupStatus = $authorized ? schulit_setup_status() : ['initialized' => false];
$initialized = ($setupStatus['initialized'] ?? false) === true;
$state = is_array($setupStatus['state'] ?? null) ? $setupStatus['state'] : [];

$step = $_GET['step'] ?? 'start';
if (!is_string($step) || !in_array($step, ['start', 'school', 'admin', 'restore', 'recovery', 'done'], true)) {
    $step = 'start';
}

if ($authorized && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        schulit_require_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'new') {
            unset($_SESSION['setup_school'], $_SESSION['recovery_code'], $_SESSION['recovery_state']);
            header('Location: /?step=school', true, 303);
            exit;
        }

        if ($action === 'restore') {
            header('Location: /?step=restore', true, 303);
            exit;
        }

        if ($action === 'save_school') {
            $schoolName = schulit_school_name((string)($_POST['school_name'] ?? ''));
            $schoolId = schulit_school_id((string)($_POST['school_id'] ?? ''));
            $_SESSION['setup_school'] = [
                'school_name' => $schoolName,
                'school_id' => $schoolId,
            ];
            header('Location: /?step=admin', true, 303);
            exit;
        }

        if ($action === 'create_installation') {
            $school = $_SESSION['setup_school'] ?? null;
            if (!is_array($school)) {
                throw new RuntimeException('Bitte zuerst die Schuldaten eingeben.');
            }

            $displayName = schulit_admin_display_name((string)($_POST['admin_display_name'] ?? ''));
            $username = schulit_admin_username((string)($_POST['admin_username'] ?? ''));
            $passwordHash = schulit_admin_password(
                (string)($_POST['password'] ?? ''),
                (string)($_POST['password_confirmation'] ?? '')
            );

            $result = schulit_setupd([
                'action' => 'initialize',
                'payload' => [
                    'school_name' => (string)$school['school_name'],
                    'school_id' => (string)$school['school_id'],
                    'admin_display_name' => $displayName,
                    'admin_username' => $username,
                    'admin_password_hash' => $passwordHash,
                ],
            ]);

            $recoveryCode = $result['recovery_code'] ?? null;
            if (!is_string($recoveryCode) || $recoveryCode === '') {
                throw new RuntimeException('Recovery-Code wurde nicht zurückgegeben.');
            }
            $_SESSION['recovery_code'] = $recoveryCode;
            $_SESSION['recovery_state'] = is_array($result['state'] ?? null) ? $result['state'] : [];
            unset($_SESSION['setup_school']);
            header('Location: /?step=recovery', true, 303);
            exit;
        }

        if ($action === 'confirm_recovery') {
            if (!isset($_SESSION['recovery_code'])) {
                throw new RuntimeException('Der Recovery-Code ist nicht mehr in dieser Sitzung verfügbar.');
            }
            unset($_SESSION['recovery_code']);
            $_SESSION['recovery_confirmed'] = true;
            header('Location: /?step=done', true, 303);
            exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $caught) {
        $error = $caught->getMessage();
    }

    $setupStatus = schulit_setup_status();
    $initialized = ($setupStatus['initialized'] ?? false) === true;
    $state = is_array($setupStatus['state'] ?? null) ? $setupStatus['state'] : [];
}

$schoolDraft = is_array($_SESSION['setup_school'] ?? null) ? $_SESSION['setup_school'] : [];
$recoveryCode = is_string($_SESSION['recovery_code'] ?? null) ? $_SESSION['recovery_code'] : null;
if ($initialized && $step !== 'recovery' && $step !== 'done') {
    $step = 'done';
}

function step_class(string $current, string $candidate): string
{
    $order = ['start' => 1, 'school' => 2, 'admin' => 3, 'recovery' => 4, 'done' => 5];
    $currentValue = $order[$current] ?? 1;
    $candidateValue = $order[$candidate] ?? 1;
    if ($candidateValue < $currentValue) return 'complete';
    if ($candidateValue === $currentValue) return 'active';
    return '';
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schul-IT Ticketsystem – Einrichtung</title>
<style>
:root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2937;background:#f3f4f6}
*{box-sizing:border-box}body{margin:0}.wrap{max-width:920px;margin:0 auto;padding:32px 18px 64px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:28px;box-shadow:0 8px 30px rgba(0,0,0,.05)}
h1{font-size:clamp(1.9rem,5vw,2.8rem);margin:.2em 0}.sub{color:#6b7280;line-height:1.6;max-width:760px}
.badge{display:inline-block;border-radius:999px;padding:6px 11px;background:#eef2ff;font-weight:700;font-size:.85rem}
.progress{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;margin:24px 0 30px}.progress div{height:6px;border-radius:99px;background:#e5e7eb}.progress .active,.progress .complete{background:#111827}
.options{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:24px}
.option{border:1px solid #d1d5db;border-radius:16px;padding:20px;background:#fff}.option h2{margin:0 0 8px;font-size:1.2rem}.option p{color:#6b7280;line-height:1.5;min-height:48px}
label{display:block;font-weight:750;margin-top:16px}input{width:100%;padding:12px 13px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;margin-top:7px}
button,.button{display:inline-block;padding:12px 17px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:750;font:inherit;cursor:pointer;text-decoration:none}.secondary{background:#fff;color:#111827;border:1px solid #cbd5e1}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.note{margin-top:20px;padding:15px;border-radius:12px;background:#f9fafb;line-height:1.55}.warning{background:#fff7ed;border:1px solid #fed7aa}.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.recovery{font:700 clamp(1rem,4vw,1.45rem)/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em;padding:18px;border:2px dashed #94a3b8;border-radius:14px;background:#f8fafc;word-break:break-all;margin:20px 0}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:20px}.summary>div{border:1px solid #e5e7eb;border-radius:12px;padding:14px}.key{font-size:.82rem;color:#6b7280}.value{font-weight:750;margin-top:4px}
details{margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px}summary{cursor:pointer;font-weight:700}code{word-break:break-word}
@media print{body{background:#fff}.no-print,.progress,details{display:none}.card{box-shadow:none;border:0}.recovery{font-size:20pt}}
</style>
</head>
<body><main class="wrap"><section class="card">
<span class="badge">Einrichtungsassistent</span>
<h1>Schul-IT Ticketsystem</h1>

<?php if (!$authorized): ?>
<p class="sub">Die Serverbasis ist erreichbar. Für die lokale Ersteinrichtung wird der Setup-Token benötigt.</p>
<form method="get" action="/">
<label for="token">Setup-Token</label>
<input id="token" name="token" autocomplete="off" inputmode="text" required placeholder="48-stelliger Token">
<div class="actions"><button type="submit">Einrichtung öffnen</button></div>
</form>
<div class="note">Token auf dem Raspberry Pi anzeigen:<br><code>sudo cat /var/lib/schulit/setup/bootstrap-token</code></div>

<?php else: ?>

<?php if ($step !== 'restore'): ?>
<div class="progress no-print" aria-label="Einrichtungsfortschritt">
  <div class="<?= step_class($step, 'start') ?>"></div>
  <div class="<?= step_class($step, 'school') ?>"></div>
  <div class="<?= step_class($step, 'admin') ?>"></div>
  <div class="<?= step_class($step, 'recovery') ?>"></div>
  <div class="<?= step_class($step, 'done') ?>"></div>
</div>
<?php endif; ?>

<?php if (is_string($error) && $error !== ''): ?>
<div class="note error"><strong>Das hat noch nicht funktioniert:</strong><br><?= schulit_escape($error) ?></div>
<?php endif; ?>

<?php if (($setupStatus['ok'] ?? true) !== true && isset($setupStatus['error'])): ?>
<div class="note error">Der lokale Setup-Systemdienst ist nicht erreichbar: <?= schulit_escape((string)$setupStatus['error']) ?></div>
<?php endif; ?>

<?php if ($step === 'start'): ?>
<h2>Willkommen</h2>
<p class="sub">Wie möchtest du dieses System einrichten? Für eine neue Schule sind nur wenige Angaben nötig. Technische Einstellungen übernimmt der Installer automatisch.</p>
<div class="options">
  <div class="option">
    <h2>Neue Installation</h2>
    <p>Ein neues Ticketsystem mit Schulkennung und erstem System-Administrator einrichten.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
      <input type="hidden" name="action" value="new">
      <button type="submit">Neue Installation starten</button>
    </form>
  </div>
  <div class="option">
    <h2>Aus Backup wiederherstellen</h2>
    <p>Eine vorhandene Schul-IT-Installation auf diesem Raspberry Pi übernehmen.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
      <input type="hidden" name="action" value="restore">
      <button type="submit" class="secondary">Backup verwenden</button>
    </form>
  </div>
</div>

<?php elseif ($step === 'restore'): ?>
<h2>Aus Backup wiederherstellen</h2>
<p class="sub">Dieser Einstieg ist bereits fest vorgesehen. Im nächsten Backup-Baustein erkennt der Assistent USB-Datenträger und lässt eine vorhandene Sicherung auswählen.</p>
<div class="note warning"><strong>Noch nicht aktiv:</strong> Bitte für den Moment keine Schuldaten auf diesem Weg wiederherstellen. Das portable Backupformat und die USB-Auswahl werden als nächster Systembaustein implementiert.</div>
<div class="actions"><a class="button secondary" href="/">Zurück</a></div>

<?php elseif ($step === 'school'): ?>
<h2>1. Schule</h2>
<p class="sub">Diese Angaben identifizieren die Installation. Die Schulnummer wird später auch als sichtbarer Teil des Recovery-Codes verwendet; sie ist selbst kein Geheimnis.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="save_school">
<label for="school_name">Name der Schule</label>
<input id="school_name" name="school_name" maxlength="150" required autofocus value="<?= schulit_escape((string)($schoolDraft['school_name'] ?? '')) ?>" placeholder="z. B. Musterschule Musterstadt">
<label for="school_id">Schulnummer / Schulkennung</label>
<input id="school_id" name="school_id" maxlength="32" required value="<?= schulit_escape((string)($schoolDraft['school_id'] ?? '')) ?>" placeholder="z. B. 123456">
<div class="note">Erlaubt sind Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich.</div>
<div class="actions"><a class="button secondary" href="/">Zurück</a><button type="submit">Weiter</button></div>
</form>

<?php elseif ($step === 'admin'): ?>
<?php if (!$schoolDraft): ?>
<h2>Schuldaten fehlen</h2>
<p class="sub">Bitte zuerst die Schule eintragen.</p>
<div class="actions"><a class="button" href="/?step=school">Zur Schuleingabe</a></div>
<?php else: ?>
<h2>2. Erster System-Administrator</h2>
<p class="sub">Dieses Konto darf später sowohl Tickets verwalten als auch Updates, Backups, Domain und Servereinstellungen administrieren.</p>
<div class="note"><strong><?= schulit_escape((string)$schoolDraft['school_name']) ?></strong><br>Schulkennung: <?= schulit_escape((string)$schoolDraft['school_id']) ?></div>
<form method="post">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="create_installation">
<label for="admin_display_name">Anzeigename</label>
<input id="admin_display_name" name="admin_display_name" maxlength="100" required autofocus placeholder="Vor- und Nachname">
<label for="admin_username">Benutzername</label>
<input id="admin_username" name="admin_username" maxlength="100" required autocomplete="username" placeholder="z. B. admin">
<label for="password">Passwort</label>
<input id="password" type="password" name="password" minlength="14" maxlength="72" required autocomplete="new-password">
<label for="password_confirmation">Passwort wiederholen</label>
<input id="password_confirmation" type="password" name="password_confirmation" minlength="14" maxlength="72" required autocomplete="new-password">
<div class="note">Mindestens 14 Zeichen. Das Passwort wird ausschließlich als sicherer Passwort-Hash gespeichert.</div>
<div class="actions"><a class="button secondary" href="/?step=school">Zurück</a><button type="submit">Installation anlegen</button></div>
</form>
<?php endif; ?>

<?php elseif ($step === 'recovery'): ?>
<h2>3. Recovery-Code sichern</h2>
<?php if ($recoveryCode === null): ?>
<p class="sub">Der Klartext-Recovery-Code ist in dieser Browsersitzung nicht mehr verfügbar. Auf dem Raspberry Pi ist absichtlich nur sein sicherer Prüfwert gespeichert.</p>
<div class="note warning">Wenn du den Code noch nicht gesichert hast, sollten wir vor dem produktiven Betrieb einen kontrollierten neuen Recovery-Code erzeugen.</div>
<div class="actions"><a class="button" href="/?step=done">Zum Status</a></div>
<?php else: ?>
<p class="sub">Dieser Code ist der Notfallzugang für spätere Recovery-Funktionen. Speichere ihn jetzt außerhalb des Raspberry Pi oder drucke diese Seite aus.</p>
<div class="recovery"><?= schulit_escape($recoveryCode) ?></div>
<div class="note warning"><strong>Wichtig:</strong> Der vollständige Code wird auf dem Raspberry Pi nicht im Klartext gespeichert und nach diesem Schritt nicht erneut angezeigt.</div>
<form method="post" class="no-print">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="confirm_recovery">
<div class="actions"><button type="submit">Ich habe den Recovery-Code sicher gespeichert</button></div>
</form>
<?php endif; ?>

<?php elseif ($step === 'done'): ?>
<?php $finalState = $state ?: (is_array($_SESSION['recovery_state'] ?? null) ? $_SESSION['recovery_state'] : []); ?>
<h2>Grundkonfiguration abgeschlossen</h2>
<p class="sub">Die lokale Datenbank, die Schule und der erste System-Administrator sind eingerichtet. Als nächstes folgt die USB-Backup-Routine.</p>
<div class="summary">
  <div><div class="key">Schule</div><div class="value"><?= schulit_escape((string)($finalState['school_name'] ?? 'eingerichtet')) ?></div></div>
  <div><div class="key">Schulkennung</div><div class="value"><?= schulit_escape((string)($finalState['school_id'] ?? '–')) ?></div></div>
  <div><div class="key">System-Admin</div><div class="value"><?= schulit_escape((string)($finalState['admin_username'] ?? 'eingerichtet')) ?></div></div>
  <div><div class="key">Datenbank</div><div class="value"><?= schulit_escape((string)($finalState['database'] ?? 'schulit')) ?></div></div>
</div>
<div class="note"><strong>Nächster Schritt:</strong> USB-Backupmedium erkennen, registrieren und eine sichere automatische Backup-/Restore-Routine vorbereiten.</div>

<?php endif; ?>

<details>
<summary>Technische Details anzeigen</summary>
<div class="summary">
  <div><div class="key">Webserver</div><div class="value"><?= schulit_escape((string)($phase1['apache'] ?? 'bereit')) ?></div></div>
  <div><div class="key">PHP</div><div class="value"><?= schulit_escape((string)($phase1['php'] ?? PHP_VERSION)) ?></div></div>
  <div><div class="key">MariaDB</div><div class="value"><?= schulit_escape((string)($phase1['mariadb'] ?? 'bereit')) ?></div></div>
  <div><div class="key">Systemcheck</div><div class="value"><?= schulit_escape((string)($phase1['verified_at'] ?? 'erfolgreich')) ?></div></div>
</div>
</details>
<?php endif; ?>
</section></main></body></html>
