-- Add this table to an existing BCIHS Library database.
CREATE TABLE IF NOT EXISTS attendance (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NULL,
  student_name        VARCHAR(150) NOT NULL,
  year_level          VARCHAR(20)  NOT NULL,
  section             VARCHAR(25)  NOT NULL,
  student_identifier  VARCHAR(50)  NULL,
  time_in             DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attendance_time_in (time_in),
  INDEX idx_attendance_user_time (user_id, time_in),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @attendance_year_level_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE attendance ADD COLUMN year_level VARCHAR(20) NULL AFTER student_name',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'attendance'
    AND COLUMN_NAME = 'year_level'
);
PREPARE attendance_year_level_stmt FROM @attendance_year_level_sql;
EXECUTE attendance_year_level_stmt;
DEALLOCATE PREPARE attendance_year_level_stmt;

SET @attendance_section_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE attendance ADD COLUMN section VARCHAR(25) NULL AFTER year_level',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'attendance'
    AND COLUMN_NAME = 'section'
);
PREPARE attendance_section_stmt FROM @attendance_section_sql;
EXECUTE attendance_section_stmt;
DEALLOCATE PREPARE attendance_section_stmt;
