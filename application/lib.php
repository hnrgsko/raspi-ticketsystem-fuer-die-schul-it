<?php
declare(strict_types=1);

const SCHULIT_APP_CONFIG = '/etc/schulit/app.php';
const SCHULIT_ACCESS_TOKEN = '/etc/schulit/access-token';
const SCHULIT_PUBLIC_SESSIONS = '/var/lib/schulit/sessions';
const SCHULIT_ADMIN_SESSIONS = '/var/lib/schulit/admin-sessions';

function app_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') === 'on') return true;
    $forwarded = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $forwarded === 'https';
}

function app_start_session(string $directory, string $name): void
{
    if (!is_dir($directory)) {
        throw new RuntimeException('Sitzungsspeicher ist nicht verfügbar.');
    }
    session_save_path($directory);
    session_name($name);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!session_start()) {
        throw new RuntimeException('Sitzung konnte nicht gestartet werden.');
    }
}

function app_database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_readable(SCHULIT_APP_CONFIG)) {
        throw new RuntimeException('Das System ist noch nicht vollständig eingerichtet.');
    }
    $config = require SCHULIT_APP_CONFIG;
    if (!is_array($config) || !is_array($config['database'] ?? null)) {
        throw new RuntimeException('Ungültige Anwendungskonfiguration.');
    }
    $db = $config['database'];
    $host = (string)($db['host'] ?? '');
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $password = (string)($db['password'] ?? '');
    $port = (int)($db['port'] ?? 3306);

    if (!preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)
        || !preg_match('/\A[a-zA-Z0-9_]+\z/', $name)
        || $user === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('Ungültige Datenbankkonfiguration.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')");
    return $pdo;
}

