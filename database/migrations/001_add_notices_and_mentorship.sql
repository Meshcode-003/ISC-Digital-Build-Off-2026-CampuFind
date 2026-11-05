USE campusfind;

CREATE TABLE notice_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE notices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    audience ENUM('all', 'students', 'staff') NOT NULL DEFAULT 'all',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notices_category
        FOREIGN KEY (category_id) REFERENCES notice_categories(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_notices_creator
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_notices_category (category_id),
    INDEX idx_notices_creator (created_by),
    INDEX idx_notices_audience_status (audience, status),
    INDEX idx_notices_publication (published_at, expires_at)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE notice_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notice_id BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notice_attachments_notice
        FOREIGN KEY (notice_id) REFERENCES notices(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notice_attachments_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_notice_attachments_notice (notice_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE notice_reads (
    notice_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id, user_id),
    CONSTRAINT fk_notice_reads_notice
        FOREIGN KEY (notice_id) REFERENCES notices(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notice_reads_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_notice_reads_user (user_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentor_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    headline VARCHAR(160) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    accepting_requests BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentor_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_mentor_profiles_discoverability (status, accepting_requests)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentor_expertise (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mentor_user_id BIGINT UNSIGNED NOT NULL,
    expertise_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentor_expertise_profile
        FOREIGN KEY (mentor_user_id) REFERENCES mentor_profiles(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE INDEX uq_mentor_expertise (mentor_user_id, expertise_name),
    INDEX idx_mentor_expertise_name (expertise_name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE mentorship_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    mentor_user_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'accepted', 'declined', 'cancelled', 'completed')
        NOT NULL DEFAULT 'pending',
    mentor_response TEXT DEFAULT NULL,
    responded_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mentorship_requests_student
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_mentorship_requests_mentor
        FOREIGN KEY (mentor_user_id) REFERENCES mentor_profiles(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_mentorship_requests_student (student_user_id),
    INDEX idx_mentorship_requests_mentor_status (mentor_user_id, status)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
