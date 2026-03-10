-- ============================================================
--  S.I.T.A QA System — Database Setup
--  Run this file once to initialise the database.
--  Usage: mysql -u root -p < setup.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS qa_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE qa_system;

-- ── Colleges ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS colleges (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    college_code  VARCHAR(20) UNIQUE NOT NULL,
    college_name  VARCHAR(200) NOT NULL,
    description   TEXT,
    status        ENUM('active','inactive') DEFAULT 'active',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ── Majors (formerly Departments) ───────────────────────────
-- Only used for colleges where has_major = 1
CREATE TABLE IF NOT EXISTS departments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    college_id      INT UNSIGNED NOT NULL,
    department_code VARCHAR(20) UNIQUE NOT NULL,
    department_name VARCHAR(200) NOT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
);

-- ── Roles ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key    VARCHAR(50) UNIQUE NOT NULL,
    role_label  VARCHAR(100) NOT NULL,
    level       TINYINT UNSIGNED NOT NULL,
    description TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Users ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(50) UNIQUE,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) UNIQUE NOT NULL,
    password        VARCHAR(255) NOT NULL,
    role_id         INT UNSIGNED NOT NULL,
    college_id      INT UNSIGNED,
    program_id      INT UNSIGNED,         -- the user's specific program (e.g. BSED, BEED)
    profile_photo   VARCHAR(255),
    phone           VARCHAR(30),
    email_verified  TINYINT(1) DEFAULT 0,
    otp_code        VARCHAR(128),
    otp_expiry      DATETIME,
    last_login_at   DATETIME,
    last_login_ip   VARCHAR(45),
    failed_attempts TINYINT UNSIGNED DEFAULT 0,
    locked_until    DATETIME,
    status          ENUM('active','inactive','suspended') DEFAULT 'active',
    deleted_at      DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE RESTRICT,
    FOREIGN KEY (college_id)    REFERENCES colleges(id)    ON DELETE SET NULL,
    FOREIGN KEY (program_id)    REFERENCES programs(id)    ON DELETE SET NULL
);

-- ── Programs ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS programs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    college_id      INT UNSIGNED NOT NULL,
    department_id   INT UNSIGNED NULL,  -- legacy, kept for FK safety
    program_code    VARCHAR(30) UNIQUE NOT NULL,
    program_name    VARCHAR(200) NOT NULL,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (college_id)    REFERENCES colleges(id)    ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ── Accreditation Levels ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS accreditation_levels (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_name   VARCHAR(100) NOT NULL,
    level_order  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    description  TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Areas ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS areas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT UNSIGNED DEFAULT NULL,
    area_code   VARCHAR(30),
    area_name   VARCHAR(150) NOT NULL,
    description TEXT,
    sort_order  SMALLINT UNSIGNED DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES areas(id) ON DELETE SET NULL
);

-- ── Documents ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_code           VARCHAR(100) UNIQUE,
    title                   VARCHAR(255) NOT NULL,
    description             TEXT,
    program_id              INT UNSIGNED,
    area_id                 INT UNSIGNED,
    accreditation_level_id  INT UNSIGNED,
    academic_year           VARCHAR(20),
    semester                ENUM('1st','2nd','Summer') DEFAULT '1st',
    uploaded_by             INT UNSIGNED,
    current_version         SMALLINT UNSIGNED DEFAULT 1,
    file_name               VARCHAR(255),
    file_path               TEXT,
    file_type               VARCHAR(50),
    file_size               BIGINT UNSIGNED,
    status                  ENUM('draft','submitted','under_review','revision_requested','approved','rejected','archived') DEFAULT 'draft',
    is_confidential         TINYINT(1) DEFAULT 0,
    deadline                DATE,
    submitted_at            DATETIME,
    approved_at             DATETIME,
    archived_at             DATETIME,
    deleted_at              DATETIME DEFAULT NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id)             REFERENCES programs(id)             ON DELETE SET NULL,
    FOREIGN KEY (area_id)                REFERENCES areas(id)                ON DELETE SET NULL,
    FOREIGN KEY (accreditation_level_id) REFERENCES accreditation_levels(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by)            REFERENCES users(id)                ON DELETE SET NULL
);

-- ── Document Versions ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS document_versions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id     INT UNSIGNED NOT NULL,
    version_number  SMALLINT UNSIGNED NOT NULL,
    version_label   VARCHAR(30),
    file_name       VARCHAR(255),
    file_path       TEXT NOT NULL,
    file_type       VARCHAR(50),
    file_size       BIGINT UNSIGNED,
    checksum        VARCHAR(64),
    uploaded_by     INT UNSIGNED,
    remarks         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_version (document_id, version_number),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)     ON DELETE SET NULL
);

-- ── Document Assignments ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS document_assignments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    assigned_by  INT UNSIGNED,
    role_context VARCHAR(50),
    assigned_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_doc_user (document_id, user_id),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id)     ON DELETE SET NULL
);

-- ── Document Reviews ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS document_reviews (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id             INT UNSIGNED NOT NULL,
    reviewer_id             INT UNSIGNED,
    version_number_reviewed SMALLINT UNSIGNED,
    review_round            TINYINT UNSIGNED DEFAULT 1,
    decision                ENUM('approved','revision_requested','rejected') NOT NULL,
    comments                TEXT,
    internal_notes          TEXT,
    reviewed_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id)     ON DELETE SET NULL
);

