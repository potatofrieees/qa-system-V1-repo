-- ── Mail Feature Migration ─────────────────────────────────────
-- Run this if you already have the database installed from a
-- previous version. It extends the otp_code column to hold
-- 64-char password-reset tokens.

USE qa_system_new;

ALTER TABLE users
    MODIFY otp_code VARCHAR(128) DEFAULT NULL;

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'qa_system_new'
  AND TABLE_NAME   = 'users'
  AND COLUMN_NAME  = 'otp_code';
