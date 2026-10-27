-- Phase 2B: additive change only. Back up first; use scripts/migrate-borrowing.php locally.
-- Existing 'approved' rows remain legacy checked-out loans; no dates or rows are rewritten.
ALTER TABLE borrow_requests
  MODIFY status ENUM('pending','approved','rejected','returned','awaiting_pickup','checked_out') NOT NULL DEFAULT 'pending',
  ADD COLUMN checked_out_at TIMESTAMP NULL AFTER approved_at;
