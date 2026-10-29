# Local development setup — Phase 1

Work on `development`. Keep this setup local; do not upload the development
`includes/db-local.php` file or `scripts/` directory to production.

## Start MAMP

1. Open MAMP and click **Start Servers**. Use Apache port `8888`, MySQL port
   `8889`, and PHP `8.3.30` with PDO MySQL enabled.
2. The project folder is `/Applications/MAMP/htdocs/bcihs-library`.
3. Open <http://localhost:8888/bcihs-library/> through Apache. Do not open the
   HTML files directly.

The schema is already imported on this Mac. Do not reimport it or run
`sql/truncate-data.sql` against the existing database.

## Configure the private database file

`includes/db-local.php` is prepared and ignored by Git. On another checkout,
copy `includes/db-local.example.php` to that name. Keep its PHP format and
its direct-request guard. Use these MAMP values:

| Setting | Value |
| --- | --- |
| `BCIHS_DB_HOST` | `127.0.0.1` |
| `BCIHS_DB_PORT` | `8889` |
| `BCIHS_DB_NAME` | `bcihs_library` |
| `BCIHS_DB_USER` | Your local MySQL username |
| `BCIHS_DB_PASS` | Your actual local MySQL password, entered privately |

The example does not supply a password. Do not paste credentials into chat,
commit them, put them in URLs, or pass them as command arguments. Inside a PHP
single-quoted string, escape a quote as `\'` and a backslash as `\\`.

On this Mac:

```sh
cd /Applications/MAMP/htdocs/bcihs-library
chmod 600 includes/db-local.php
git check-ignore includes/db-local.php
```

Settings come from environment variables first, then the private file, then
existing XAMPP defaults (`localhost`, `bcihs_library`, `root`, empty password,
no explicit port). An explicitly empty environment value overrides the file too.
Use `127.0.0.1` when selecting a TCP port; `localhost` may use a Unix socket.
CLI PHP and Apache may have different environments, so test both.

`db_config.php` selects settings without connecting. `db_connect.php` adds the
optional port to the PDO connection string. Ports must be `1`–`65535`.
Prepared statements and existing PDO options are preserved. Connection errors
show a generic message and log no raw PDO details. No packages are required.

## Check the backend

From the project folder, run:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/check-local-db.php
```

It reads metadata and checks expected columns without changing records or
running migrations. Exit code `0` means base-schema checks passed; `1` means a
connection/table/column problem. It does not verify every constraint or workflow.

In a signed-out browser, open
<http://localhost:8888/bcihs-library/backend/admin-session-status.php>.
Expect HTTP `200` and `{"loggedIn":false}`. A connection failure returns HTTP
`500` with generic text; check your private settings, MAMP service, and PHP log.
A static homepage returning `200` alone does not prove MySQL works.

## Create your local administrator

Run in an interactive Terminal:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/create-local-admin.php
```

Choose a new username (3–100 letters, numbers, dots, underscores, or hyphens).
Enter a unique password twice and save it in your password manager. Neither
entry is displayed. Use 12–72 bytes; ASCII characters use one byte each. The
72-byte limit avoids truncation by PHP's current default bcrypt algorithm.

The command inserts one `admins` row using a prepared statement and
`password_hash(..., PASSWORD_DEFAULT)`. A hash checks a password without saving
its original text. The existing login already uses `password_verify()`.
Existing usernames are refused; no accounts are reset or deleted.

Hidden input uses `stty` on macOS/Linux and PowerShell on Windows. PHP needs
`shell_exec`, plus `exec` on macOS/Linux. Passwords are never shell arguments.
If interrupted terminal input stops displaying characters, run `stty sane`.
Both commands refuse remote hosts and database names other than `bcihs_library`
before connecting. Web requests to the commands receive `404`.

`sql/admin-account.sql` now contains instructions only; it creates or resets no
accounts. Older Git versions contained a predefined administrator hash. If that
credential is still used by any installation, rotate it through an authorized
private procedure. Use the interactive helper above to create a new local account.

## Verify your login

1. Sign in at <http://localhost:8888/bcihs-library/admin-login.html>. Expect
   `admin-dashboard.html` after successful authentication.
2. In the same browser, open `backend/admin-session-status.php`. Expect
   `loggedIn: true`, your username, and `role: "admin"`.
3. Open `backend/announcements-data.php`. Expect HTTP `200` with an
   `announcements` JSON array; an empty array is valid.
