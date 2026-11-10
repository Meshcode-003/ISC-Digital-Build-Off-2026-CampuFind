-- Add optional structured information and pinning without replacing notice data.
USE campusfind;

ALTER TABLE notices
    ADD COLUMN summary VARCHAR(300) DEFAULT NULL AFTER title,
    ADD COLUMN what_happening VARCHAR(500) DEFAULT NULL AFTER summary,
    ADD COLUMN who_affected VARCHAR(500) DEFAULT NULL AFTER what_happening,
    ADD COLUMN action_required VARCHAR(500) DEFAULT NULL AFTER who_affected,
    ADD COLUMN location VARCHAR(255) DEFAULT NULL AFTER action_required,
    ADD COLUMN contact_info VARCHAR(255) DEFAULT NULL AFTER location,
    ADD COLUMN is_pinned BOOLEAN NOT NULL DEFAULT FALSE AFTER priority,
    ADD INDEX idx_notices_feed (status, published_at, is_pinned, expires_at);
