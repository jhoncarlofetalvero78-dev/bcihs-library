# Final refined Hostinger deployment candidate — 29 October 2026

The selected starting data is **only `bcihs_demo_20261028_236ee935`**. The content was exported directly from its finalized rows, never recreated from an old seed or copied from development. This package is prepared locally, closed by default, and **has not been deployed or tested on Hostinger**.

## Deliverables and exact counts

Private release folder: `/Users/macbookprom116512/.codex/bcihs-hostinger/release-20261029-01`.

- Verified portable SQL: `bcihs-refined.sql`.
- Clean upload candidate: `bcihs-hostinger-application.zip` (extracts sibling `public_html/` and `bcihs-private/` directories).
- Uncompressed candidate: `application/`.
- SQL restore evidence: `database-verification.json`.
- Restored-application evidence: `application-verification.json`.
- Complete file checksums: `application-manifest.json`; archive checksum: `archive-verification.json`.
- Private exact working-tree/checkpoint lists: `checkpoint-manifest.json`, `working-tree-status.txt`.

The SQL deliberately contains no CREATE DATABASE, USE, DROP TABLE, server DEFINER, local filesystem paths, sessions, API keys, guard ledgers or plaintext passwords. It preserves table definitions, indexes, foreign keys, enums, UTF-8 data and timestamps; instance AUTO_INCREMENT counters are omitted. Import only into a **new empty** database. It contains the two existing staging password hashes, so it is sensitive and belongs outside the public web root even though no plaintext passwords are included.

| Table | Final exported rows |
| --- | ---: |
| admins | 1 |
| users | 1 |
| books | 10 |
| announcements | 4 |
| attendance_settings | 1 |
| reservation_settings | 1 |
| sms_notification_settings | 1 |
| borrow_requests | 0 |
| reservations | 0 |
| attendance | 0 |
| sms_logs | 0 |
| sms_notification_audit | 0 |

There are **24 available physical copies**, zero borrowed/held copies, one accepted student (`user`, Library Reader) and one administrator (`admin`). Synthetic accession/shelfmarks and verified edition metadata remain as documented in STAGING-CONTENT-REFINEMENT.md; no real school ownership is implied. All ten book covers and four original uncropped announcement banners are included with identical paths and hashes. The newest two announcements are Welcome to the BCIHS Library Portal and How to Borrow Books; View All retains all four. No history was fabricated to populate reports.

## Local preservation

Both SQL export and packaged-application verification used separately created disposable verification databases. The first import compared every restored row hash with finalized staging. A second import exercised actual packaged PHP endpoints, with fresh test-only credentials and a database-scoped temporary user. Only that disposable import received checkout/reservation/attendance/upload verification actions. Its database, database user, process and temporary files were removed. No table was reset or changed in development or persistent staging.

`bcihs_library` still matches the original preparation baseline across all twelve tables. Staging still matches its finalized clean snapshot. Original database backup checksum and image/upload contents are preserved. Local familiar credentials, SMS history, student #364, log #265, consumed account:364/account:368 claims and Phase 11 deferral remain untouched. The source private SMS configuration stays UniSMS Live; the separate Semaphore API key remains private and BCIHSLIB remains pending. Only the dedicated safe checker was used to inspect SMS readiness. No SMS provider was contacted, message sent or credit consumed.

The loopback staging site at port 8899 remains separate and functional. The original MAMP application connection was not switched. No Git checkpoint or main reference was changed.

## Candidate layout and deployment-only adaptations

```
website-directory/
  public_html/                 # Only this directory is the website document root
    index.html
    backend/ css/ js/ images/
    demo-assets/               # 10 covers, 4 banners, staff staging indicator, license
    includes/                  # PHP helpers, denied to HTTP
    uploads/books/             # Empty apart from executable-denial rules
    uploads/announcements/     # Empty apart from executable-denial rules
    .htaccess                  # Require all denied until protected setup is approved
    .user.ini
  bcihs-private/               # SIBLING of public_html, never beneath it
    config.example.php         # Blank hPanel DB fields and blank HTTPS URL
    config.php                 # Administrator creates privately; absent from archive
    rotate-credentials.php     # CLI-only one-time imported-account rotation
    activation.json            # Created by rotation; absent from archive
    sessions/ rate-limits/      # Private writable runtime storage
    vendor/                    # Locked Composer dependencies and license files
    composer.json composer.lock
```