4. Sign out. `backend/attendance-data.php` should return HTTP `401` with
   `{"error":"not_logged_in"}`. Compare the existing homepage and login UI.

Avoid book endpoints as schema tests: existing `books-data.php`,
`book-save.php`, and `public-books-data.php` contain automatic `ALTER TABLE`
logic. Use the read-only checker first. Do not submit borrowing, reservation,
attendance, SMS, or password-reset forms for this setup check.

## XAMPP for another developer

1. Put the project in XAMPP's `htdocs/bcihs-library`, use `development`, and
   start Apache and MySQL.
2. Only for a **new, empty local database**, import `sql/schema (2).sql` through
   phpMyAdmin. Back up and inspect an existing database instead of reimporting.
3. Copy the config example to `includes/db-local.php`. Use `127.0.0.1`, your
   MySQL port (commonly `3306`), actual username/password, and `bcihs_library`.
4. Open <http://localhost/bcihs-library/>; include Apache's port if different.
5. Run the same commands with XAMPP's PHP. On Windows, from the project folder:

   ```bat
   C:\xampp\php\php.exe scripts\check-local-db.php
   C:\xampp\php\php.exe scripts\create-local-admin.php
   ```

   On macOS XAMPP, use `/Applications/XAMPP/xamppfiles/bin/php`. Enable PDO
   MySQL if PHP reports that the driver is unavailable.

## Schema findings and safe migration plan

The schema defines all eight required tables: `admins`, `users`, `books`,
`borrow_requests`, `reservations`, `attendance`, `announcements`, and `sms_logs`.
Their base columns support current admin login and the active workflows.

The older `backend/forgot-password-process.php` additionally expects four
`users` columns absent from the schema: `reset_token`, `reset_expires`,
`otp_code`, and `otp_expires`. It also puts the OTP in a redirect URL. Current
`js/forgot-password.js` calls `forgot-password-request.php`, which shares the
existing `reset_code` fields with `reset-password-process.php`. Adding legacy
columns alone would not reconcile the different reset flows.

No migration is needed for this admin setup or normal admin login. If a later
phase requires schema changes:

1. Back up the local database and compare `SHOW CREATE TABLE` / `SHOW COLUMNS`
   results with the affected endpoints. Decide which reset flow to retain.
2. Review only missing-column additions on a backup copy. The older handler
   would need nullable `reset_token VARCHAR(255)`, `reset_expires DATETIME`,
   `otp_code VARCHAR(255)`, and `otp_expires DATETIME` on `users`. Admin OTP
   columns are not needed for current admin login. Check SQL compatibility
   before using `sql/password-reset-migration.sql`.
3. Review the specific migration before applying it to the existing database.
   Preserve records, compare row counts/constraints, and rerun verification.
   No migration has been applied in Phase 1.

InfinityFree's existing environment-variable mechanism remains supported;
`BCIHS_DB_PORT` is optional. No production connection or deployment is needed.
See [DEPLOYMENT.md](DEPLOYMENT.md) for hosting configuration.

## Verified results on this Mac

- Initially: homepage HTTP `200`, database-backed session request HTTP `500`.
- After private configuration: database checker exited `0`; all eight tables
  and base columns were accessible. Only the older reset handler's four extra
  columns were missing. Expected role/status enums, nullable manual-borrower
  `user_id`, and `call_number VARCHAR(120)` were also confirmed read-only.
- All 47 PHP files passed syntax checks. Defaults, private configuration,
  environment overrides, empty passwords, invalid ports, and PDO connection
  option validation passed. Remote hosts were refused before connecting.
- Session and announcements endpoints returned expected `200` JSON; signed-out
  attendance/users requests returned `401` JSON; reset handlers refused GET
  with `405` JSON. Invalid admin credentials produced the existing error redirect.
- Private config returned HTTP `403`; CLI scripts returned `404` over HTTP.
  macOS hidden input did not echo passwords. Invalid usernames, short passwords,
  and mismatched confirmation were refused without changing the admin count.
  No existing admin was available to test duplicate refusal; its code was reviewed.
- Homepage/login rendered with the existing styling. Homepage HTML was unchanged.
  No HTML, CSS, JavaScript, endpoint, or SQL workflow sources changed.
- **Manual checks remaining:** create your own administrator and verify a
  successful sign-in. No administrator was installed. Windows/XAMPP and
  production hosting were not exercised.
