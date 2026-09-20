-- Schul-IT portable migration 003
-- FAQ moderation and published FAQ content.

CREATE TABLE IF NOT EXISTS faq_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(20) NOT NULL,
    source_ticket_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    question VARCHAR(400) NOT NULL,
    answer_draft TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_by_admin_id BIGINT UNSIGNED NULL,
    moderated_by_admin_id BIGINT UNSIGNED NULL,
    moderated_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_faq_proposal_status (status,created_at,id),
    KEY ix_faq_proposal_ticket (source_ticket_id),
    KEY ix_faq_proposal_category (category_id),
    CONSTRAINT fk_faq_proposal_ticket FOREIGN KEY (source_ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_proposal_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_proposal_created_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_proposal_moderated_admin FOREIGN KEY (moderated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT ck_faq_proposal_source CHECK (source_type IN ('ticket','admin','colleague')),
    CONSTRAINT ck_faq_proposal_status CHECK (status IN ('pending','accepted','rejected')),
    CONSTRAINT ck_faq_proposal_question CHECK (CHAR_LENGTH(TRIM(question)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faq_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proposal_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    question VARCHAR(400) NOT NULL,
    answer TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'published',
    weight INT NOT NULL DEFAULT 0,
    created_by_admin_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    published_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_faq_entry_proposal (proposal_id),
    KEY ix_faq_entry_public (status,weight,id),
    KEY ix_faq_entry_category (category_id,status),
    CONSTRAINT fk_faq_entry_proposal FOREIGN KEY (proposal_id) REFERENCES faq_proposals(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_entry_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_entry_created_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_faq_entry_updated_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT ck_faq_entry_status CHECK (status IN ('published','inactive')),
    CONSTRAINT ck_faq_entry_text CHECK (
        CHAR_LENGTH(TRIM(question)) > 0 AND CHAR_LENGTH(TRIM(answer)) > 0
    ),
    CONSTRAINT ck_faq_entry_publication CHECK (
        status <> 'published' OR published_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_faq_moderation');
