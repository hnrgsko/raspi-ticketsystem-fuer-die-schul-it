-- Schul-IT portable migration 002
-- Ticket core for the school-neutral Raspberry Pi distribution.

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_code (code),
    CONSTRAINT ck_category_active CHECK (is_active IN (0,1)),
    CONSTRAINT ck_category_names CHECK (
        CHAR_LENGTH(TRIM(code)) > 0 AND CHAR_LENGTH(TRIM(name)) > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(20) NOT NULL,
    reporter_name VARCHAR(100) NOT NULL,
    reporter_abbreviation VARCHAR(20) NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    location VARCHAR(150) NOT NULL,
    device VARCHAR(255) NOT NULL,
    defect_subject VARCHAR(255) NULL,
    inventory_number VARCHAR(100) NULL,
    serial_number VARCHAR(100) NULL,
    description TEXT NOT NULL,
    occurrence_details TEXT NULL,
    priority VARCHAR(10) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    queue_position BIGINT NOT NULL DEFAULT 0,
    status_changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    resolved_at DATETIME(6) NULL,
    archived_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_ticket_queue (archived_at,status,queue_position,id),
    KEY ix_ticket_category (category_id,status,created_at),
    KEY ix_ticket_type (type,status),
    KEY ix_ticket_priority (priority,status),
    KEY ix_ticket_created (created_at),
    KEY ix_ticket_archived (archived_at,status,created_at),
    CONSTRAINT fk_ticket_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT ck_ticket_type CHECK (type IN ('support','defect')),
    CONSTRAINT ck_ticket_priority CHECK (priority IN ('low','normal','high')),
    CONSTRAINT ck_ticket_status CHECK (status IN ('new','in_progress','awaiting_reply','done')),
    CONSTRAINT ck_ticket_required CHECK (
        CHAR_LENGTH(TRIM(reporter_name)) > 0
        AND CHAR_LENGTH(TRIM(reporter_abbreviation)) > 0
        AND CHAR_LENGTH(TRIM(location)) > 0
        AND CHAR_LENGTH(TRIM(device)) > 0
        AND CHAR_LENGTH(TRIM(description)) > 0
    ),
    CONSTRAINT ck_ticket_defect CHECK (
        (type = 'support')
        OR (type = 'defect' AND priority = 'high'
            AND defect_subject IS NOT NULL
            AND CHAR_LENGTH(TRIM(defect_subject)) > 0)
    ),
    CONSTRAINT ck_ticket_resolution CHECK (
        (status = 'done' AND resolved_at IS NOT NULL)
        OR (status <> 'done' AND resolved_at IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_comment_ticket (ticket_id,created_at,id),
    KEY ix_comment_author (author_id),
    CONSTRAINT fk_comment_ticket FOREIGN KEY (ticket_id)
        REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_author FOREIGN KEY (author_id)
        REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT ck_comment_body CHECK (CHAR_LENGTH(TRIM(body)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (code,name,sort_order) VALUES
('school_portal','Schulportal',10),
('service_mail','Dienstmail',20),
('network','WLAN / Internet',30),
('tablet','iPad / Tablet',40),
('board','Digitale Tafel / Smartboard',50),
('computer','Computer / Notebook',60),
('accessories','Kabel / Zubehör',70),
('software','Software / Apps',80),
('account','Benutzerkonto / Passwort',90),
('hardware','Defekt / Hardware',100),
('other','Sonstiges',110);

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_ticket_core');
