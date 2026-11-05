CREATE DATABASE IF NOT EXISTS campusfind
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE campusfind;

CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO roles (role_name, description) VALUES
    ('student', 'Student user'),
    ('staff', 'Staff user'),
    ('admin', 'Administrator'),
    ('super_admin', 'Super administrator');

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    username VARBINARY(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(15) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    account_status ENUM('active', 'suspended', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_users_role (role_id),
    INDEX idx_users_status (account_status)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO categories (category_name, description) VALUES
    ('electronics', 'Phones, laptops, chargers, and other electronic devices'),
    ('documents', 'IDs, certificates, books, and other documents');

CREATE TABLE items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    item_type ENUM('lost', 'found') NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    date_occurred DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    contact_details VARCHAR(25) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'recovered') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT DEFAULT NULL,
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    recovered_at TIMESTAMP DEFAULT NULL,
    recovered_by BIGINT UNSIGNED DEFAULT NULL,
    recovered_claim_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_items_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_items_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_items_approved_by
        FOREIGN KEY (approved_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_items_recovered_by
        FOREIGN KEY (recovered_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_items_user (user_id),
    INDEX idx_items_category (category_id),
    INDEX idx_items_type (item_type),
    INDEX idx_items_status (status),
    INDEX idx_items_date (date_occurred),
    INDEX idx_items_location (location)
) ENGINE=InnoDB;

CREATE TABLE item_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_item_images_item
        FOREIGN KEY (item_id) REFERENCES items(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_item_images_item (item_id)
) ENGINE=InnoDB;

CREATE TABLE claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    claimant_id BIGINT UNSIGNED NOT NULL,
    claim_description TEXT NOT NULL,
    proof_description TEXT DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_claims_item
        FOREIGN KEY (item_id) REFERENCES items(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_claims_claimant
        FOREIGN KEY (claimant_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_claims_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_claims_item (item_id),
    INDEX idx_claims_claimant (claimant_id),
    INDEX idx_claims_status (status)
) ENGINE=InnoDB;

ALTER TABLE items
    ADD CONSTRAINT fk_items_recovered_claim
        FOREIGN KEY (recovered_claim_id) REFERENCES claims(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_items_recovered_claim (recovered_claim_id);

CREATE TABLE lost_item_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    reporter_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    contact_details VARCHAR(25) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lost_reports_item
        FOREIGN KEY (item_id) REFERENCES items(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_lost_reports_reporter
        FOREIGN KEY (reporter_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_lost_reports_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_lost_reports_item (item_id),
    INDEX idx_lost_reports_reporter (reporter_id),
    INDEX idx_lost_reports_status (status)
) ENGINE=InnoDB;

CREATE TABLE notification (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    notification_type ENUM('system', 'approval', 'rejection', 'claim', 'recovery') NOT NULL DEFAULT 'system',
    related_item_id BIGINT UNSIGNED DEFAULT NULL,
    related_claim_id BIGINT UNSIGNED DEFAULT NULL,
    related_report_id BIGINT UNSIGNED DEFAULT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    read_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notifications_item
        FOREIGN KEY (related_item_id) REFERENCES items(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_notification_claim
        FOREIGN KEY (related_claim_id) REFERENCES claims(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_notifications_report
        FOREIGN KEY (related_report_id) REFERENCES lost_item_reports(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    related_item_id BIGINT UNSIGNED DEFAULT NULL,
    related_claim_id BIGINT UNSIGNED DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_activity_item
        FOREIGN KEY (related_item_id) REFERENCES items(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_activity_claim
        FOREIGN KEY (related_claim_id) REFERENCES claims(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_item (related_item_id),
    INDEX idx_activity_date (created_at)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    setting_type ENUM('text', 'number', 'boolean') NOT NULL DEFAULT 'text',
    description VARCHAR(255) DEFAULT NULL,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
    ('system_name', 'CampusFind', 'text', 'Name displayed throughout the system'),
    ('system_description', 'Campus lost and found system', 'text', 'Short description of the system'),
    ('items_per_page', '20', 'number', 'Number of items displayed per page'),
    ('registration_enabled', '1', 'boolean', 'Allow new student and staff registrations'),
    ('reporting_enabled', '1', 'boolean', 'Allow users to submit lost and found reports'),
    ('maintenance_mode', '0', 'boolean', 'Put the system into maintenance mode');
