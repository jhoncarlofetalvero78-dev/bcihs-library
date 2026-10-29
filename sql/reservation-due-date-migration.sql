-- Phase 2B: preserve historical reservations; only future checkouts receive a due date.
ALTER TABLE reservations ADD COLUMN due_date TIMESTAMP NULL AFTER fulfilled_at;
