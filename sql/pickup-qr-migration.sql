-- One persistent random reference per approved borrowing request; no time expiry.
ALTER TABLE borrow_requests
  ADD COLUMN pickup_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD UNIQUE KEY uq_borrow_pickup_token (pickup_token);