The builder copies the current working application source, preserving the accumulated client UI, due-date, attendance/report and notification improvements. Finalized image files come from the reviewed staging copy, not original development uploads. It excludes Git, SQL, backups, tests/scripts/tools, local private configurations, claims, sessions, recipient lists, personal records and original upload directories. The public assets contain no full Philippine-number literals. The archive is an upload candidate from the current uncommitted working tree, not a claim that all prior changes already have a Git checkpoint.

Deployment adaptations exist only inside the generated candidate; original business source/configuration remains unchanged:

- Private `config.php` supplies hosting DB credentials and the exact HTTPS pickup base URL. The hosting DB loader has **no local root/database fallback** and refuses user `root` or source DB `bcihs_library`.
- No configured credential file is shipped. An unconfigured site fails closed.
- Both imported passwords must be rotated before PHP database workflows are available. This PHP gate complements independent server access protection; it does not protect static HTML by itself.
- The package forces simulation provider/mode and live-disabled settings, including against inherited Live environment variables. Existing adapters/events remain in the engine, but activating a provider in this staging bundle would require a separately reviewed change to this deployment safety policy. This is intentional: do not use the ordinary admin ON toggle as live transport authorization.
- Pickup URL comes from private configuration and must be HTTPS, not localhost; a stale inherited LAN URL cannot override it.
- Sessions, rate-limit files, report signatories and default-period policy/audit JSON live outside the public root. The configured fallback remains 14 calendar days until an authorized administrator changes future-checkout policy.
- PhpSpreadsheet dependencies are outside the web root, with the autoloader path adjusted accordingly. Composer's locked versions were retained.
- Ten staff pages have the existing authenticated-only staging indicator, with no public content disclaimer injection or printed indicator.
- Internal helpers and uploaded executable extensions are denied. No development tools/tests are included. The root uses `index.html` and relative URLs; it does not require `/bcihs-library`.

The package's POSIX modes are intentionally restrictive. After upload, apply host-appropriate ownership: public directories/files normally 0755/0644 for static serving; private directory 0700, credential/activation files 0600, private writable directories 0700 under the PHP account. Check the actual hosting user/process model; never use 0777. Do not make private files public to resolve a permission error.

## Step-by-step hPanel preparation and upload (not performed)