-- ── Notifications ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        ENUM('document_submitted','review_decision','revision_requested','deadline_reminder','assignment','system','general','account_access_request') DEFAULT 'general',
    priority    ENUM('low','normal','high','urgent') DEFAULT 'normal',
    title       VARCHAR(255),
    message     TEXT NOT NULL,
    link        VARCHAR(255),
    is_read     TINYINT(1) DEFAULT 0,
    read_at     DATETIME DEFAULT NULL,
    expires_at  DATETIME DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, is_read)
);

-- ── Deadline Reminders ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS deadline_reminders (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id   INT UNSIGNED,
    reminder_date DATE NOT NULL,
    reminder_type ENUM('email','in_app','both') DEFAULT 'both',
    days_before   TINYINT UNSIGNED DEFAULT 3,
    sent          TINYINT(1) DEFAULT 0,
    sent_at       DATETIME DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
);

-- ── Audit Logs ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id   INT UNSIGNED,
    description TEXT,
    old_value   JSON DEFAULT NULL,
    new_value   JSON DEFAULT NULL,
    ip_address  VARCHAR(45),
    user_agent  VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_user   (user_id)
);

-- ── MIGRATION from older versions ───────────────────────────
-- If upgrading from a version that had has_major / departments / majors:
-- Run: database/migrate_no_majors.sql

-- ============================================================
--  SEED DATA
-- ============================================================

INSERT IGNORE INTO roles (role_key, role_label, level, description) VALUES
('qa_director',       'QA Director',       1, 'Highest QA authority with full system control'),
('qa_staff',          'QA Staff',          2, 'QA reviewers who evaluate submitted documents'),
('academic_director', 'Academic Director', 3, 'Oversees college-wide quality and compliance'),
('dean',              'Dean',              4, 'College head responsible for program oversight'),
('program_head',      'Program Head',      5, 'Program chair / assistant dean managing faculty docs'),
('faculty',           'Faculty',           6, 'Uploads and manages accreditation documents');

INSERT IGNORE INTO accreditation_levels (level_name, level_order, description) VALUES
('Candidate Status', 0, 'Candidate Status'),
('Level I',   1, 'Initial accreditation level'),
('Level II',  2, 'Standard accreditation level'),
('Level III', 3, 'Advanced accreditation level'),
('Level IV',  4, 'Highest accreditation level');

INSERT IGNORE INTO areas (area_code, area_name, sort_order) VALUES
('A1', 'Mission, Vision, Goals and Objectives', 1),
('A2', 'Faculty', 2),
('A3', 'Curriculum and Instruction', 3),
('A4', 'Support to Students', 4),
('A5', 'Research', 5),
('A6', 'Extension and Community Involvement', 6),
('A7', 'Library', 7),
('A8', 'Physical Plant and Facilities', 8);

INSERT IGNORE INTO colleges (college_code, college_name) VALUES
('CAAIS', 'College of Accountancy and Accounting Information System'),
('CBE',   'College of Business Education'),
('COE',   'College of Engineering'),
('CTE',   'College of Teacher Education'),
('CIT',   'College of Information Technology'),
('CHM',   'College of Hospitality Management'),
('CCJE',  'College of Criminal Justice Education'),
('COM',   'College of Midwifery');

-- Programs — linked directly to Colleges
-- Example: full program name includes major, e.g. "Bachelor of Secondary Education Major in Mathematics"
INSERT IGNORE INTO programs (program_code, program_name, college_id) VALUES
('BSCS',       'Bachelor of Science in Computer Science',                         (SELECT id FROM colleges WHERE college_code='CBE')),
('BSIT',       'Bachelor of Science in Information Technology',                   (SELECT id FROM colleges WHERE college_code='CIT')),
('BSBA-MM',    'Bachelor of Science in Business Administration Major in Marketing',(SELECT id FROM colleges WHERE college_code='CBE')),
('BSBA-FM',    'Bachelor of Science in Business Administration Major in Finance',  (SELECT id FROM colleges WHERE college_code='CBE')),
('BSECE',      'Bachelor of Science in Electronics Engineering',                  (SELECT id FROM colleges WHERE college_code='COE')),
('BSED-MATH',  'Bachelor of Secondary Education Major in Mathematics',            (SELECT id FROM colleges WHERE college_code='CTE')),
('BSED-ENG',   'Bachelor of Secondary Education Major in English',                (SELECT id FROM colleges WHERE college_code='CTE')),
('BSED-SCI',   'Bachelor of Secondary Education Major in Science',                (SELECT id FROM colleges WHERE college_code='CTE')),
('BSAIS',      'Bachelor of Science in Accounting Information System',            (SELECT id FROM colleges WHERE college_code='CAAIS')),
('BSA',        'Bachelor of Science in Accountancy',                              (SELECT id FROM colleges WHERE college_code='CAAIS')),
('BSCRIM',     'Bachelor of Science in Criminology',                              (SELECT id FROM colleges WHERE college_code='CCJE')),
('BSHM',       'Bachelor of Science in Hospitality Management',                   (SELECT id FROM colleges WHERE college_code='CHM')),
('BSM',        'Bachelor of Science in Midwifery',                                (SELECT id FROM colleges WHERE college_code='COM'));

-- ── Default admin account ────────────────────────────────────
-- Email: admin@qa.edu | Password: Admin@1234
INSERT IGNORE INTO users (employee_id, name, email, password, role_id, status) VALUES
('EMP-001', 'QA Administrator', 'apabloharold670@gmail.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 1, 'active');

-- Sample CIT faculty (no major/department needed)
INSERT IGNORE INTO users (employee_id, name, email, password, role_id, college_id, status) VALUES
('EMP-002', 'Harold Pablo', 'akosiharold.goku@gmail.com',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 6, (SELECT id FROM colleges WHERE college_code='CIT'), 'active');
