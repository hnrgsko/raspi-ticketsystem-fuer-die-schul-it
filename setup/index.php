<?php
declare(strict_types=1);

const TOKEN_HASH_FILE = '/var/lib/schulit/setup/token.sha256';
const STATUS_FILE = '/var/lib/schulit/setup/status.json';

session_name('schulit_setup');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$expectedHash = is_readable(TOKEN_HASH_FILE) ? trim((string) file_get_contents(TOKEN_HASH_FILE)) : '';
$provided = $_GET['token'] ?? null;

if (is_string($provided) && preg_match('/\A[a-f0-9]{48}\z/', $provided) === 1 && $expectedHash !== '') {
    $providedHash = hash('sha256', $provided);
    if (hash_equals($expectedHash, $providedHash)) {
        session_regenerate_id(true);
        $_SESSION['setup_authorized'] = true;
        header('Location: /', true, 303);
        exit;
    }
}

$authorized = ($_SESSION['setup_authorized'] ?? false) === true;

$status = [];
if ($authorized && is_readable(STATUS_FILE)) {
    $decoded = json_decode((string) file_get_contents(STATUS_FILE), true);
    if (is_array($decoded)) {
        $status = $decoded;
    }
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schul-IT Ticketsystem – Einrichtung</title>
<style>
:root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2937;background:#f3f4f6}
*{box-sizing:border-box}body{margin:0}.wrap{max-width:820px;margin:0 auto;padding:32px 18px 64px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:26px;box-shadow:0 8px 30px rgba(0,0,0,.05)}
h1{font-size:clamp(1.8rem,5vw,2.7rem);margin:.2em 0}.sub{color:#6b7280;line-height:1.6}
.badge{display:inline-block;border-radius:999px;padding:6px 11px;background:#eef2ff;font-weight:700;font-size:.85rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-top:22px}
.item{border:1px solid #e5e7eb;border-radius:12px;padding:14px}.ok{font-weight:800}.muted{color:#6b7280;font-size:.92rem}
input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;margin:8px 0 12px}
button{padding:12px 16px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:700;font:inherit;cursor:pointer}
.note{margin-top:20px;padding:14px;border-radius:12px;background:#f9fafb;line-height:1.55}
code{word-break:break-word}
</style>
</head>
<body><main class="wrap"><section class="card">
<span class="badge">Technische Phase 1</span>
<h1>Schul-IT Ticketsystem</h1>
<?php if (!$authorized): ?>
<p class="sub">Die Serverbasis ist erreichbar. Für die weitere Einrichtung wird der einmalige lokale Setup-Token benötigt.</p>
<form method="get" action="/">
<label for="token"><strong>Setup-Token</strong></label>
<input id="token" name="token" autocomplete="off" inputmode="text" required placeholder="48-stelliger Token">
<button type="submit">Einrichtung öffnen</button>
</form>
<div class="note">Token auf dem Raspberry Pi anzeigen:<br><code>sudo cat /var/lib/schulit/setup/bootstrap-token</code></div>
<?php else: ?>
<p class="sub">Die technische Serverbasis wurde installiert. Noch sind keine Schule, Ticketdatenbank, Domain oder Tunnelverbindung eingerichtet.</p>
<div class="grid">
  <div class="item"><div class="ok">✓ Webserver</div><div class="muted"><?= esc((string)($status['apache'] ?? 'Apache bereit')) ?></div></div>
  <div class="item"><div class="ok">✓ PHP</div><div class="muted"><?= esc((string)($status['php'] ?? PHP_VERSION)) ?></div></div>
  <div class="item"><div class="ok">✓ MariaDB</div><div class="muted"><?= esc((string)($status['mariadb'] ?? 'lokal bereit')) ?></div></div>
  <div class="item"><div class="ok">✓ Systemcheck</div><div class="muted"><?= esc((string)($status['verified_at'] ?? 'erfolgreich')) ?></div></div>
</div>
<div class="note"><strong>Nächster Entwicklungsschritt:</strong> Aus dieser Seite wird der geführte Einrichtungsassistent für Schule, Administrator, Datenbank, Backup, Domain und Cloudflare.</div>
<?php endif; ?>
</section></main></body></html>
