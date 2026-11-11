USE campusfind;

ALTER TABLE notification
    MODIFY COLUMN notification_type ENUM(
        'system',
        'approval',
        'rejection',
        'claim',
        'recovery',
        'notice',
        'mentorship',
        'message',
        'admin',
        'registration'
    ) NOT NULL DEFAULT 'system',
    ADD COLUMN notification_url VARCHAR(500) DEFAULT NULL AFTER notification_type,
    ADD COLUMN dedupe_key VARCHAR(191) DEFAULT NULL AFTER notification_url,
    ADD UNIQUE INDEX uq_notification_user_dedupe (user_id, dedupe_key),
    ADD INDEX idx_notification_user_read_created (user_id, is_read, created_at);

CREATE TABLE mentorship_conversation_presence (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_mentorship_presence_conversation
        FOREIGN KEY (conversation_id) REFERENCES mentorship_conversations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mentorship_presence_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_mentorship_presence_last_seen (last_seen_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
