USE campusfind;

CREATE TABLE mentor_applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    headline VARCHAR(160) NOT NULL,
    bio TEXT NOT NULL,
    qualifications TEXT NOT NULL,
    experience TEXT DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    pending_user_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN status = 'pending' THEN user_id ELSE NULL END
        ) STORED,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentor_applications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_mentor_applications_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE INDEX uq_mentor_applications_pending_user (pending_user_id),
    INDEX idx_mentor_applications_status_created (status, created_at),
    INDEX idx_mentor_applications_user_created (user_id, created_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentor_application_expertise (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    expertise_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentor_application_expertise_application
        FOREIGN KEY (application_id) REFERENCES mentor_applications(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE INDEX uq_mentor_application_expertise (application_id, expertise_name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentor_contact_preferences (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    whatsapp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentor_contact_preferences_user
        FOREIGN KEY (user_id) REFERENCES mentor_profiles(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentorship_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mentorship_request_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentorship_conversations_request
        FOREIGN KEY (mentorship_request_id) REFERENCES mentorship_requests(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE INDEX uq_mentorship_conversations_request (mentorship_request_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentorship_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentorship_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES mentorship_conversations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mentorship_messages_sender
        FOREIGN KEY (sender_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_mentorship_messages_conversation_created (conversation_id, created_at, id),
    INDEX idx_mentorship_messages_sender (sender_user_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentorship_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mentorship_request_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    scheduled_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    meeting_method ENUM('platform', 'whatsapp', 'physical') NOT NULL,
    location_or_meeting_link VARCHAR(500) DEFAULT NULL,
    status ENUM('scheduled', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentorship_sessions_request
        FOREIGN KEY (mentorship_request_id) REFERENCES mentorship_requests(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mentorship_sessions_creator
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_mentorship_sessions_request_scheduled (mentorship_request_id, scheduled_at),
    INDEX idx_mentorship_sessions_status_scheduled (status, scheduled_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

INSERT INTO mentor_contact_preferences (user_id, whatsapp_enabled)
SELECT user_id, FALSE
FROM mentor_profiles;

INSERT INTO mentorship_conversations (mentorship_request_id)
SELECT id
FROM mentorship_requests
WHERE status = 'accepted';
