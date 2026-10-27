ALTER TABLE reservations
  MODIFY status ENUM('waiting','ready','fulfilled','returned','cancelled') NOT NULL DEFAULT 'waiting',
  ADD COLUMN returned_at TIMESTAMP NULL AFTER fulfilled_at;