function app_tables_ready(PDO $db): bool
{
    try {
        $db->query('SELECT id FROM categories LIMIT 0');
        $db->query('SELECT id FROM tickets LIMIT 0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function app_setting(PDO $db, string $key, string $fallback = ''): string
{
    $q = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=:key');
    $q->execute(['key' => $key]);
    $value = $q->fetchColumn();
    return is_string($value) ? $value : $fallback;
}

function app_csrf(array &$session, string $key = 'csrf'): string
{
    if (!is_string($session[$key] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $session[$key]) !== 1) {
        $session[$key] = bin2hex(random_bytes(32));
    }
    return $session[$key];
}

function app_csrf_valid(array $session, mixed $token, string $key = 'csrf'): bool
{
    return is_string($token)
        && is_string($session[$key] ?? null)
        && hash_equals($session[$key], $token);
}

function app_try_public_token(): void
{
    $provided = $_GET['access'] ?? null;
    if (!is_string($provided) || strlen($provided) > 200 || !is_readable(SCHULIT_ACCESS_TOKEN)) return;
    $expected = trim((string)file_get_contents(SCHULIT_ACCESS_TOKEN));
    if ($expected !== '' && hash_equals($expected, $provided)) {
        session_regenerate_id(true);
        $_SESSION['public_access'] = true;
        header('Location: /', true, 303);
        exit;
    }
}

function app_public_authorized(): bool
{
    return ($_SESSION['public_access'] ?? false) === true;
}

function app_categories(PDO $db, bool $includeHardware = true): array
{
    $sql = 'SELECT id,code,name FROM categories WHERE is_active=1';
    if (!$includeHardware) $sql .= " AND code<>'hardware'";
    $sql .= ' ORDER BY sort_order,id';
    return $db->query($sql)->fetchAll();
}

function app_text(mixed $value, int $max, bool $required, string $label): string
{
    if (!is_string($value) || strlen($value) > $max * 4
        || preg_match('/\A.{0,' . $max . '}\z/us', $value) !== 1
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException($label . ': höchstens ' . $max . ' gültige Zeichen.');
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException($label . ': Bitte ausfüllen.');
    }
    return $value;
}

function app_ticket_create(PDO $db, array $input, string $type): string
{
    if (!in_array($type, ['support', 'defect'], true)) {
        throw new InvalidArgumentException('Ungültige Ticketart.');
    }

    $name = app_text($input['reporter_name'] ?? '', 100, true, 'Name');
    $abbr = app_text($input['reporter_abbreviation'] ?? '', 20, true, 'Kürzel');
    $location = app_text($input['location'] ?? '', 150, true, 'Raum / Ort');
    $device = app_text($input['device'] ?? '', 255, true, 'Gerät / System');
    $description = app_text($input['description'] ?? '', 5000, true, 'Problembeschreibung');
    $occurrence = app_text($input['occurrence_details'] ?? '', 2000, false, 'Seit wann');
    $inventory = app_text($input['inventory_number'] ?? '', 100, false, 'Inventarnummer');
    $serial = app_text($input['serial_number'] ?? '', 100, false, 'Seriennummer');
    $defectSubject = $type === 'defect'
        ? app_text($input['defect_subject'] ?? '', 255, true, 'Was ist defekt?')
        : null;

    if ($type === 'defect') {
        $q = $db->query("SELECT id FROM categories WHERE code='hardware' AND is_active=1 LIMIT 1");
        $categoryId = $q->fetchColumn();
    } else {
        $categoryRaw = $input['category_id'] ?? '';
        if (!is_string($categoryRaw) || preg_match('/\A[1-9][0-9]{0,18}\z/', $categoryRaw) !== 1) {
            throw new InvalidArgumentException('Bitte eine Kategorie auswählen.');
        }
        $q = $db->prepare("SELECT id FROM categories WHERE id=:id AND is_active=1 AND code<>'hardware'");
        $q->execute(['id' => $categoryRaw]);
        $categoryId = $q->fetchColumn();
    }
    if ($categoryId === false) {
        throw new InvalidArgumentException('Die gewählte Kategorie ist nicht verfügbar.');
    }

    $db->beginTransaction();
    try {
        $insert = $db->prepare(
            'INSERT INTO tickets
            (type,reporter_name,reporter_abbreviation,category_id,location,device,defect_subject,
             inventory_number,serial_number,description,occurrence_details,priority,status,status_changed_at)
             VALUES
            (:type,:name,:abbr,:category,:location,:device,:defect,
             :inventory,:serial,:description,:occurrence,:priority,\'new\',UTC_TIMESTAMP(6))'
        );
        $insert->execute([
            'type' => $type,
            'name' => $name,
            'abbr' => $abbr,
            'category' => $categoryId,
            'location' => $location,
            'device' => $device,
            'defect' => $defectSubject,
            'inventory' => $inventory === '' ? null : $inventory,
            'serial' => $serial === '' ? null : $serial,
            'description' => $description,
            'occurrence' => $occurrence === '' ? null : $occurrence,
            'priority' => $type === 'defect' ? 'high' : 'normal',
        ]);
        $id = (string)$db->lastInsertId();
        $queue = $db->prepare('UPDATE tickets SET queue_position=:position WHERE id=:id');
        $queue->execute(['position' => $id, 'id' => $id]);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function app_ticket_number(string $id): string
{
    return '#' . str_pad($id, 6, '0', STR_PAD_LEFT);
}

function app_status_label(string $status): string
{
    return [
        'new' => 'Neu',
        'in_progress' => 'In Bearbeitung',
        'awaiting_reply' => 'Rückfrage',
        'done' => 'Erledigt',
    ][$status] ?? $status;
}

function app_priority_label(string $priority): string
{
    return ['low' => 'Niedrig', 'normal' => 'Normal', 'high' => 'Hoch'][$priority] ?? $priority;
}

function app_local_time(?string $utc): string
{
    if ($utc === null || $utc === '') return '–';
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Berlin'))
            ->format('d.m.Y H:i');
    } catch (Throwable) {
        return $utc;
    }
}

function app_ticket_lookup(PDO $db, mixed $number, mixed $schoolCode): ?array
{
    if (!is_string($number) || !is_string($schoolCode)) return null;
    $id = ltrim(ltrim(trim($number), '#'), '0');
    if (!preg_match('/\A[1-9][0-9]{0,19}\z/', $id)) return null;

    $expected = app_setting($db, 'school_id');
    if ($expected === '' || !hash_equals(mb_strtolower($expected), mb_strtolower(trim($schoolCode)))) {
        return null;
    }
    $q = $db->prepare('SELECT id,status,created_at,status_changed_at FROM tickets WHERE id=:id AND archived_at IS NULL');
    $q->execute(['id' => $id]);
    $row = $q->fetch();
    return $row === false ? null : $row;
}

function app_session_rate(array &$session, string $key, int $limit, int $seconds): bool
{
    $now = time();
    $state = $session['limits'][$key] ?? ['until' => 0, 'count' => 0];
    if (!is_array($state) || (int)($state['until'] ?? 0) <= $now) {
        $state = ['until' => $now + $seconds, 'count' => 0];
    }
    if ((int)($state['count'] ?? 0) >= $limit) return false;
    $state['count'] = (int)$state['count'] + 1;
    $session['limits'][$key] = $state;
    return true;
}

function app_admin_login(PDO $db, mixed $username, mixed $password): ?array
{
    if (!is_string($username) || !is_string($password) || strlen($username) > 100 || strlen($password) > 1024) {
        return null;
    }
    $q = $db->prepare(
        "SELECT id,username,display_name,password_hash,role,must_change_password
         FROM admin_users WHERE username=:username AND is_active=1"
    );
    $q->execute(['username' => trim($username)]);
    $row = $q->fetch();
    $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $hash = $row === false ? $dummy : (string)$row['password_hash'];
    if (!password_verify($password, $hash) || $row === false
        || !in_array($row['role'], ['system_admin', 'ticket_admin'], true)) {
        return null;
    }
    session_regenerate_id(true);
    $_SESSION['admin_auth'] = [
        'id' => (string)$row['id'],
        'started' => time(),
        'seen' => time(),
        'credential' => hash('sha256', (string)$row['password_hash']),
    ];
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    $db->prepare('UPDATE admin_users SET last_login_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=:id')
       ->execute(['id' => $row['id']]);
    unset($row['password_hash']);
    return $row;
}

function app_admin_user(PDO $db): ?array
{
    $auth = $_SESSION['admin_auth'] ?? null;
    $now = time();
    if (!is_array($auth)
        || !is_string($auth['id'] ?? null)
        || !is_int($auth['started'] ?? null)
        || !is_int($auth['seen'] ?? null)
        || $now - $auth['started'] >= 28800
        || $now - $auth['seen'] >= 1800) {
        unset($_SESSION['admin_auth']);
        return null;
    }

    $q = $db->prepare(
        "SELECT id,username,display_name,password_hash,role,must_change_password
         FROM admin_users WHERE id=:id AND is_active=1"
    );
    $q->execute(['id' => $auth['id']]);
    $row = $q->fetch();
    if ($row === false
        || !in_array($row['role'], ['system_admin', 'ticket_admin'], true)
        || !is_string($auth['credential'] ?? null)
        || !hash_equals($auth['credential'], hash('sha256', (string)$row['password_hash']))) {
        unset($_SESSION['admin_auth']);
        return null;
    }
    $_SESSION['admin_auth']['seen'] = $now;
    unset($row['password_hash']);
    return $row;
}

function app_admin_tickets(PDO $db, array $filters): array
{
    $conditions = ['1=1'];
    $params = [];
    $archive = ($filters['view'] ?? '') === 'archive';
    $conditions[] = $archive ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL';

    foreach (['status' => ['new','in_progress','awaiting_reply','done'], 'priority' => ['low','normal','high']] as $key => $allowed) {
        $value = $filters[$key] ?? '';
        if (is_string($value) && in_array($value, $allowed, true)) {
            $conditions[] = "t.{$key}=:{$key}";
            $params[$key] = $value;
        }
    }

    $sql = "SELECT t.id,t.type,t.priority,t.status,t.reporter_name,t.reporter_abbreviation,
                   t.location,t.device,t.created_at,t.updated_at,t.archived_at,c.name AS category_name
            FROM tickets t JOIN categories c ON c.id=t.category_id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
                     CASE t.status WHEN 'new' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'awaiting_reply' THEN 2 ELSE 3 END,
                     t.queue_position,t.created_at";
    $q = $db->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function app_admin_ticket(PDO $db, string $id): ?array
{
    if (!preg_match('/\A[1-9][0-9]{0,19}\z/', $id)) return null;
    $q = $db->prepare(
        "SELECT t.*,c.name AS category_name
         FROM tickets t JOIN categories c ON c.id=t.category_id WHERE t.id=:id"
    );
    $q->execute(['id' => $id]);
    $ticket = $q->fetch();
    if ($ticket === false) return null;

    $comments = $db->prepare(
        'SELECT tc.body,tc.created_at,au.display_name AS author_name
         FROM ticket_comments tc LEFT JOIN admin_users au ON au.id=tc.author_id
         WHERE tc.ticket_id=:id ORDER BY tc.created_at,tc.id'
    );
    $comments->execute(['id' => $id]);
    $ticket['comments'] = $comments->fetchAll();
    return $ticket;
}

function app_admin_update_ticket(PDO $db, string $id, string $adminId, array $input): void
{
    $status = (string)($input['status'] ?? '');
    $priority = (string)($input['priority'] ?? '');
    if (!in_array($status, ['new','in_progress','awaiting_reply','done'], true)
        || !in_array($priority, ['low','normal','high'], true)) {
        throw new InvalidArgumentException('Ungültiger Status oder Priorität.');
    }
    $comment = app_text($input['comment'] ?? '', 5000, false, 'Interne Notiz');

    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT type,status,priority FROM tickets WHERE id=:id FOR UPDATE');
        $lock->execute(['id' => $id]);
        $ticket = $lock->fetch();
        if ($ticket === false) throw new InvalidArgumentException('Ticket nicht gefunden.');
        if ($ticket['type'] === 'defect' && $priority !== 'high') {
            throw new InvalidArgumentException('Defektmeldungen bleiben auf hoher Priorität.');
        }

        $update = $db->prepare(
            "UPDATE tickets SET status=:status,priority=:priority,
             status_changed_at=CASE WHEN status<>:status2 THEN UTC_TIMESTAMP(6) ELSE status_changed_at END,
             resolved_at=CASE WHEN :done=1 THEN COALESCE(resolved_at,UTC_TIMESTAMP(6)) ELSE NULL END,
             updated_at=UTC_TIMESTAMP(6)
             WHERE id=:id"
        );
        $update->execute([
            'status' => $status,
            'priority' => $priority,
            'status2' => $status,
            'done' => $status === 'done' ? 1 : 0,
            'id' => $id,
        ]);

        if ($comment !== '') {
            $db->prepare('INSERT INTO ticket_comments(ticket_id,author_id,body) VALUES(:ticket,:author,:body)')
               ->execute(['ticket' => $id, 'author' => $adminId, 'body' => $comment]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function app_admin_archive(PDO $db, string $id, bool $archive): void
{
    $q = $db->prepare('SELECT status FROM tickets WHERE id=:id');
    $q->execute(['id' => $id]);
    $status = $q->fetchColumn();
    if ($status === false) throw new InvalidArgumentException('Ticket nicht gefunden.');
    if ($archive && $status !== 'done') {
        throw new InvalidArgumentException('Nur erledigte Tickets können archiviert werden.');
    }
    $sql = $archive
        ? 'UPDATE tickets SET archived_at=COALESCE(archived_at,UTC_TIMESTAMP(6)),updated_at=UTC_TIMESTAMP(6) WHERE id=:id'
        : 'UPDATE tickets SET archived_at=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=:id';
    $db->prepare($sql)->execute(['id' => $id]);
}
