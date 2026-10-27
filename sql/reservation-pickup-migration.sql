ALTER TABLE reservations
  ADD COLUMN pickup_at DATETIME NULL AFTER reserved_at;
