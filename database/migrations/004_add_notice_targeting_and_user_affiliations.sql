-- Additive migration for the live schema reviewed against the provided dump.
-- Existing accounts remain unaffiliated until their school/department is
-- explicitly selected; the reference directory below is copied from the dump.
USE campusfind;

CREATE TABLE schools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_name VARCHAR(150) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uq_schools_name (school_name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    department_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_departments_school
        FOREIGN KEY (school_id) REFERENCES schools(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE INDEX uq_departments_school_name (school_id, department_name),
    UNIQUE INDEX uq_departments_school_id (school_id, id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE notice_schools (
    notice_id BIGINT UNSIGNED NOT NULL,
    school_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id, school_id),
    CONSTRAINT fk_notice_schools_notice
        FOREIGN KEY (notice_id) REFERENCES notices(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notice_schools_school
        FOREIGN KEY (school_id) REFERENCES schools(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_notice_schools_school (school_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE notice_departments (
    notice_id BIGINT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id, department_id),
    CONSTRAINT fk_notice_departments_notice
        FOREIGN KEY (notice_id) REFERENCES notices(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notice_departments_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_notice_departments_department (department_id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

INSERT INTO schools (id, school_name, status) VALUES
    (1, 'School of Business', 'active'),
    (2, 'School of Education', 'active'),
    (3, 'School of ICT', 'active'),
    (4, 'School of Health Sciences', 'active'),
    (5, 'School of Arts & Social Sciences', 'active'),
    (6, 'School of Nursing', 'active'),
    (7, 'School of Science and Technology', 'active'),
    (8, 'School of Postgraduate Studies', 'active');

INSERT INTO departments (id, school_id, department_name) VALUES
    (1, 1, 'Accounting'),
    (2, 1, 'Finance'),
    (3, 1, 'Business Administration'),
    (4, 1, 'Economics'),
    (5, 2, 'Educational Administration'),
    (6, 2, 'Curriculum Studies'),
    (7, 2, 'Early Childhood Education'),
    (8, 3, 'Computer Science'),
    (9, 3, 'Information Technology'),
    (10, 3, 'Software Engineering'),
    (11, 3, 'Information Systems'),
    (12, 4, 'Public Health'),
    (13, 4, 'Clinical Medicine'),
    (14, 4, 'Medical Laboratory Sciences'),
    (15, 5, 'Communication'),
    (16, 5, 'Sociology'),
    (17, 5, 'Psychology'),
    (18, 6, 'BSc Nursing'),
    (19, 6, 'Diploma Nursing'),
    (20, 6, 'Midwifery'),
    (21, 7, 'Engineering'),
    (22, 7, 'Physics'),
    (23, 7, 'Chemistry'),
    (24, 7, 'Mathematics'),
    (25, 8, 'MBA Programmes'),
    (26, 8, 'MSc IT Programmes'),
    (27, 8, 'MEd Programmes'),
    (28, 8, 'PhD Programmes');

ALTER TABLE users
    ADD COLUMN school_id INT UNSIGNED DEFAULT NULL AFTER role_id,
    ADD COLUMN department_id INT UNSIGNED DEFAULT NULL AFTER school_id,
    ADD INDEX idx_users_school_department (school_id, department_id),
    ADD CONSTRAINT fk_users_affiliation_school
        FOREIGN KEY (school_id) REFERENCES schools(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_users_affiliation_department
        FOREIGN KEY (school_id, department_id)
        REFERENCES departments(school_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT chk_users_department_requires_school
        CHECK (department_id IS NULL OR school_id IS NOT NULL);
