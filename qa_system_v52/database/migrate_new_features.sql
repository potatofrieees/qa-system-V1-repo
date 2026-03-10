-- ================================================================
-- QA System — New Features Migration
-- Run this AFTER setup.sql
-- ================================================================

-- ── New Role: Student ──────────────────────────────────────────
INSERT IGNORE INTO roles (role_key, role_label, level, description) VALUES
('student', 'Student', 7, 'Student who can submit project proposals and book appointments');

-- ================================================================
-- ANNOUNCEMENTS
-- ================================================================
CREATE TABLE IF NOT EXISTS announcements (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    body        TEXT NOT NULL,
    type        ENUM('general','urgent','deadline','event') DEFAULT 'general',
    target      ENUM('all','admin','faculty','student') DEFAULT 'all',
    created_by  INT UNSIGNED NOT NULL,
    is_active   TINYINT(1) DEFAULT 1,
    pinned      TINYINT(1) DEFAULT 0,
    expires_at  DATETIME NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- ================================================================
-- STUDENT PROJECT PROPOSALS
-- ================================================================
CREATE TABLE IF NOT EXISTS proposals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proposal_code   VARCHAR(50) UNIQUE,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    type            ENUM('research','capstone','thesis','internship','other') DEFAULT 'research',
    submitted_by    INT UNSIGNED NOT NULL,   -- student user id
    program_id      INT UNSIGNED,
    adviser_id      INT UNSIGNED,            -- faculty/staff reviewer
    status          ENUM('draft','submitted','under_review','revision_requested','approved','rejected','archived') DEFAULT 'draft',
    file_path       VARCHAR(500),
    file_name       VARCHAR(255),
    file_size       INT UNSIGNED,
    review_comments TEXT,
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME NULL,
    submitted_at    DATETIME NULL,
    deadline        DATE NULL,
    academic_year   VARCHAR(20),
    deleted_at      DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (program_id)   REFERENCES programs(id) ON DELETE SET NULL,
    FOREIGN KEY (adviser_id)   REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by)  REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS proposal_reviews (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    decision    ENUM('approved','revision_requested','rejected') NOT NULL,
    comments    TEXT,
    round       TINYINT UNSIGNED DEFAULT 1,
    reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ================================================================
-- SCHEDULING / APPOINTMENTS
-- ================================================================
CREATE TABLE IF NOT EXISTS appointment_slots (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slot_date   DATE NOT NULL,
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    location    VARCHAR(255) DEFAULT 'QA Office',
    capacity    TINYINT UNSIGNED DEFAULT 1,   -- how many bookings allowed
    purpose     VARCHAR(255),                 -- e.g. "Document Consultation"
    is_active   TINYINT(1) DEFAULT 1,
    created_by  INT UNSIGNED,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS appointments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slot_id         INT UNSIGNED NOT NULL,
    booked_by       INT UNSIGNED NOT NULL,   -- who booked
    purpose         VARCHAR(255),
    notes           TEXT,
    status          ENUM('pending','confirmed','cancelled','completed','no_show') DEFAULT 'pending',
    cancelled_by    INT UNSIGNED NULL,
    cancel_reason   TEXT,
    confirmed_by    INT UNSIGNED NULL,
    confirmed_at    DATETIME NULL,
    reminder_sent   TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (slot_id)       REFERENCES appointment_slots(id) ON DELETE CASCADE,
    FOREIGN KEY (booked_by)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (cancelled_by)  REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (confirmed_by)  REFERENCES users(id) ON DELETE SET NULL
);

-- Room reservations (for reserving the QA office / meeting rooms)
CREATE TABLE IF NOT EXISTS room_reservations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_name       VARCHAR(150) DEFAULT 'QA Office',
    reserved_by     INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,   -- meeting title/purpose
    description     TEXT,
    attendees       TEXT,                    -- JSON or comma-separated
    start_datetime  DATETIME NOT NULL,
    end_datetime    DATETIME NOT NULL,
    status          ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    approved_by     INT UNSIGNED NULL,
    approved_at     DATETIME NULL,
    reject_reason   TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reserved_by)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by)  REFERENCES users(id) ON DELETE SET NULL
);
