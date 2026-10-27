-- Run after creating the admins table, with the target database selected.
-- Re-running this script resets the libraryadmin password to the configured value.
INSERT INTO admins (username, password_hash)
VALUES ('libraryadmin', '$2y$10$TSJlvCMvJgglKeUOV2lN5eqG6n6Wq.cbfh4FBtxnSJuGIhZ8On7fe')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);
