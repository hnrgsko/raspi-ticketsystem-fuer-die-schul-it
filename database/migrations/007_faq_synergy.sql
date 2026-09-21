-- Schul-IT portable migration 007
-- Preserve FAQ history and grow internal search knowledge without overwriting public content.

CREATE TABLE IF NOT EXISTS faq_entry_search_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT UNSIGNED NOT NULL,
    source_ticket_id BIGINT UNSIGNED NULL,
    term VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_faq_entry_term (entry_id,term),
    KEY ix_faq_search_ticket (source_ticket_id),
    CONSTRAINT fk_faq_search_entry
        FOREIGN KEY (entry_id) REFERENCES faq_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_faq_search_ticket
        FOREIGN KEY (source_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill internal search knowledge from already linked support tickets.
INSERT IGNORE INTO faq_entry_search_terms(entry_id,source_ticket_id,term)
SELECT ft.entry_id,ft.ticket_id,LEFT(TRIM(t.description),500)
FROM faq_entry_tickets ft
JOIN tickets t ON t.id=ft.ticket_id
WHERE TRIM(COALESCE(t.description,''))<>'';

INSERT IGNORE INTO faq_entry_search_terms(entry_id,source_ticket_id,term)
SELECT ft.entry_id,ft.ticket_id,LEFT(TRIM(t.device),500)
FROM faq_entry_tickets ft
JOIN tickets t ON t.id=ft.ticket_id
WHERE TRIM(COALESCE(t.device,''))<>'';

INSERT IGNORE INTO faq_entry_search_terms(entry_id,source_ticket_id,term)
SELECT ft.entry_id,ft.ticket_id,LEFT(TRIM(t.defect_subject),500)
FROM faq_entry_tickets ft
JOIN tickets t ON t.id=ft.ticket_id
WHERE TRIM(COALESCE(t.defect_subject,''))<>'';

CREATE TABLE IF NOT EXISTS faq_entry_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT UNSIGNED NOT NULL,
    question VARCHAR(400) NOT NULL,
    answer TEXT NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    changed_by_admin_id BIGINT UNSIGNED NULL,
    change_type VARCHAR(30) NOT NULL,
    change_note VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_faq_revision_entry (entry_id,created_at),
    CONSTRAINT fk_faq_revision_entry
        FOREIGN KEY (entry_id) REFERENCES faq_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_faq_revision_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_revision_admin
        FOREIGN KEY (changed_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT ck_faq_revision_type
        CHECK (change_type IN ('supplement','manual_edit','restore'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('007_faq_synergy');
