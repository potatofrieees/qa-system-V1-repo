-- ============================================================
--  Migration: Remove Majors / Departments concept
--  Programs now link directly to Colleges
--  Run once on existing database
-- ============================================================

USE qa_system;

-- 1. Add college_id directly to programs (nullable during migration)
ALTER TABLE programs
    ADD COLUMN college_id INT UNSIGNED NULL AFTER department_id;

-- 2. Populate college_id from the departments chain
UPDATE programs p
JOIN departments d ON d.id = p.department_id
SET p.college_id = d.college_id;

-- 3. Make college_id NOT NULL and add FK now that it's populated
ALTER TABLE programs
    MODIFY COLUMN college_id INT UNSIGNED NOT NULL;

ALTER TABLE programs
    ADD CONSTRAINT fk_programs_college
    FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE;

-- 4. Make department_id nullable (keep column for safe FK, but we no longer require it)
ALTER TABLE programs
    MODIFY COLUMN department_id INT UNSIGNED NULL;

-- 5. Drop has_major from colleges (no longer needed)
ALTER TABLE colleges DROP COLUMN IF EXISTS has_major;

-- 6. Drop department_id from users (no longer needed)
ALTER TABLE users DROP FOREIGN KEY IF EXISTS fk_users_dept;
-- try common FK name variants
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='department_id' LIMIT 1);
SET @sql = IF(@fk IS NOT NULL, CONCAT('ALTER TABLE users DROP FOREIGN KEY ', @fk), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE users DROP COLUMN IF EXISTS department_id;

-- Done
SELECT 'Migration complete.' AS status;
