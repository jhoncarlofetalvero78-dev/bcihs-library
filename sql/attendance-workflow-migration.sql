-- Additive Phase 5 migration. Run the local backup/migration script, not a reimport.
ALTER TABLE attendance
  ADD COLUMN year_section_snapshot VARCHAR(50) NULL,
  ADD COLUMN tracks_departure TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN time_out DATETIME NULL,
  ADD COLUMN submission_key CHAR(32) NULL,
  ADD COLUMN submission_hash CHAR(64) NULL,
  ADD UNIQUE KEY uq_attendance_submission (submission_key),
  ADD INDEX idx_attendance_open (tracks_departure, time_out);

CREATE TABLE attendance_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  daily_visit_limit SMALLINT UNSIGNED NULL,
  occupancy_limit SMALLINT UNSIGNED NULL,
  track_departures TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO attendance_settings (id) VALUES (1);
