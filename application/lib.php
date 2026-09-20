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

function app_current_host(): string
{
    $raw = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_ADDR'] ?? '127.0.0.1'));
    if (str_starts_with($raw, '[')) {
        $end = strpos($raw, ']');
        return $end === false ? '127.0.0.1' : substr($raw, 0, $end + 1);
    }
    return preg_replace('/:\d+\z/', '', $raw) ?: '127.0.0.1';
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


function app_setting_bool(PDO $db, string $key, bool $fallback = false): bool
{
    $value = strtolower(trim(app_setting($db, $key, $fallback ? '1' : '0')));
    return in_array($value, ['1','true','yes','on'], true);
}

function app_assistant_widget_url(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 2048) return null;
    $value = trim($value);
    if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) return null;

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    $host = (string) parse_url($value, PHP_URL_HOST);
    $user = parse_url($value, PHP_URL_USER);
    $pass = parse_url($value, PHP_URL_PASS);

    // Embedded third-party content is permitted only via HTTPS and without
    // credentials in the URL. Individual providers may still block framing.
    if ($scheme !== 'https' || $host === '' || $user !== null || $pass !== null) return null;

    return $value;
}

function app_assistant_settings(PDO $db): array
{
    $url = app_setting($db, 'assistant_url', '');
    return [
        'enabled' => app_setting_bool($db, 'assistant_enabled', false),
        'label' => app_setting($db, 'assistant_label', 'KI-Assistent'),
        'url' => $url,
        'widget_enabled' => app_setting_bool($db, 'assistant_widget_enabled', false),
        'widget_url' => app_assistant_widget_url($url),
    ];
}

