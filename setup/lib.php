<?php
declare(strict_types=1);

const SCHULIT_TOKEN_HASH_FILE = '/var/lib/schulit/setup/token.sha256';
const SCHULIT_STATUS_FILE = '/var/lib/schulit/setup/status.json';
const SCHULIT_SETUP_SOCKET = '/run/schulit/setupd.sock';

function schulit_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function schulit_start_session(): void
{
    session_name('schulit_setup');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function schulit_headers(): void
{
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

function schulit_try_bootstrap_token(): void
{
    $expectedHash = is_readable(SCHULIT_TOKEN_HASH_FILE)
        ? trim((string) file_get_contents(SCHULIT_TOKEN_HASH_FILE))
        : '';
    $provided = $_GET['token'] ?? null;

    if (!is_string($provided) || preg_match('/\A[a-f0-9]{48}\z/', $provided) !== 1 || $expectedHash === '') {
        return;
    }

    if (hash_equals($expectedHash, hash('sha256', $provided))) {
        session_regenerate_id(true);
        $_SESSION['setup_authorized'] = true;
        header('Location: /', true, 303);
        exit;
    }
}

function schulit_authorized(): bool
{
    return ($_SESSION['setup_authorized'] ?? false) === true;
}

function schulit_csrf_token(): string
{
    $value = $_SESSION['csrf'] ?? null;
    if (!is_string($value) || strlen($value) !== 64) {
        $value = bin2hex(random_bytes(32));
        $_SESSION['csrf'] = $value;
    }
    return $value;
}

function schulit_require_csrf(): void
{
    $sent = $_POST['csrf'] ?? null;
    $expected = $_SESSION['csrf'] ?? null;
    if (!is_string($sent) || !is_string($expected) || !hash_equals($expected, $sent)) {
        throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
    }
}

function schulit_setupd(array $request): array
{
    $socket = @stream_socket_client(
        'unix://' . SCHULIT_SETUP_SOCKET,
        $errno,
        $error,
        3.0,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        throw new RuntimeException('Der lokale Setup-Systemdienst ist nicht erreichbar.');
    }

    stream_set_timeout($socket, 130);
    $encoded = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fwrite($socket, $encoded . "\n");

    $response = '';
    while (!feof($socket) && strlen($response) <= 131072) {
        $chunk = fgets($socket, 8192);
        if ($chunk === false) {
            break;
        }
        $response .= $chunk;
        if (str_ends_with($response, "\n")) {
            break;
        }
    }
    fclose($socket);

    if ($response === '' || strlen($response) > 131072) {
        throw new RuntimeException('Ungültige Antwort des Setup-Systemdienstes.');
    }

    $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültige Antwort des Setup-Systemdienstes.');
    }
    if (($decoded['ok'] ?? false) !== true) {
        $message = $decoded['error'] ?? 'Setup-Aktion fehlgeschlagen.';
        throw new RuntimeException(is_string($message) ? $message : 'Setup-Aktion fehlgeschlagen.');
    }
    return $decoded;
}

function schulit_setup_status(): array
{
    try {
        return schulit_setupd(['action' => 'status']);
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'initialized' => false,
            'error' => $error->getMessage(),
        ];
    }
}

function schulit_phase1_status(): array
{
    if (!is_readable(SCHULIT_STATUS_FILE)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents(SCHULIT_STATUS_FILE), true);
    return is_array($decoded) ? $decoded : [];
}

function schulit_school_name(string $value): string
{
    $value = trim($value);
    if (mb_strlen($value) < 2 || mb_strlen($value) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException('Bitte einen Schulnamen mit 2–150 Zeichen eingeben.');
    }
    return $value;
}

function schulit_school_id(string $value): string
{
    $value = trim($value);
    if (preg_match('/\A[A-Za-z0-9._-]{2,32}\z/', $value) !== 1) {
        throw new InvalidArgumentException('Schulnummer / Schulkennung: 2–32 Zeichen; erlaubt sind Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich.');
    }
    return $value;
}

function schulit_admin_username(string $value): string
{
    $value = trim($value);
    if (preg_match('/\A[A-Za-z0-9._-]{3,100}\z/', $value) !== 1) {
        throw new InvalidArgumentException('Benutzername: 3–100 Buchstaben, Ziffern, Punkte, Unterstriche oder Bindestriche.');
    }
    return $value;
}

function schulit_admin_display_name(string $value): string
{
    $value = trim($value);
    if (mb_strlen($value) < 1 || mb_strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new InvalidArgumentException('Bitte einen Anzeigenamen mit 1–100 Zeichen eingeben.');
    }
    return $value;
}

function schulit_admin_password(string $password, string $confirmation): string
{
    $bytes = strlen($password);
    if ($bytes < 14 || $bytes > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Das Passwort muss mindestens 14 Zeichen und höchstens 72 UTF-8-Bytes lang sein.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('Die beiden Passwörter stimmen nicht überein.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Das Passwort konnte nicht sicher gespeichert werden.');
    }
    return $hash;
}


function schulit_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return 'unbekannt';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return ($index >= 3 ? number_format($value, 1, ',', '.') : number_format($value, 0, ',', '.')) . ' ' . $units[$index];
}
