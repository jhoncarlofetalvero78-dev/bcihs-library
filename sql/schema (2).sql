-- BCIHS Library Management System — database schema
-- Import this into your MySQL server before using the PHP pages.

CREATE DATABASE IF NOT EXISTS bcihs_library
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bcihs_library;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name      VARCHAR(150)  NOT NULL,
  email          VARCHAR(190)  NULL UNIQUE,
  student_id     VARCHAR(50)   NULL UNIQUE,
  year_section   VARCHAR(50)   NULL,
  id_number      VARCHAR(50)   NULL UNIQUE,
  department     VARCHAR(150)  NULL,
  phone          VARCHAR(30)   NULL,
  avatar_path    VARCHAR(255)  NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  role           ENUM('student','teacher','librarian','staff','employee','visitor') NOT NULL,
  account_status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  reset_code         VARCHAR(10)  NULL,
  reset_code_expires TIMESTAMP    NULL,
  last_login     TIMESTAMP     NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Attendance snapshots preserve visit details; departure tracking is optional.
CREATE TABLE IF NOT EXISTS attendance (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NULL,
  student_name        VARCHAR(150) NOT NULL,
  year_level          VARCHAR(20)  NOT NULL,
  section             VARCHAR(25)  NOT NULL,
  student_identifier  VARCHAR(50)  NULL,
  time_in             DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  year_section_snapshot VARCHAR(50) NULL,
  tracks_departure TINYINT UNSIGNED NOT NULL DEFAULT 0,
  time_out DATETIME NULL,
  submission_key CHAR(32) NULL,
  submission_hash CHAR(64) NULL,
  UNIQUE KEY uq_attendance_submission (submission_key),
  INDEX idx_attendance_open (tracks_departure, time_out),
  INDEX idx_attendance_time_in (time_in),
  INDEX idx_attendance_user_time (user_id, time_in),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- If you already created the table before this update, run this instead:
-- ALTER TABLE users MODIFY email VARCHAR(190) NULL;
-- ALTER TABLE users ADD COLUMN student_id VARCHAR(50) NULL UNIQUE AFTER email;
-- ALTER TABLE users ADD COLUMN year_section VARCHAR(50) NULL AFTER student_id;
-- ALTER TABLE users ADD COLUMN id_number VARCHAR(50) NULL UNIQUE AFTER year_section;
-- ALTER TABLE users ADD COLUMN department VARCHAR(150) NULL AFTER id_number;
-- ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER department;
-- ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER phone;
-- ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL AFTER role;
-- For an existing installation, run sql/account-approval-migration.sql.

-- Separate table for Admin Login — intentionally not part of `users`,
-- since admins aren't library patrons and use a username instead of
-- email/Student ID/ID Number.
CREATE TABLE IF NOT EXISTS admins (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255)  NOT NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Book inventory, managed from the admin dashboard's Manage Book page.
-- borrowed_copies is tracked directly on the book row for now (no separate
-- per-loan transaction table yet); available_copies is always computed as
-- total_copies - borrowed_copies rather than stored, so it can never drift.
CREATE TABLE IF NOT EXISTS books (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title                 VARCHAR(200)  NOT NULL,
  author                VARCHAR(150)  NOT NULL,
  edition               VARCHAR(50)   NULL,
  place_of_publication  VARCHAR(150)  NULL,
  publisher             VARCHAR(150)  NULL,
  year_of_publication   VARCHAR(10)   NULL,
  isbn                  VARCHAR(17)   NULL,
  category              VARCHAR(80)   NULL,
  call_number           VARCHAR(120)  NULL,
  cutter_number         VARCHAR(50)   NULL,
  accession_number      VARCHAR(50)   NULL,
  image_path            VARCHAR(255)  NULL,
  ddc                   VARCHAR(30)   NULL,
  genre                 VARCHAR(100)  NULL,
  total_copies          INT UNSIGNED  NOT NULL DEFAULT 1,
  borrowed_copies       INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- If you already created the books table before this update, run this instead:
-- ALTER TABLE books ADD COLUMN edition VARCHAR(50) NULL AFTER author;
-- ALTER TABLE books ADD COLUMN place_of_publication VARCHAR(150) NULL AFTER edition;
-- ALTER TABLE books ADD COLUMN publisher VARCHAR(150) NULL AFTER place_of_publication;
-- ALTER TABLE books ADD COLUMN year_of_publication VARCHAR(10) NULL AFTER publisher;
-- ALTER TABLE books ADD COLUMN isbn VARCHAR(17) NULL AFTER author;
-- ALTER TABLE books ADD COLUMN category VARCHAR(80) NULL AFTER isbn;
-- ALTER TABLE books ADD COLUMN call_number VARCHAR(120) NULL AFTER category;
-- ALTER TABLE books ADD COLUMN cutter_number VARCHAR(50) NULL AFTER call_number;
-- ALTER TABLE books ADD COLUMN accession_number VARCHAR(50) NULL AFTER call_number;
-- ALTER TABLE books ADD COLUMN image_path VARCHAR(255) NULL AFTER accession_number;
-- ALTER TABLE books ADD COLUMN ddc VARCHAR(30) NULL AFTER isbn;
-- ALTER TABLE books ADD COLUMN genre VARCHAR(100) NULL AFTER ddc;

-- A patron's request to borrow a book, and the admin decision on it.
-- Approval holds a copy for pickup; only explicit checkout increments borrowed_copies;
-- marking a checked-out loan returned decrements it back down. user_id points at `users`
-- when the patron has an account. Staff-entered records can store a typed
-- borrower_name without creating a user account.
-- approved_at/rejected_at/returned_at are separate columns (rather than one
-- shared "processed_at") so the Transaction report can still show the
-- approval date after a book has been returned.
CREATE TABLE IF NOT EXISTS borrow_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NULL,
  borrower_name  VARCHAR(150)  NULL,
  book_id        INT UNSIGNED  NOT NULL,
  status         ENUM('pending','approved','rejected','returned','awaiting_pickup','checked_out') NOT NULL DEFAULT 'pending',
  requested_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at    TIMESTAMP     NULL,
  checked_out_at TIMESTAMP     NULL,
  due_date       TIMESTAMP     NULL,
  rejected_at    TIMESTAMP     NULL,
  returned_at    TIMESTAMP     NULL,
  pickup_token   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  UNIQUE KEY uq_borrow_pickup_token (pickup_token),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Existing installations: apply sql/borrowing-lifecycle-migration.sql after backup.
-- The legacy approved status means already checked out; new approvals use awaiting_pickup.
-- If you already created this table with the old single `processed_at`
-- column, run this instead:
-- ALTER TABLE borrow_requests CHANGE processed_at approved_at TIMESTAMP NULL;
-- ALTER TABLE borrow_requests ADD COLUMN due_date TIMESTAMP NULL AFTER approved_at;
-- ALTER TABLE borrow_requests ADD COLUMN rejected_at TIMESTAMP NULL AFTER approved_at;
-- ALTER TABLE borrow_requests ADD COLUMN returned_at TIMESTAMP NULL AFTER rejected_at;
-- -- Move any returned rows' approval date out of the old shared column:
-- UPDATE borrow_requests SET returned_at = approved_at, approved_at = NULL WHERE status = 'returned';
-- -- Move any rejected rows' date out of the old shared column:
-- UPDATE borrow_requests SET rejected_at = approved_at, approved_at = NULL WHERE status = 'rejected';

-- Library announcements shown on the public homepage and managed from the
-- admin dashboard. posted_by references a library staff user when one exists;
-- standalone admin accounts are not linked to users and are stored as NULL.
CREATE TABLE IF NOT EXISTS announcements (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(200)  NOT NULL,
  body           TEXT          NOT NULL,
  category       ENUM('General','Event','Notice') NOT NULL DEFAULT 'General',
  image_path     VARCHAR(255)  NULL,
  posted_by      INT UNSIGNED  NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- A patron's place in line when no unallocated copy is available: copies may
-- already be checked out or held for another pickup. 'waiting' = in the queue; 'ready'
-- = admin has held an available copy for this reservation specifically; 'fulfilled' = the patron actually checked it out (this
-- increments the book's borrowed_copies, same as an approved borrow
-- request); 'returned' = library staff confirmed the book was returned;
-- 'cancelled' = withdrawn by patron or admin. Queue position is computed on
-- read (order of 'waiting' rows per book by reserved_at), not stored, so it
-- never needs to be renumbered.
CREATE TABLE IF NOT EXISTS reservations (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  book_id        INT UNSIGNED  NOT NULL,
  status         ENUM('waiting','ready','fulfilled','returned','cancelled') NOT NULL DEFAULT 'waiting',
  reserved_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pickup_at      DATETIME      NULL,
  ready_at       TIMESTAMP     NULL,
  fulfilled_at   TIMESTAMP     NULL,
  due_date       TIMESTAMP     NULL,
  returned_at    TIMESTAMP     NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- For existing databases, apply sql/reservation-return-migration.sql and
-- sql/reservation-pickup-migration.sql when those fields are missing.

-- Outbound SMS notification log (SMS feature). Every send attempt is
-- recorded here — success, failure, or skipped (e.g. no phone on file, or
-- the Semaphore API key hasn't been configured yet) — so the admin SMS Logs
-- page always shows what actually happened, not just what was attempted.
-- user_id is nullable + SET NULL so deleting a user never deletes their
-- SMS history, it just detaches it.
CREATE TABLE IF NOT EXISTS sms_logs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NULL,
  phone          VARCHAR(30)   NOT NULL,
  message        TEXT          NOT NULL,
  status         ENUM('sent','failed','skipped') NOT NULL,
  response       TEXT          NULL,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Optional pickup quotas; NULL means unlimited.
-- NULL limits retain the original unlimited pickup scheduling policy.
CREATE TABLE IF NOT EXISTS reservation_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  daily_limit SMALLINT UNSIGNED NULL,
  slot_limit SMALLINT UNSIGNED NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO reservation_settings (id, daily_limit, slot_limit) VALUES (1, NULL, NULL);

-- Phase 5 attendance policy: unlimited visits, departure tracking off by default.
CREATE TABLE IF NOT EXISTS attendance_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  daily_visit_limit SMALLINT UNSIGNED NULL,
  occupancy_limit SMALLINT UNSIGNED NULL,
  track_departures TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT IGNORE INTO attendance_settings (id) VALUES (1);
