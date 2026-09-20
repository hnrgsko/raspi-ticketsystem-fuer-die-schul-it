-- Schul-IT portable migration 004
-- Privacy-preserving aggregate usage statistics. No IPs, names, user agents or raw events.

CREATE TABLE IF NOT EXISTS usage_daily (
    stat_date DATE NOT NULL,
    metric VARCHAR(64) NOT NULL,
    event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    session_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (stat_date,metric),
    KEY ix_usage_metric_date (metric,stat_date),
    CONSTRAINT ck_usage_counts CHECK (event_count >= 0 AND session_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('004_usage_statistics');
