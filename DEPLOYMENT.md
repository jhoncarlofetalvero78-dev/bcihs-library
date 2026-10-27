# InfinityFree deployment

InfinityFree cannot connect to the MySQL server inside your local XAMPP
installation. Export the local database and import it into the MySQL database
created in the InfinityFree control panel.

The HTML pages remain in the project root, with page stylesheets in `css/` and
page scripts in `js/`. PHP request handlers are in `backend/`, with shared PHP
files in the root `includes/` folder; serve the project through PHP-enabled
Apache rather than opening the HTML files directly.

## Configure the live database

1. The current InfinityFree database settings shown in the account panel are
   `sql210.infinityfree.com`, `if0_43204300_bcihslibrary`, and
   `if0_43204300`. Verify they still match the panel if the database is
   recreated or renamed.
2. Set `BCIHS_DB_HOST`, `BCIHS_DB_NAME`, `BCIHS_DB_USER`, and `BCIHS_DB_PASS`
   to the live database credentials in the PHP environment. If the host does
   not support environment variables, replace the local fallback values in
   `includes/db_connect.php` privately before uploading; do not commit or share
   the password. The connection defaults in that file are for local XAMPP
   (`localhost`, `bcihs_library`, `root`, and an empty password).
3. In phpMyAdmin, select the database created by InfinityFree. Import a copy of
   `sql/schema (2).sql` after removing its `CREATE DATABASE` and
   `USE bcihs_library` statements; InfinityFree supplies the database and uses
   its assigned, prefixed name.
4. The supplied schema already defines account approval and reservation return
   fields. Run `sql/password-reset-migration.sql` against the selected database
   (remove its `USE bcihs_library` statement first). For an older existing
   database, check its columns before applying
   `sql/account-approval-migration.sql`,
   `sql/reservation-return-migration.sql`, or
   `sql/reservation-pickup-migration.sql`; apply each only when its
   corresponding fields are missing. Back up an existing live database before
   applying migrations. For an existing database, also run
   `sql/manual-borrower-name-migration.sql` to allow manually entered borrower
   names without user accounts, `sql/book-ddc-migration.sql` to add Dewey
   Decimal Classification to books, and `sql/announcement-photo-migration.sql`
   before using announcement photos.
   Run `sql/attendance-migration.sql` to enable the admin attendance page and
   add separate year-level and section fields to attendance records. The
   migration also adds these fields to an existing attendance table; historical
   rows will have them blank until updated.
   Ensure the PHP process can write to the site's `uploads/announcements/`
   directory; it is created automatically when the first photo is uploaded.
5. Confirm the PHP account has access to the selected database and that the
   `users` table exists. Registration requires `student_id`, `year_section`,
   `id_number`, `department`, `phone`, `password_hash`, and `role` columns.
6. Select the target database and run `sql/admin-account.sql` to create or
   update the `libraryadmin` login. The script stores a password hash; running
   it again resets that account's password. Change the seeded password before
   exposing a public deployment.

## Configure Semaphore SMS

1. Get the API key from your Semaphore account.
2. In the deployed site, open `includes/semaphore-api-key.php` and change the
   empty string to your key, for example `return 'YOUR_KEY_HERE';`. Do not
   share the key or commit this file; it is excluded by `.gitignore`.
3. Confirm the account has SMS credits and that the recipient's phone number
   is correct. Trigger an SMS feature and check the admin SMS Logs page.
4. If the log says `failed`, inspect its response and the hosting PHP error
   log. A `sent` status means Semaphore accepted the API request, not
   necessarily that the carrier delivered the message.

## Diagnose registration failures

Open the browser's Developer Tools, select **Network**, submit the registration
form, and inspect the `backend/register-process.php` response and status. A redirect
back to the form with “Registration could not be completed” means the SQL
operation failed. Check the InfinityFree account's PHP error log for the
corresponding `Registration database operation failed` or `Database connection
failed` entry. The full PDO error is logged server-side and is deliberately not
shown to visitors.

Common log messages:

- **Access denied**: the database username or password is wrong, or the user
  was not granted access to that database.
- **Unknown database / connection refused**: use the exact database name and
  MySQL hostname from InfinityFree, not the XAMPP database settings.
- **Table or column does not exist**: import the schema and run the listed
  migrations against the InfinityFree database.
- **Duplicate entry**: the generated student/teacher ID or visitor email is
  already present; retry with a new registration ID or email.