1. Select the intended **custom PHP website**, preferably a dedicated staging subdomain. Confirm its document root and hosting plan; avoid overwriting an existing website. Keep public access blocked throughout preparation.
2. In PHP Configuration select the tested PHP 8.3 family and confirm PDO MySQL, mbstring, fileinfo, GD, zip, cURL, XML/DOM/SimpleXML/XMLReader/XMLWriter, iconv and OpenSSL. Hostinger documents PHP selection in its [PHP version guide](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/). Actual plan configuration and MySQL/MariaDB compatibility still need verification.
3. In hPanel MySQL database management, create a **new empty staging database** and its dedicated database user. Store the actual hPanel database name, user, password, host and port privately; no values have been invented. Do not use MAMP root. Grant the import user required schema privileges; the application needs SELECT/INSERT/UPDATE/DELETE at runtime (verify MySQL advisory-lock availability). Do not grant global privileges.
4. Open phpMyAdmin for that exact new empty database, select Import and choose `bcihs-refined.sql`. Compare its SHA-256 with `database-verification.json` before uploading. Do not import into an existing school/development database and do not upload the private development backup. Follow Hostinger's [database import guide](https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/); its empty-target instruction applies only to the new target.
5. Verify twelve tables and the counts above, 24 total/unborrowed copies, accepted student, four full announcements and SMS permission OFF. Do not execute an old seed/reset tool afterward. The package contains no seed tools.
6. Use File Manager/SFTP to upload the **application ZIP to the website's parent directory**, then extract there so `public_html` and `bcihs-private` are siblings. If the account prevents extraction above `public_html`, use SFTP/SSH to place the private directory correctly or stop and request a reviewed path adjustment. Never extract the whole archive under `public_html`. Do not upload SQL, verification reports or credential input files into the public root. Remove the upload ZIP after successful extraction.
7. Preserve the shipped top-level `Require all denied` rule. Verify HTTPS/SSL and protected file rules before opening access. If the hosting engine rejects the rule syntax, keep the site blocked and fix configuration; do not delete deny rules simply to make it load. Actual Hostinger Apache/LiteSpeed rule handling has not been proven by local PHP API tests.
8. Privately copy `bcihs-private/config.example.php` to `bcihs-private/config.php`. Fill only `database.host`, `database.port`, `database.name`, `database.user`, `database.password` using hPanel, and `public_base_url` using the exact HTTPS website root (no `/bcihs-library` unless intentionally installed in that subfolder). Keep all passwords out of chat, shell arguments and Git. Do not copy source DB/SMS/private files.
9. Rotate both imported application passwords using the procedure below while website access remains denied.
10. Configure an independent staging access control such as directory password protection or a reviewed IP allowlist. Confirm it applies to **all** HTML, PHP and assets, not just login pages. Only then replace the initial root `Require all denied` with that verified access-control configuration. Keep HTTPS enforcement, internal/backup denial, no-index rules and upload execution restrictions. `robots.txt`/noindex is not an access control.
11. Inside the access-protected HTTPS site, verify both new logins, the two homepage notices, all four publications, ten searchable books, 14 images, staff indicator, SMS Disabled and normal empty dashboard/report values. Check Secure/HttpOnly/SameSite cookies, session regeneration and expiration on the actual proxy/HTTPS setup. Clear inherited local environment settings and check permissions/session persistence.
12. Perform authorized staging-only transaction QA: request, approval/rejection, reservation pickup, student QR, staff authorization and physical phone verification, checkout/inventory, default/custom dates, return, Time In, uploads, reports/CSV/Excel and A4 printing. Do not fabricate official history or turn SMS ON/live. Back up the hosted clean import before optional QA and review any cleanup separately; no public reset endpoint exists.
13. Take a private hosted database/assets backup and restore-test it into a separate disposable target. Retain checksum/version records. Keep SQL and logs outside the web root, with retention/access controls.
14. Keep independent staging protection in place until librarian acceptance and separately authorized production cutover. No upload/deploy approval was inferred from this preparation request.

## Secure hosted-account rotation

The original local demo passwords remain unchanged. The exported hashes still correspond to those local passwords until rotation in the **new hosted import**. Do not publish/expose login while those familiar credentials are valid.

1. Use a password manager to generate **two different random passwords**, at least 20 characters and at most 72 bytes, with uppercase/lowercase letters, numbers and punctuation. Retain them privately for the designated administrator and borrower. Never put their plaintext in this document, SQL or the ZIP.
2. In a private directory outside `public_html`, create a mode-0600 JSON input file in your editor containing `admin` and `user` keys and those new values. Do not pass values on a command line or in a web form/tool log.
3. After privately configuring the correct hosting database, use SSH/Hostinger Terminal from `bcihs-private`:

   ```sh
   php rotate-credentials.php --rotate ABSOLUTE_PRIVATE_PASSWORD_INPUT --confirm=ACTUAL_HOSTINGER_DATABASE_NAME
   ```

   Substitute the actual target name from hPanel. The CLI validates the confirmation, refuses the original development DB, requires precisely the two expected imported identities, rejects existing/identical/short passwords and reactivation, hashes both with `password_hash(PASSWORD_DEFAULT)` in a database transaction, then writes private `activation.json`. It emits no password or hash.
4. Delete the plaintext input promptly after success, preserve the password-manager entries, and confirm both new passwords work. Old password fingerprints cannot reuse authenticated sessions. Leave `activation.json` private; it records only rotation completion/time.
5. If the command fails or is interrupted, keep server access denied and inspect state privately. Do not manually set activation true to bypass an incomplete rotation. A committed-DB/failed-marker case fails closed and requires reviewed recovery; never reset the original local database.
6. If SSH/Terminal is unavailable on the selected plan, **stop before opening access**. Arrange an approved private CLI/secure database-administrator rotation method that uses password hashing and the same completion checks; do not upload a public password-reset installer.

## Verification and limits

