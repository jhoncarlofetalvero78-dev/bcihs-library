-- Truncate all data tables (preserves schema)
USE bcihs_library;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE borrow_requests;
TRUNCATE TABLE books;
TRUNCATE TABLE users;
TRUNCATE TABLE admins;

SET FOREIGN_KEY_CHECKS = 1;