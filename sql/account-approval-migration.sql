-- Run once on existing installations to preserve current accounts as accepted
-- while making future registrations pending by default.
ALTER TABLE users
  ADD COLUMN account_status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'accepted'
  AFTER role;

ALTER TABLE users
  ALTER COLUMN account_status SET DEFAULT 'pending';
