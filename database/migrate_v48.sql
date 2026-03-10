-- ================================================================
-- QA System v48 Migration
-- Run AFTER setup.sql and migrate_new_features.sql
-- ================================================================

-- ── Student Role (if not already added) ─────────────────────
INSERT IGNORE INTO roles (role_key, role_label, level, description) VALUES
('student', 'Student', 7, 'Student who can self-register, book appointments, and submit proposals');

-- ── Schedule Blackout Dates ──────────────────────────────────
CREATE TABLE IF NOT EXISTS schedule_blackouts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blackout_date DATE NOT NULL UNIQUE,
    reason        VARCHAR(255),
    type          ENUM('holiday','event','maintenance','other') DEFAULT 'other',
    created_by    INT UNSIGNED,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ── Allow users without employee_id (students register themselves) ──
ALTER TABLE users MODIFY COLUMN employee_id VARCHAR(50) NULL DEFAULT NULL;

-- ── Add student_id column if needed ─────────────────────────
ALTER TABLE users ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL AFTER employee_id;

-- ── Add attendees to room_reservations if not already there ─
ALTER TABLE room_reservations ADD COLUMN IF NOT EXISTS attendees TEXT NULL AFTER description;
ALTER TABLE room_reservations ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED NULL;
ALTER TABLE room_reservations ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL;

-- ================================================================
-- DONE
-- ================================================================

-- ── Open-day slot flag ───────────────────────────────────────
-- When is_open_day=1, the slot covers a window and users pick their own time
ALTER TABLE appointment_slots ADD COLUMN IF NOT EXISTS is_open_day TINYINT(1) DEFAULT 0;
-- For appointments made on open-day slots, store the user-chosen time
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS chosen_start TIME NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS chosen_end   TIME NULL;