- The SQL import restored all twelve schemas and every finalized row hash exactly into a disposable MySQL 8.0.44 database.
- **92 imported-package checks passed** against another disposable import with a database-scoped temporary user. Covered fail-closed setup, secure rotation, both authenticated accounts, ten-book data, all images, latest two/four notices, SMS OFF/forced simulation under hostile inherited Live flags, staff-versus-borrower QR authorization, single-use checkout, default/custom-day/specific-date deadlines, reservation fulfillment/return, inventory, Time In, validated upload, reports and private Composer Excel charts, outside-root policy persistence and role denial.
- The existing **896 connected isolated workflow regressions** remain recorded from the just-completed content refinement. No business source changed in this packaging task. The targeted imported-package checks cover the packaging-specific PHP/path/configuration changes; the SQL, source staging and development databases were not altered by workflow tests.
- All packaged PHP/helper files passed syntax checks; source/archive image hashes and private file exclusions were checked. Git whitespace checks passed.
- Local API tests simulated HTTPS server metadata for cookie behavior; they do **not** prove real TLS, Hostinger rewrite/deny directives, hosting proxy/cookie behavior, delivery, mobile reachability or native printing. PHP's built-in test server ignores `.htaccess`; the candidate's default deny rule was reviewed structurally, not presented as a real Hostinger HTTP pass.
- Browser access remains disabled in Work settings; responsive/native print and real phone QR tests remain pending. No actual Hostinger testing has taken place.
- School holdings, classifications, circulation metric, signatories, quotas and approved policies still require librarian confirmation before official inventory/production use.
- No live provider calls, SMS credits, deploy, push, merge, commit or main-branch changes occurred.

## Exact source additions for this preparation

- `deployment/hostinger/config.example.php` — blank private hosting configuration template.
- `deployment/hostinger/hosting-config.php` — outside-root path/configuration and rotation gate.
- `deployment/hostinger/db_config.php` — fail-closed hosting DB loader with no MAMP fallback.
- `deployment/hostinger/rotate-credentials.php` — private CLI-only imported-account rotation.
- `scripts/hostinger-export.php` — direct refined-stage export and disposable restore/hash verification.
- `scripts/build-hostinger-package.py` — explicit package selection and deployment-only adaptations.
- `tests/hostinger-package.php` — owned disposable import/application verification.
- `HOSTINGER-PREPARATION.md` — this setup, import, rotation, testing and checkpoint record.

No existing source, private configuration, SQL source database or staging application record was modified for this preparation. Generated configuration/path changes exist only in the separate candidate. If an earlier feature changes later, rebuild/retest a new candidate and checksum; do not assume this snapshot updates automatically.

## Safe checkpoint recommendation

Development is still `5b77d8eae03dfa73c39e9837aee5b22aa08c5a79`; main remains `1465cf93735b3e5bff7c1b80e3eec004e16b249c`. The index is empty. The working tree contains accumulated prior client, attendance, report, due-date, SMS policy, Semaphore, UI and staging-content work; it must not be blanket-staged.

The release's `checkpoint-manifest.json` enumerates **every** modified/untracked file with status and review group. Suggested review/checkpoint sequence:

1. Client functionality/Time In/report corrections and related tests, selectively reviewed against CLIENT-FINAL-FIXES.md. Review the overlapping login/homepage changes rather than blindly staging them.
2. Flexible due-date feature and tests, against BORROWING-DUE-DATES.md, including shared checkout QR/reservation changes.
3. SMS production policy/diagnostic/Semaphore preparation and tests, reviewed together against their documented safety gates. Private config, key files and ledgers never belong in a checkpoint.
4. Approved premium UI/homepage presentation refinements, with hunk-level review where the original photo customizations overlap `index.html`/CSS/login.
5. Isolated staging tooling/artwork/content and its tests/documentation, against DEMO-STAGING.md and STAGING-CONTENT-REFINEMENT.md.
6. This hosting-preparation tooling/templates/tests/documentation as a separate local checkpoint **only after approval**.

Preserve original homepage/login/photo/upload customizations as a separately approved group; do not include the five original upload images or school-photo file accidentally in another checkpoint. Keep Phase 11 on its deferred branch/tag. Check full manifests, whitespace, tests and secret exclusions before any eventual commit. The release ZIP, private SQL/password hashes, backups and verification workspace remain outside Git.

**Verdict:** local upload candidate and database export are verified and ready for a controlled, separately authorized Hostinger setup. Public access is blocked pending actual hPanel details, independent access protection, credential rotation, HTTPS/permissions/rule checks, on-host database/runtime testing, responsive/print/phone acceptance and librarian review.
