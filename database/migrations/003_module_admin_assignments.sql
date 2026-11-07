USE campusfind;

CREATE TABLE module_admin_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    module_key ENUM('lost_items', 'notices', 'mentorship') NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_module_admin_assignments_admin
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_module_admin_assignments_assigner
        FOREIGN KEY (assigned_by) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    UNIQUE INDEX uq_module_admin_assignments_user_module (user_id, module_key),
    INDEX idx_module_admin_assignments_module_user (module_key, user_id),
    INDEX idx_module_admin_assignments_assigner (assigned_by)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
