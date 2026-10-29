-- NULL limits retain the original unlimited pickup scheduling policy.
CREATE TABLE IF NOT EXISTS reservation_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  daily_limit SMALLINT UNSIGNED NULL,
  slot_limit SMALLINT UNSIGNED NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO reservation_settings (id, daily_limit, slot_limit) VALUES (1, NULL, NULL);
