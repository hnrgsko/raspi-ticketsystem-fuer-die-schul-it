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
if (!is_string($step) || !in_array($step, ['start', 'school', 'admin', 'restore', 'recovery', 'backup', 'done'], true)) {
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
            header('Location: /?step=backup', true, 303);
            exit;
        }

        if ($action === 'register_backup_device') {
            $uuid = $_POST['device_uuid'] ?? null;
            if (!is_string($uuid) || $uuid === '') {
                throw new RuntimeException('Bitte einen USB-Datenträger auswählen.');
            }
            schulit_setupd([
                'action' => 'register_backup_device',
                'uuid' => $uuid,
            ]);
            $_SESSION['backup_registered'] = true;
            header('Location: /?step=backup', true, 303);
            exit;
        }

        if ($action === 'activate_backup_crypto') {
            $recoveryCodeInput = trim((string)($_POST['recovery_code'] ?? ''));
            if ($recoveryCodeInput === '') {
                throw new RuntimeException('Bitte den Recovery-Code eingeben.');
            }
            schulit_setupd([
                'action' => 'activate_backup_crypto',
                'recovery_code' => $recoveryCodeInput,
            ]);
            $created = schulit_setupd(['action' => 'create_backup']);
            $_SESSION['backup_message'] = 'Verschlüsselung eingerichtet und erstes Backup erfolgreich erstellt.';
            $_SESSION['last_backup'] = is_array($created['last_backup'] ?? null) ? $created['last_backup'] : [];
            header('Location: /?step=backup', true, 303);
            exit;
        }

        if ($action === 'create_backup') {
            $created = schulit_setupd(['action' => 'create_backup']);
            $_SESSION['backup_message'] = 'Backup erfolgreich erstellt.';
            $_SESSION['last_backup'] = is_array($created['last_backup'] ?? null) ? $created['last_backup'] : [];
            header('Location: /?step=backup', true, 303);
            exit;
        }

        if ($action === 'skip_backup') {
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
if ($initialized && !in_array($step, ['recovery', 'backup', 'done'], true)) {
    $step = 'done';
}

$backupDevices = [];
$backupStatus = ['configured' => false];
$backupMessage = is_string($_SESSION['backup_message'] ?? null) ? $_SESSION['backup_message'] : null;
unset($_SESSION['backup_message']);
if ($authorized && $initialized && $step === 'backup') {
    try {
        $devicesResponse = schulit_setupd(['action' => 'list_backup_devices']);
        $backupDevices = is_array($devicesResponse['devices'] ?? null) ? $devicesResponse['devices'] : [];
        $backupStatus = schulit_setupd(['action' => 'backup_status']);
    } catch (Throwable $backupError) {
        if ($error === null) {
            $error = $backupError->getMessage();
        }
    }
}

function step_class(string $current, string $candidate): string
{
    $order = ['start' => 1, 'school' => 2, 'admin' => 3, 'recovery' => 4, 'backup' => 5, 'done' => 6];
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
.progress{display:grid;grid-template-columns:repeat(6,1fr);gap:7px;margin:24px 0 30px}.progress div{height:6px;border-radius:99px;background:#e5e7eb}.progress .active,.progress .complete{background:#111827}
.options{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:24px}
.option{border:1px solid #d1d5db;border-radius:16px;padding:20px;background:#fff}.option h2{margin:0 0 8px;font-size:1.2rem}.option p{color:#6b7280;line-height:1.5;min-height:48px}
label{display:block;font-weight:750;margin-top:16px}input{width:100%;padding:12px 13px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;margin-top:7px}
button,.button{display:inline-block;padding:12px 17px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:750;font:inherit;cursor:pointer;text-decoration:none}.secondary{background:#fff;color:#111827;border:1px solid #cbd5e1}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.note{margin-top:20px;padding:15px;border-radius:12px;background:#f9fafb;line-height:1.55}.warning{background:#fff7ed;border:1px solid #fed7aa}.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.recovery{font:700 clamp(1rem,4vw,1.45rem)/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em;padding:18px;border:2px dashed #94a3b8;border-radius:14px;background:#f8fafc;word-break:break-all;margin:20px 0}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:20px}.summary>div{border:1px solid #e5e7eb;border-radius:12px;padding:14px}.key{font-size:.82rem;color:#6b7280}.value{font-weight:750;margin-top:4px}
.device{border:1px solid #d1d5db;border-radius:14px;padding:16px;margin-top:12px}.device-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.device-title{font-weight:800}.device-meta{color:#6b7280;font-size:.9rem;line-height:1.5;margin-top:6px}.device input[type=radio]{width:auto;margin:3px 8px 0 0}.device label{display:flex;align-items:flex-start;margin:0;cursor:pointer}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2ff;font-size:.78rem;font-weight:700;margin-left:6px}.pill.warn{background:#fff7ed}
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
  <div class="<?= step_class($step, 'backup') ?>"></div>
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


<?php elseif ($step === 'backup'): ?>
<h2>4. USB-Backup einrichten</h2>
<p class="sub">Wähle einen USB-Datenträger für automatische Sicherungen aus. Vorhandene Dateien bleiben vollständig erhalten. Das System formatiert oder leert den Datenträger nicht.</p>

<?php if (($backupStatus['configured'] ?? false) === true): ?>
<?php
$configuredBackup = is_array($backupStatus['backup'] ?? null) ? $backupStatus['backup'] : [];
$encryptionConfigured = ($backupStatus['encryption_configured'] ?? false) === true;
$lastBackup = is_array($backupStatus['last_backup'] ?? null) ? $backupStatus['last_backup'] : [];
if (!$lastBackup && is_array($_SESSION['last_backup'] ?? null)) {
    $lastBackup = $_SESSION['last_backup'];
}
?>
<div class="note">
<strong>Backupmedium eingerichtet ✓</strong><br>
<?= schulit_escape((string)($configuredBackup['label'] ?: $configuredBackup['model'] ?: 'USB-Datenträger')) ?><br>
Dateisystem: <?= schulit_escape((string)($configuredBackup['filesystem'] ?? '–')) ?><br>
Ordner: <code><?= schulit_escape((string)($configuredBackup['relative_path'] ?? '')) ?></code><br>
Status: <?= (($backupStatus['present'] ?? false) === true) ? 'angeschlossen' : 'derzeit nicht angeschlossen' ?>
</div>
<div class="note"><strong>Wichtig:</strong> Es wurden keine vorhandenen Dateien verändert. Das System arbeitet nur im Ordner <code>SchulIT-Ticketsystem/</code>.</div>

<?php if ($backupMessage !== null): ?>
<div class="note"><strong><?= schulit_escape($backupMessage) ?></strong></div>
<?php endif; ?>

<?php if (!$encryptionConfigured): ?>
<h3>Backup-Verschlüsselung aktivieren</h3>
<p class="sub">Das Backup wird mit einem eigenen age-Schlüssel verschlüsselt. Der private Entschlüsselungsschlüssel wird nicht offen auf dem USB-Stick gespeichert, sondern mit deinem Recovery-Code geschützt.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="activate_backup_crypto">
<label for="recovery_code_backup">Recovery-Code</label>
<input id="recovery_code_backup" type="password" name="recovery_code" required autocomplete="off" placeholder="Recovery-Code eingeben">
<div class="note warning">Für diese Testinstallation ist der Recovery-Code bereits einmal angezeigt worden. In einer späteren Produktivinstallation sollte er nur außerhalb des Raspberry Pi sicher aufbewahrt werden.</div>
<div class="actions"><button type="submit">Verschlüsselung aktivieren & erstes Backup erstellen</button></div>
</form>
<?php else: ?>
<div class="note"><strong>Backup-Verschlüsselung:</strong> aktiv ✓<br>Automatische tägliche Sicherung: vorbereitet/aktiviert</div>
<?php if ($lastBackup): ?>
<div class="summary">
  <div><div class="key">Letztes Backup</div><div class="value"><?= schulit_escape((string)($lastBackup['created_at'] ?? '–')) ?></div></div>
  <div><div class="key">Archiv</div><div class="value"><?= schulit_escape((string)($lastBackup['archive'] ?? '–')) ?></div></div>
  <div><div class="key">Größe</div><div class="value"><?= schulit_escape(schulit_format_bytes((int)($lastBackup['size_bytes'] ?? 0))) ?></div></div>
  <div><div class="key">Verschlüsselt</div><div class="value">ja ✓</div></div>
</div>
<?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="create_backup">
<div class="actions"><button type="submit">Backup jetzt erstellen</button><a class="button secondary" href="/?step=done">Weiter</a></div>
</form>
<?php endif; ?>

<?php else: ?>
<?php if (!$backupDevices): ?>
<div class="note warning"><strong>Kein geeigneter USB-Datenträger erkannt.</strong><br>Stecke einen USB-Stick ein und lade diese Seite anschließend neu.</div>
<div class="actions"><a class="button secondary" href="/?step=backup">Erneut suchen</a></div>
<?php else: ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="register_backup_device">

<?php foreach ($backupDevices as $device): ?>
<?php
$deviceSupported = ($device['supported'] ?? false) === true;
$deviceUuid = (string)($device['uuid'] ?? '');
$deviceLabel = trim((string)($device['label'] ?? ''));
$deviceModel = trim((string)($device['model'] ?? ''));
$deviceName = $deviceLabel !== '' ? $deviceLabel : ($deviceModel !== '' ? $deviceModel : 'USB-Datenträger');
?>
<div class="device">
<label>
<input type="radio" name="device_uuid" value="<?= schulit_escape($deviceUuid) ?>" <?= $deviceSupported ? '' : 'disabled' ?> required>
<span>
<span class="device-title"><?= schulit_escape($deviceName) ?></span>
<?php if (!$deviceSupported): ?><span class="pill warn">nicht verwendbar</span><?php endif; ?>
<div class="device-meta">
<?= schulit_escape(schulit_format_bytes((int)($device['size_bytes'] ?? 0))) ?> ·
<?= schulit_escape((string)($device['fstype'] ?? 'unbekannt')) ?> ·
UUID <?= schulit_escape($deviceUuid) ?>
<?php if (!empty($device['mountpoint'])): ?><br>Aktuell eingehängt unter <?= schulit_escape((string)$device['mountpoint']) ?><?php endif; ?>
<?php if (($device['read_only'] ?? false) === true): ?><br>Schreibgeschützt<?php endif; ?>
</div>
</span>
</label>
</div>
<?php endforeach; ?>

<div class="note"><strong>Was passiert bei „Backupmedium verwenden“?</strong><br>Es wird ausschließlich <code>SchulIT-Ticketsystem/Backups/<?= schulit_escape((string)($state['school_id'] ?? 'Schule')) ?>/</code> angelegt. Andere Dateien und Ordner bleiben unangetastet.</div>
<div class="actions"><button type="submit">Backupmedium verwenden</button><a class="button secondary" href="/?step=backup">Erneut suchen</a></div>
</form>
<?php endif; ?>

<form method="post" class="no-print">
<input type="hidden" name="csrf" value="<?= schulit_escape(schulit_csrf_token()) ?>">
<input type="hidden" name="action" value="skip_backup">
<div class="actions"><button type="submit" class="secondary">Backup später einrichten</button></div>
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
<?php
try {
    $doneBackupStatus = schulit_setupd(['action' => 'backup_status']);
} catch (Throwable) {
    $doneBackupStatus = ['configured' => false];
}
?>
<?php if (($doneBackupStatus['configured'] ?? false) === true): ?>
<div class="note"><strong>USB-Backupmedium:</strong> eingerichtet ✓<br>
Backup-Verschlüsselung: <?= (($doneBackupStatus['encryption_configured'] ?? false) === true) ? 'aktiv ✓' : 'noch nicht aktiviert' ?></div>
<div class="actions"><a class="button secondary" href="/?step=backup">Backup verwalten</a></div>
<?php else: ?>
<div class="note"><strong>Nächster Schritt:</strong> USB-Backupmedium erkennen und registrieren. Vorhandene Daten auf dem Stick bleiben erhalten.</div>
<div class="actions"><a class="button" href="/?step=backup">USB-Backup einrichten</a></div>
<?php endif; ?>

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