function app_admin_save_assistant_settings(PDO $db, array $input): void
{
    $enabled = isset($input['assistant_enabled']) && (string)$input['assistant_enabled'] === '1';
    $widgetEnabled = isset($input['assistant_widget_enabled'])
        && (string)$input['assistant_widget_enabled'] === '1';
    $label = app_text($input['assistant_label'] ?? '', 80, true, 'Bezeichnung des KI-Assistenten');
    $url = trim((string)($input['assistant_url'] ?? ''));

    if ($url !== '') {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Bitte eine gültige vollständige Assistenten-URL eingeben.');
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['https','http'], true)) {
            throw new InvalidArgumentException('Die Assistenten-URL muss mit https:// oder http:// beginnen.');
        }
    }
    if ($enabled && $url === '') {
        throw new InvalidArgumentException('Zum Aktivieren des KI-Assistenten muss eine URL hinterlegt sein.');
    }
    if ($widgetEnabled && !$enabled) {
        throw new InvalidArgumentException('Die experimentelle Sprechblase kann nur zusammen mit dem KI-Assistenten aktiviert werden.');
    }
    if ($widgetEnabled && app_assistant_widget_url($url) === null) {
        throw new InvalidArgumentException('Für die experimentelle Sprechblase wird eine gültige HTTPS-URL ohne eingebettete Zugangsdaten benötigt.');
    }

    $values = [
        'assistant_enabled' => $enabled ? '1' : '0',
        'assistant_label' => $label,
        'assistant_url' => $url,
        'assistant_widget_enabled' => $widgetEnabled ? '1' : '0',
    ];

    $q = $db->prepare(
        'INSERT INTO system_settings(setting_key,setting_value,updated_at)
         VALUES(:key,:value,UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP(6)'
    );
    foreach ($values as $key => $value) {
        $q->execute(['key' => $key, 'value' => $value]);
    }
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
        app_usage_record($db, 'ticket_created');
        if (($_SESSION['assistant_used_in_session'] ?? false) === true) {
            app_usage_record($db, 'ticket_after_assistant');
        }
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
    $conditions = [];
    $params = [];

    $archive = ($filters['view'] ?? '') === 'archive';
    $conditions[] = $archive ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL';

    $status = $filters['status'] ?? '';
    if (is_string($status) && in_array($status, ['new','in_progress','awaiting_reply','done'], true)) {
        $conditions[] = 't.status=:status';
        $params['status'] = $status;
    }

    $priority = $filters['priority'] ?? '';
    if (is_string($priority) && in_array($priority, ['low','normal','high'], true)) {
        $conditions[] = 't.priority=:priority';
        $params['priority'] = $priority;
    }

    $type = $filters['type'] ?? '';
    if (is_string($type) && in_array($type, ['support','defect'], true)) {
        $conditions[] = 't.type=:type';
        $params['type'] = $type;
    }

    $category = $filters['category'] ?? '';
    if (is_string($category) && preg_match('/\A[1-9][0-9]{0,18}\z/', $category) === 1) {
        $conditions[] = 't.category_id=:category';
        $params['category'] = $category;
    }

    $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
    if ($search !== '' && mb_strlen($search) <= 200) {
        $needle = '%' . $search . '%';
        $parts = [];
        foreach (['reporter_name','reporter_abbreviation','location','device','description','defect_subject'] as $index => $column) {
            $key = 'search_' . $index;
            $parts[] = "COALESCE(t.{$column},'') LIKE :{$key}";
            $params[$key] = $needle;
        }
        $parts[] = 'c.name LIKE :search_category';
        $params['search_category'] = $needle;

        $number = ltrim(ltrim($search, "# \t\n\r\0\x0B"), '0');
        if (preg_match('/\A[1-9][0-9]{0,18}\z/', $number) === 1) {
            $parts[] = 't.id=:search_id';
            $params['search_id'] = $number;
        }
        $conditions[] = '(' . implode(' OR ', $parts) . ')';
    }

    $sort = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'priority';
    $order = match ($sort) {
        'newest' => 't.created_at DESC,t.id DESC',
        'oldest' => 't.created_at ASC,t.id ASC',
        'updated' => 't.updated_at DESC,t.id DESC',
        default => "CASE WHEN t.status='done' THEN 1 ELSE 0 END,
            CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
            CASE t.status WHEN 'new' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'awaiting_reply' THEN 2 ELSE 3 END,
            t.queue_position ASC,t.created_at ASC,t.id ASC",
    };

    $sql = "SELECT t.id,t.type,t.priority,t.status,t.reporter_name,t.reporter_abbreviation,
                   t.location,t.device,t.queue_position,t.created_at,t.status_changed_at,
                   t.updated_at,t.archived_at,c.name AS category_name
            FROM tickets t JOIN categories c ON c.id=t.category_id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY " . $order;
    $q = $db->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function app_admin_category_list(PDO $db): array
{
    return $db->query('SELECT id,name FROM categories WHERE is_active=1 ORDER BY name,id')->fetchAll();
}

function app_admin_quick_status(PDO $db, string $id, string $adminId, string $status): void
{
    if (!in_array($status, ['in_progress','awaiting_reply','done'], true)) {
        throw new InvalidArgumentException('Ungültige Schnellaktion.');
    }
    $q = $db->prepare('SELECT priority FROM tickets WHERE id=:id');
    $q->execute(['id' => $id]);
    $priority = $q->fetchColumn();
    if (!is_string($priority)) {
        throw new InvalidArgumentException('Ticket nicht gefunden.');
    }
    app_admin_update_ticket($db, $id, $adminId, [
        'status' => $status,
        'priority' => $priority,
        'comment' => '',
    ]);
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
             status_changed_at=CASE WHEN :status_changed=1 THEN UTC_TIMESTAMP(6) ELSE status_changed_at END,
             resolved_at=CASE WHEN :done=1 THEN COALESCE(resolved_at,UTC_TIMESTAMP(6)) ELSE NULL END,
             updated_at=UTC_TIMESTAMP(6)
             WHERE id=:id"
        );
        $update->execute([
            'status' => $status,
            'priority' => $priority,
            'status_changed' => $ticket['status'] !== $status ? 1 : 0,
            'done' => $status === 'done' ? 1 : 0,
            'id' => $id,
        ]);

        if ($comment !== '') {
            $db->prepare('INSERT INTO ticket_comments(ticket_id,author_id,body) VALUES(:ticket,:author,:body)')
               ->execute(['ticket' => $id, 'author' => $adminId, 'body' => $comment]);
        }
        $statusChangedToDone = $ticket['status'] !== 'done' && $status === 'done';
        $db->commit();

        if ($statusChangedToDone && app_faq_auto_from_done($db) && app_faq_tables_ready($db)) {
            try {
                app_faq_ticket_proposal($db, $id, $adminId);
            } catch (Throwable $faqError) {
                error_log('Schul-IT: automatic FAQ proposal failed for ticket ' . $id);
            }
        }
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


function app_faq_auto_from_done(PDO $db): bool
{
    return app_setting_bool($db, 'faq_auto_from_done', true);
}

function app_faq_admin_save_settings(PDO $db, array $input): void
{
    $enabled = isset($input['faq_auto_from_done']) && (string)$input['faq_auto_from_done'] === '1';
    $q = $db->prepare(
        'INSERT INTO system_settings(setting_key,setting_value,updated_at)
         VALUES(:key,:value,UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP(6)'
    );
    $q->execute(['key'=>'faq_auto_from_done','value'=>$enabled ? '1' : '0']);
}

function app_faq_tables_ready(PDO $db): bool
{
    try {
        $db->query('SELECT id FROM faq_proposals LIMIT 0');
        $db->query('SELECT id FROM faq_entries LIMIT 0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function app_faq_category_id(PDO $db, mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/', $value) !== 1) {
        throw new InvalidArgumentException('Ungültige FAQ-Kategorie.');
    }
    $q = $db->prepare('SELECT id FROM categories WHERE id=:id AND is_active=1');
    $q->execute(['id' => $value]);
    $id = $q->fetchColumn();
    if ($id === false) throw new InvalidArgumentException('Die FAQ-Kategorie ist nicht verfügbar.');
    return (string)$id;
}

function app_faq_public_entries(PDO $db, int $limit = 12): array
{
    if (!app_faq_tables_ready($db)) return [];
    $limit = max(1, min(50, $limit));
    return $db->query(
        "SELECT f.id,f.question,f.answer,f.weight,c.name AS category_name
         FROM faq_entries f
         LEFT JOIN categories c ON c.id=f.category_id
         WHERE f.status='published'
         ORDER BY f.weight DESC,f.published_at DESC,f.id DESC
         LIMIT " . $limit
    )->fetchAll();
}

function app_faq_ticket_question(array $ticket): string
{
    $subject = trim((string)($ticket['defect_subject'] ?? ''));
    $description = trim((string)($ticket['description'] ?? ''));
    $source = $subject !== '' ? $subject : $description;
    $source = trim((string)preg_replace('/\s+/u', ' ', $source));
    if ($source === '') return 'Wie lässt sich dieses IT-Problem lösen?';

    if (mb_strlen($source) > 260) {
        $source = rtrim(mb_substr($source, 0, 257)) . '…';
    }
    if (str_ends_with($source, '?')) return $source;

    if (($ticket['type'] ?? '') === 'defect') {
        return 'Was kann ich tun, wenn folgendes Gerät oder Zubehör nicht funktioniert: ' . rtrim($source, '.!') . '?';
    }
    return 'Wie lässt sich folgendes IT-Problem lösen: ' . rtrim($source, '.!') . '?';
}

function app_faq_ticket_proposal(PDO $db, string $ticketId, string $adminId): string
{
    if (!app_faq_tables_ready($db)) {
        throw new RuntimeException('Die FAQ-Funktion ist noch nicht eingerichtet.');
    }
    if (preg_match('/\A[1-9][0-9]{0,19}\z/', $ticketId) !== 1) {
        throw new InvalidArgumentException('Ungültige Ticketnummer.');
    }

    $existing = $db->prepare(
        "SELECT id FROM faq_proposals
         WHERE source_type='ticket' AND source_ticket_id=:ticket AND status IN ('pending','accepted')
         ORDER BY id DESC LIMIT 1"
    );
    $existing->execute(['ticket'=>$ticketId]);
    $existingId = $existing->fetchColumn();
    if ($existingId !== false) return (string)$existingId;

    $q = $db->prepare(
        'SELECT id,type,status,category_id,defect_subject,description
         FROM tickets WHERE id=:id'
    );
    $q->execute(['id'=>$ticketId]);
    $ticket = $q->fetch();
    if ($ticket === false) throw new InvalidArgumentException('Ticket nicht gefunden.');
    if ($ticket['status'] !== 'done') {
        throw new InvalidArgumentException('FAQ-Entwürfe können automatisch erst aus erledigten Tickets erzeugt werden.');
    }

    $comment = $db->prepare(
        'SELECT body FROM ticket_comments WHERE ticket_id=:ticket ORDER BY created_at DESC,id DESC LIMIT 1'
    );
    $comment->execute(['ticket'=>$ticketId]);
    $latest = $comment->fetchColumn();
    $answerDraft = is_string($latest) ? trim($latest) : '';

    $insert = $db->prepare(
        "INSERT INTO faq_proposals
        (source_type,source_ticket_id,category_id,question,answer_draft,status,created_by_admin_id)
        VALUES('ticket',:ticket,:category,:question,:answer,'pending',:admin)"
    );
    $insert->execute([
        'ticket'=>$ticketId,
        'category'=>$ticket['category_id'],
        'question'=>app_faq_ticket_question($ticket),
        'answer'=>$answerDraft === '' ? null : $answerDraft,
        'admin'=>$adminId,
    ]);
    return (string)$db->lastInsertId();
}

function app_faq_admin_create_proposal(PDO $db, string $adminId, array $input): string
{
    if (!app_faq_tables_ready($db)) {
        throw new RuntimeException('Die FAQ-Funktion ist noch nicht eingerichtet.');
    }
    $question = app_text($input['faq_question'] ?? '', 400, true, 'Problemfrage');
    $answer = app_text($input['faq_answer'] ?? '', 8000, false, 'Antwort');
    $categoryId = app_faq_category_id($db, $input['faq_category_id'] ?? '');

    $q = $db->prepare(
        "INSERT INTO faq_proposals
        (source_type,category_id,question,answer_draft,status,created_by_admin_id)
        VALUES('admin',:category,:question,:answer,'pending',:admin)"
    );
    $q->execute([
        'category'=>$categoryId,
        'question'=>$question,
        'answer'=>$answer === '' ? null : $answer,
        'admin'=>$adminId,
    ]);
    return (string)$db->lastInsertId();
}

function app_faq_admin_pending(PDO $db): array
{
    if (!app_faq_tables_ready($db)) return [];
    return $db->query(
        "SELECT p.*,c.name AS category_name,t.id AS ticket_exists
         FROM faq_proposals p
         LEFT JOIN categories c ON c.id=p.category_id
         LEFT JOIN tickets t ON t.id=p.source_ticket_id
         WHERE p.status='pending'
         ORDER BY p.created_at ASC,p.id ASC"
    )->fetchAll();
}

function app_faq_admin_entries(PDO $db): array
{
    if (!app_faq_tables_ready($db)) return [];
    return $db->query(
        "SELECT f.*,c.name AS category_name
         FROM faq_entries f
         LEFT JOIN categories c ON c.id=f.category_id
         ORDER BY CASE f.status WHEN 'published' THEN 0 ELSE 1 END,
                  f.updated_at DESC,f.id DESC"
    )->fetchAll();
}

function app_faq_admin_publish(PDO $db, string $proposalId, string $adminId, array $input): void
{
    if (!app_faq_tables_ready($db)) {
        throw new RuntimeException('Die FAQ-Funktion ist noch nicht eingerichtet.');
    }
    if (preg_match('/\A[1-9][0-9]{0,19}\z/', $proposalId) !== 1) {
        throw new InvalidArgumentException('Ungültiger FAQ-Entwurf.');
    }
    $question = app_text($input['faq_question'] ?? '', 400, true, 'Problemfrage');
    $answer = app_text($input['faq_answer'] ?? '', 8000, true, 'FAQ-Antwort');
    $categoryId = app_faq_category_id($db, $input['faq_category_id'] ?? '');

    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT id,status FROM faq_proposals WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$proposalId]);
        $proposal = $lock->fetch();
        if ($proposal === false || $proposal['status'] !== 'pending') {
            throw new InvalidArgumentException('Dieser FAQ-Entwurf ist nicht mehr zur Moderation verfügbar.');
        }

        $insert = $db->prepare(
            "INSERT INTO faq_entries
            (proposal_id,category_id,question,answer,status,created_by_admin_id,updated_by_admin_id,published_at)
            VALUES(:proposal,:category,:question,:answer,'published',:admin,:admin,UTC_TIMESTAMP(6))"
        );
        $insert->execute([
            'proposal'=>$proposalId,
            'category'=>$categoryId,
            'question'=>$question,
            'answer'=>$answer,
            'admin'=>$adminId,
        ]);

        $update = $db->prepare(
            "UPDATE faq_proposals
             SET question=:question,answer_draft=:answer,category_id=:category,status='accepted',
                 moderated_by_admin_id=:admin,moderated_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
             WHERE id=:id"
        );
        $update->execute([
            'question'=>$question,
            'answer'=>$answer,
            'category'=>$categoryId,
            'admin'=>$adminId,
            'id'=>$proposalId,
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function app_faq_admin_reject(PDO $db, string $proposalId, string $adminId): void
{
    if (preg_match('/\A[1-9][0-9]{0,19}\z/', $proposalId) !== 1) {
        throw new InvalidArgumentException('Ungültiger FAQ-Entwurf.');
    }
    $q = $db->prepare(
        "UPDATE faq_proposals
         SET status='rejected',moderated_by_admin_id=:admin,moderated_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
         WHERE id=:id AND status='pending'"
    );
    $q->execute(['admin'=>$adminId,'id'=>$proposalId]);
    if ($q->rowCount() !== 1) {
        throw new InvalidArgumentException('Dieser FAQ-Entwurf ist nicht mehr zur Moderation verfügbar.');
    }
}

function app_faq_admin_set_entry_status(PDO $db, string $entryId, string $adminId, string $status): void
{
    if (!in_array($status, ['published','inactive'], true)
        || preg_match('/\A[1-9][0-9]{0,19}\z/', $entryId) !== 1) {
        throw new InvalidArgumentException('Ungültige FAQ-Aktion.');
    }
    $q = $db->prepare(
        "UPDATE faq_entries
         SET status=:status,updated_by_admin_id=:admin,
             published_at=CASE WHEN :status='published' THEN COALESCE(published_at,UTC_TIMESTAMP(6)) ELSE published_at END,
             updated_at=UTC_TIMESTAMP(6)
         WHERE id=:id"
    );
    $q->execute(['status'=>$status,'admin'=>$adminId,'id'=>$entryId]);
    if ($q->rowCount() < 1) {
        $exists = $db->prepare('SELECT id FROM faq_entries WHERE id=:id');
        $exists->execute(['id'=>$entryId]);
        if ($exists->fetchColumn() === false) throw new InvalidArgumentException('FAQ-Eintrag nicht gefunden.');
    }
}


function app_usage_tables_ready(PDO $db): bool
{
    try {
        $db->query('SELECT stat_date FROM usage_daily LIMIT 0');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function app_usage_metrics(): array
{
    return [
        'assistant_inline_use',
        'assistant_bubble_open',
        'assistant_external_open',
        'ticket_created',
        'ticket_after_assistant',
    ];
}

function app_usage_record(PDO $db, string $metric): void
{
    if (!in_array($metric, app_usage_metrics(), true) || !app_usage_tables_ready($db)) return;

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $sessionKey = 'usage_' . $today . '_' . $metric;
    $isNewSession = session_status() === PHP_SESSION_ACTIVE
        && (($_SESSION['usage_counted'][$sessionKey] ?? false) !== true);

    $q = $db->prepare(
        'INSERT INTO usage_daily(stat_date,metric,event_count,session_count,updated_at)
         VALUES(:day,:metric,1,:sessions,UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE
           event_count=event_count+1,
           session_count=session_count+VALUES(session_count),
           updated_at=UTC_TIMESTAMP(6)'
    );
    $q->execute([
        'day'=>$today,
        'metric'=>$metric,
        'sessions'=>$isNewSession ? 1 : 0,
    ]);

    if ($isNewSession) {
        if (!isset($_SESSION['usage_counted']) || !is_array($_SESSION['usage_counted'])) {
            $_SESSION['usage_counted'] = [];
        }
        $_SESSION['usage_counted'][$sessionKey] = true;
    }

    if (str_starts_with($metric, 'assistant_') && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['assistant_used_in_session'] = true;
    }
}

function app_admin_usage_summary(PDO $db): array
{
    $zone = new DateTimeZone('Europe/Berlin');
    $today = new DateTimeImmutable('today', $zone);
    $periods = [
        'today' => $today,
        '7d' => $today->modify('-6 days'),
        '30d' => $today->modify('-29 days'),
    ];

    $result = [];
    foreach (app_usage_metrics() as $metric) {
        $result[$metric] = [
            'today'=>['events'=>0,'sessions'=>0],
            '7d'=>['events'=>0,'sessions'=>0],
            '30d'=>['events'=>0,'sessions'=>0],
            'all'=>['events'=>0,'sessions'=>0],
        ];
    }

    $all = $db->query(
        'SELECT metric,SUM(event_count) AS events,SUM(session_count) AS sessions
         FROM usage_daily GROUP BY metric'
    )->fetchAll();
    foreach ($all as $row) {
        $metric = (string)$row['metric'];
        if (!isset($result[$metric])) continue;
        $result[$metric]['all'] = [
            'events'=>(int)$row['events'],
            'sessions'=>(int)$row['sessions'],
        ];
    }

    foreach ($periods as $key=>$start) {
        $q = $db->prepare(
            'SELECT metric,SUM(event_count) AS events,SUM(session_count) AS sessions
             FROM usage_daily WHERE stat_date>=:start GROUP BY metric'
        );
        $q->execute(['start'=>$start->format('Y-m-d')]);
        foreach ($q->fetchAll() as $row) {
            $metric = (string)$row['metric'];
            if (!isset($result[$metric])) continue;
            $result[$metric][$key] = [
                'events'=>(int)$row['events'],
                'sessions'=>(int)$row['sessions'],
            ];
        }
    }

    return $result;
}

function app_admin_ticket_statistics(PDO $db): array
{
    $zone = new DateTimeZone('Europe/Berlin');
    $utc = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today', $zone);

    $countSince = static function (PDO $db, DateTimeImmutable $start) use ($utc): int {
        $q = $db->prepare('SELECT COUNT(*) FROM tickets WHERE created_at>=:start');
        $q->execute(['start'=>$start->setTimezone($utc)->format('Y-m-d H:i:s')]);
        return (int)$q->fetchColumn();
    };

    return [
        'today'=>$countSince($db, $today),
        '7d'=>$countSince($db, $today->modify('-6 days')),
        '30d'=>$countSince($db, $today->modify('-29 days')),
        'all'=>(int)$db->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
        'open'=>(int)$db->query("SELECT COUNT(*) FROM tickets WHERE archived_at IS NULL AND status<>'done'")->fetchColumn(),
        'done'=>(int)$db->query("SELECT COUNT(*) FROM tickets WHERE status='done'")->fetchColumn(),
    ];
}

function app_admin_usage_daily(PDO $db, int $days = 30): array
{
    $days = max(1, min(90, $days));
    $zone = new DateTimeZone('Europe/Berlin');
    $utc = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today', $zone);
    $start = $today->modify('-' . ($days - 1) . ' days');

    $rows = [];
    for ($i=0; $i<$days; $i++) {
        $day = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $rows[$day] = [
            'date'=>$day,
            'ticket_created'=>0,
            'assistant_inline_use'=>0,
            'assistant_bubble_open'=>0,
            'assistant_external_open'=>0,
            'ticket_after_assistant'=>0,
        ];
    }

    $usage = $db->prepare(
        'SELECT stat_date,metric,event_count FROM usage_daily
         WHERE stat_date>=:start ORDER BY stat_date'
    );
    $usage->execute(['start'=>$start->format('Y-m-d')]);
    foreach ($usage->fetchAll() as $row) {
        $day = (string)$row['stat_date'];
        $metric = (string)$row['metric'];
        if (isset($rows[$day][$metric])) {
            $rows[$day][$metric] = (int)$row['event_count'];
        }
    }

    // Ticket counts are derived from the ticket table so pre-statistics tickets are included.
    $tickets = $db->prepare('SELECT created_at FROM tickets WHERE created_at>=:start');
    $tickets->execute(['start'=>$start->setTimezone($utc)->format('Y-m-d H:i:s')]);
    foreach ($tickets->fetchAll(PDO::FETCH_COLUMN) as $createdAt) {
        try {
            $day = (new DateTimeImmutable((string)$createdAt, $utc))->setTimezone($zone)->format('Y-m-d');
            if (isset($rows[$day])) $rows[$day]['ticket_created']++;
        } catch (Throwable) {
            continue;
        }
    }

    return array_values($rows);
}
