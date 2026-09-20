-- Schul-IT portable migration 006
-- Secure ticket status lookup with a random one-time-shown status code.

ALTER TABLE tickets
    ADD COLUMN status_code_hash CHAR(64) NULL AFTER status_changed_at;

-- Existing development/test tickets receive an unknown random secret so that
-- they cannot remain accessible through predictable identifiers after upgrade.
UPDATE tickets
SET status_code_hash = SHA2(CONCAT(UUID(), ':', RAND(), ':', id, ':', UTC_TIMESTAMP(6)), 256)
WHERE status_code_hash IS NULL;

ALTER TABLE tickets
    MODIFY status_code_hash CHAR(64) NOT NULL;

INSERT IGNORE INTO schema_migrations (version) VALUES ('006_ticket_status_code');
