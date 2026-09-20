-- Schul-IT portable migration 005
-- FAQ autopilot: duplicate hints, moderation recommendation and ticket-to-FAQ links.

ALTER TABLE faq_proposals
    ADD COLUMN recommendation VARCHAR(20) NOT NULL DEFAULT 'new' AFTER answer_draft,
    ADD COLUMN recommendation_reason VARCHAR(500) NULL AFTER recommendation,
    ADD COLUMN suggested_entry_id BIGINT UNSIGNED NULL AFTER recommendation_reason,
    ADD COLUMN similarity_score DECIMAL(5,2) NULL AFTER suggested_entry_id,
    ADD KEY ix_faq_proposal_suggested (suggested_entry_id),
    ADD CONSTRAINT fk_faq_proposal_suggested
        FOREIGN KEY (suggested_entry_id) REFERENCES faq_entries(id) ON DELETE SET NULL,
    ADD CONSTRAINT ck_faq_proposal_recommendation
        CHECK (recommendation IN ('new','merge','review'));

CREATE TABLE faq_entry_tickets (
    entry_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (entry_id,ticket_id),
    KEY ix_faq_ticket_ticket (ticket_id,entry_id),
    CONSTRAINT fk_faq_ticket_entry FOREIGN KEY (entry_id) REFERENCES faq_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_faq_ticket_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('005_faq_autopilot');
