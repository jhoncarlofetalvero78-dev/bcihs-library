-- Allows staff to add a borrow record using a typed borrower name
-- without requiring a library user account.
ALTER TABLE borrow_requests
  MODIFY user_id INT UNSIGNED NULL,
  ADD COLUMN borrower_name VARCHAR(150) NULL AFTER user_id;
