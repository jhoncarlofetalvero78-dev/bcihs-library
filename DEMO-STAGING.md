# Restricted demo and pre-hosting preparation — 29 October 2026

Content was subsequently refined in place. See **[STAGING-CONTENT-REFINEMENT.md](STAGING-CONTENT-REFINEMENT.md)** for the current edition metadata, polished announcements/artwork, neutral borrower display name and staff-only staging indicator. The initial preparation details below are retained as an audit record; the content manifest, clean-seed snapshot and private demo SQL export now contain the refined content. The database name, accounts/login identifiers, counts, safeguards and original-development preservation remain unchanged.

The original `bcihs_library` database is preserved. This is a separate, local-only demonstration environment, not an online deployment. The owner clarified that **two latest announcements** must remain on the homepage; that instruction supersedes the pasted brief's one-announcement wording.

## Environment and evidence

- Demo database: `bcihs_demo_20261028_236ee935` (the identifier was created before the CLI helper was explicitly set to Manila time).
- Private workspace: `/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01`.
- Demo application copy: the workspace's `app/` directory, outside MAMP's public root.
- Loopback-only preview: `http://127.0.0.1:8899/index.html`. Staff login: `/admin-login.html`; borrower login: `/login.html`.
- Private original database backup: workspace `development-backup.sql`. Restored into a newly owned verification database, compared row-for-row using sorted row hashes, and then only that verification database was dropped.
- Private backup metadata: workspace `backup-manifest.json`. Subsequent helper runs name new metadata `development-backup-manifest.json`.
- Private original images/uploads backup: `/Users/macbookprom116512/.codex/bcihs-demo/assets-backup-20261029-01/development-assets.zip`, with a checksum manifest. All 14 original files were read back from the ZIP and compared with source bytes.
- Private clean demo export: workspace `demo-backup.sql` and `demo-backup-manifest.json`. This contains fictional accounts' password hashes, never plaintext passwords. Do not use the development backup for staging.
- Private snapshots: `source-before.json`, `demo-empty.json`, `demo-seeded.json`; these contain table counts and row hashes, not public test reports.
- Directories are owner-only; backup, configuration, seed input and evidence files are private. Nothing in this workspace belongs in Git or `public_html`.

## Exactly two login accounts

| Account | Actual schema and identifier | Initial state |
| --- | --- | --- |
| Administrator | `admins`, username `admin`, ID 1; administrator authority is represented by this table | Active administrator |
| Borrower | `users`, School ID `user`, ID 1; full name `Demo Student`, student role | Accepted/active |

The real borrower login uses School ID or Email, not a username field. Enter `user` in School ID or Email. Its address is `demo.student@example.invalid`, its phone is NULL, and it has no avatar or recovery code. No real person's identity is used. Passwords supplied by the owner were read from a mode-0600 private JSON input and hashed using `password_hash(PASSWORD_DEFAULT)`; authentication was verified through the existing login endpoints. No plaintext password appears in the seed script, SQL export, documentation, or application logs. The temporary seed-password input was removed after verification.

These intentionally familiar credentials are restricted-local-demo credentials. Before any online release, require unique strong replacements, restrict staging with an independent access control, and separately verify the new login credentials. Do not publish the local credentials or make this copy publicly reachable.

## Books and artwork

All ten requested titles are seeded, with 24 total physical copies, zero borrowed copies and no pickup holds:

| Title | Author | Copies |
| --- | --- | ---: |
| Noli Me Tangere | José Rizal | 3 |
| El Filibusterismo | José Rizal | 3 |
| Florante at Laura | Francisco Balagtas | 2 |
| Ibong Adarna | Traditional / Anonymous | 2 |
| Pride and Prejudice | Jane Austen | 2 |
| Alice's Adventures in Wonderland | Lewis Carroll | 3 |
| A Christmas Carol | Charles Dickens | 2 |
| The Adventures of Sherlock Holmes | Arthur Conan Doyle | 2 |
| Little Women | Louisa May Alcott | 3 |
| The Wonderful Wizard of Oz | L. Frank Baum | 2 |

`demo-assets/manifest.json` lists each category, genre, synopsis, original artwork path and provenance. Call numbers `DEMO-LIT-001`…`010` and catalog accession labels `DEMO-CATALOG-001`…`010` are explicitly fictional. No individual-copy table exists, so no invented physical-copy rows are added. ISBN, edition, exact publisher, publication place/year and DDC are left NULL rather than guessed. The current books schema has no synopsis or shelf-location column: synopses are retained in the manifest, not forced into unrelated columns or added through a migration.

The ten 600×900 PNG covers and four 1280×720 PNG banners are original typographic/geometric artwork, dedicated to CC0-1.0. No third-party commercial covers, external hotlinks or unverified image licenses are involved. Existing school branding remains unchanged. `scripts/build-demo-artwork.py` reproduces them locally with Pillow and local Georgia fonts; the fonts themselves are not distributed. Rebuilding artwork is not required on Hostinger.

All 14 images were decoded, checked and served successfully through the isolated application. The contact sheet was visually inspected as an image artifact, not as a browser screenshot. Actual desktop/tablet/mobile page rendering remains pending.

## Four published announcements

1. Welcome to the BCIHS Library Portal — DEMO — 29 October 2026, 09:00 Manila.
2. How to Borrow Books — DEMO — 28 October 2026, 09:00 Manila.
3. Using Your Pickup QR — DEMO — 27 October 2026, 09:00 Manila.
4. Library Reminders and Book Care — DEMO — 26 October 2026, 09:00 Manila.

Each body begins with a concise preview, followed by complete student-friendly guidance and an explicit demonstration/policy disclaimer. A saved announcement is published in the current schema; there is no separate draft/published column. No school event, hours, quota, signatory or official rule is invented. The homepage takes the first two by publication date/ID; View All returns all four. The compatible `latest=1` API view remains available.

Announcement images now use responsive natural height and `object-fit:contain`, scoped to the homepage and public announcements cards. Desktop height bounds remain 360/420 pixels. Wide 16:9 demo banners fit without cropping or stretching; unusual aspect ratios remain complete even if some surrounding space is unavoidable. Book and other image components are unchanged.

## Exact record counts

Every demo table began empty after schema-only creation. Reset was also tested successfully on this owned demo only.

| Table | Development before | Development after | Demo before seed | Demo final |
| --- | ---: | ---: | ---: | ---: |
| admins | 1 | 1 | 0 | 1 |
| announcements | 2 | 2 | 0 | 4 |
| attendance | 4 | 4 | 0 | 0 |
| attendance_settings | 1 | 1 | 0 | 1 |
| books | 5 | 5 | 0 | 10 |
| borrow_requests | 8 | 8 | 0 | 0 |
| reservation_settings | 1 | 1 | 0 | 1 |
| reservations | 2 | 2 | 0 | 0 |
| sms_logs | 15 | 15 | 0 | 0 |
| sms_notification_audit | 5 | 5 | 0 | 0 |
| sms_notification_settings | 1 | 1 | 0 | 1 |
| users | 8 | 8 | 0 | 1 |

Development preservation compares every row's hash, not merely counts. All source rows matched the preparation baseline. The current source schema has no triggers, routines or events omitted from the schema backup. Source private SMS configuration and consumed claims remained unchanged; original image/upload files remained byte-identical. Student #364, SMS log #265 and other historical evidence are preserved. Phase 11 stays deferred on its existing branch/tag.

Demo supporting settings are deliberately neutral: attendance Time In only, no invented visit/occupancy quota, no invented reservation capacity, SMS permission OFF. The app copy omits source borrowing-policy and signatory files, preserving the application's 14-calendar-day fallback and blank configurable signatories. It does not copy original administrator hashes, people, reset tokens, attendance, transactions, SMS history or live ledgers.

## SMS isolation

The source environment remains UniSMS Live with its existing authorization unchanged. **Use port 8899 for the safe demo, not the active MAMP application on port 8888.**

The demo's `sms_notification_settings` row is OFF, private SMS configuration has `provider=simulation`, `mode=simulate`, `live_enabled=false`, no provider key, no recipient whitelist and no live-test authorization. The loopback launcher also forces these environment settings and disables provider cURL execution in the PHP server. An administrator turning the demo permission ON could only simulate; it cannot enable paid transport. No actual SMS/provider request or credit consumption occurred. Database-writing acceptance cases run against a third, disposable schema with mock transports, never the persistent clean demo or active database.

## Verification results

- 93 read-only demo content/HTTP checks passed, including both real login flows, permissions, catalog data, all covers/banners, two newest notices, complete announcement collection, honest empty circulation, Excel chart output, fourteen-day default, Time In only, effective Disabled SMS and denied internal files.
- 896 connected isolated workflow checks passed: book management/upload safety, login/registration, all supported borrowing/reservation flows, approval/rejection, request QR, staff verification, duplicate checkout/inventory protection, default/custom three-day/specific-date due dates, Manila display, reports/exports, attendance and all seven mock SMS paths. Only an owned temporary QA database/application was written; it was removed afterward.
- 507 numbered JavaScript checks passed across borrowing, QR, reservations, attendance, reporting, announcements, due dates and SMS settings, plus the Manage Books search/filter/export/QR-removal checks.
- 35 isolated due-date PHP checks passed.
- SMS PHP checks: 94 shared mock checks, 105 additional UniSMS checks, 120 persistent one-attempt guard checks, 16 Semaphore preparation checks. Provider adapter suites use disabled cURL functions; no live requests.
- Expected negative reset checks passed: wrong source-database confirmation refused; reset during preview refused. The exact owned-demo reset succeeded, returning to the 19-row clean seed.
- PHP syntax, PNG decoding/hash checks and Git whitespace checks passed.
- Browser automation is disabled in Work settings. No browser responsive or native print-preview pass is claimed. Manual desktop/tablet/mobile image layout, book-cover cropping preferences, text wrapping, native print pagination and actual phone scanning remain pending.

## Repeatable local creation, verification and reset

Use `/Applications/MAMP/bin/php/php8.3.30/bin/php` in the repository. All commands below operate only on the private local demo workspace; none changes the source connection file.

```sh
php scripts/demo-environment.php verify --workspace=/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01
php scripts/demo-environment.php serve --workspace=/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01 --port=8899
```

For a fresh copy, create an owner-only parent folder outside `htdocs` and a mode-0600 JSON password file in your editor. It must contain `admin` and `user` keys with local passwords of 12–72 bytes. Never use shell arguments/history to pass password values. Then use `create --workspace=NEW_ABSOLUTE_PRIVATE_FOLDER --password-file=PRIVATE_FILE`; the target folder must not exist. The script makes/verifies a fresh development SQL backup, clones only the schema, creates its random owned database, hashes credentials, seeds fictional content and creates the isolated app/configuration. It cannot target `bcihs_library` for seeding/reset. The new workspace marker records the exact generated name. Remove the private plaintext input after login verification.

For reset, first stop the demo server (Ctrl+C when launched in your own terminal, or ask Codex to stop its owned preview). Never remove a live ownership/running marker blindly. Create a new private password input, then:

```sh
php scripts/demo-environment.php reset --workspace=/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01 --confirm=bcihs_demo_20261028_236ee935 --password-file=ABSOLUTE_PRIVATE_INPUT
php scripts/demo-environment.php export --workspace=/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01
```

Reset deliberately clears **only the owned demo database** and recreates the clean seed. It requires the random-name pattern, private ownership marker, matching private app database configuration, local source connection, exact database confirmation and an exclusive preview/reset lock. It clears only demo sessions and demo policy/signatory settings; original claims/private files are never touched. Export refuses a demo that differs from the saved clean seed. A process crash may leave a conservative running marker: verify the owned worker has stopped before removing that marker. This is a local single-server helper, not a public deployment/reset endpoint.

An optional future demonstration may create a borrow request with the demo borrower, approve it as the demo administrator, inspect its pickup QR, confirm handover/due date, and return the sample book. Reservations require exhausted requestable stock. Those are explicitly optional actions; the prepared database has no invented history. Reset afterward only when that demo history is no longer wanted. Phone scanning requires an approved reachable LAN URL or the eventual staging HTTPS URL; a phone cannot reach the Mac's loopback address.

## Hostinger readiness and blockers

This is a custom PHP/MySQL application, not a Hostinger Website Builder project. Local runtime: PHP 8.3.30, MySQL 8.0.44; all required local PDO MySQL, mbstring, fileinfo, zip, GD, cURL, XML/DOM/SimpleXML/XMLReader/XMLWriter, iconv and OpenSSL extensions are available. PHP 8.3 is the tested baseline; verify the actual hosting plan's runtime/extensions before importing. Hostinger documents per-site PHP selection and extension configuration in [PHP version](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/) and [extensions/options](https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options-in-hostinger/).

Before any public exposure:

1. Create a separate staging site/database and least-privilege database user. Do not use local root credentials or import the private development backup. Import only the clean demo SQL into a newly empty staging database. Confirm utf8mb4, foreign keys, enums, timestamps and all twelve tables on the actual MySQL/MariaDB version. Hostinger's [phpMyAdmin import guide](https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/) describes import into the selected empty target; never apply its empty-target instruction to development.
2. Replace both familiar local passwords securely and put staging behind independent authentication/IP restrictions. Obtain the staging domain/subdomain and HTTPS before public login or phone QR testing. No target hosting account/domain was provided or modified.
3. Upload only approved application files, original demo assets and the locked Composer dependencies (`composer.lock`, PhpSpreadsheet 5), not `.git`, scripts/tests/tools, original uploads, private workspaces, backups, ledgers or working-tree reports. The private local preview copy is not a ready-to-upload archive because its DB configuration contains local connection credentials.
4. Configure new staging DB credentials privately, preferably outside `public_html`; existing ignored include files can load an outside-web-root configuration array through a small deployment-specific wrapper. The current app directly looks for `includes/db-local.php`, `sms-local.php`, `pickup-local.php` and policy JSON, so moving everything outside the web root is a deployment adjustment, not a feature already verified here. Keep wrappers and writable policy files denied by server rules. Never upload the source UniSMS secret, whitelist, consumed-claim directory or Semaphore key.
5. Keep staging SMS permission OFF and a credential-free simulation/live-disabled private config. Do not copy live source environment variables. Check the safe status view before enabling any workflows. Semaphore remains pending Sender Name approval and is not activated by this work.
6. Set `BCIHS_PUBLIC_BASE_URL` to the exact staging HTTPS application base URL, including any subfolder. Do not commit a LAN IP or localhost. The only public JavaScript localhost reference is an intentional QR warning; DB fallback/example hostnames are local configuration examples. SVG namespace URLs are not network endpoints. Verify staff-only QR login, redirects and checkout using a real cellphone on staging.
7. Preserve/verify Apache/LiteSpeed deny rules: `includes/`, Composer vendor paths, scripts/tests/sql, dotfiles, backups and directory listing; uploads must deny executable extensions. The PHP built-in preview has its own router because it ignores `.htaccess`. Hostinger's actual rule behavior has not been tested. Verify these denials over HTTPS before releasing access.
8. Use least required writable permissions for uploads, private policy/report settings and session/rate-limit storage; never chmod 777. Check storage persistence/locking on the hosting filesystem. The one-server file locks and MySQL advisory-lock assumptions need reassessment if hosting ever becomes multi-server.
9. Enforce HTTPS; verify Secure, HttpOnly, SameSite cookies and session regeneration behind the real proxy. The existing code determines Secure from `HTTPS`; proxy behavior must be checked. Use private session paths, production `display_errors=Off`, safe server logs, tested backup/restore and private scheduled backups. Do not expose phpinfo or diagnostic dumps.
10. Verify Composer installation/locked dependencies, upload size limits, UTF-8 image paths, empty CSV/Excel exports and native A4 print layout on actual hosting. No native print verification was possible here. Original monthly statistical reporting is circulation-based, not an inventory report, and still needs librarian metric/school-year/signatory confirmation.

Public release remains blocked by password replacement/access restriction, actual Hostinger provisioning/configuration, HTTPS/rule/permission verification, database import compatibility, responsive/native print acceptance and real phone QR acceptance on the final reachable URL. No deployment, public exposure, push, merge or commit was performed.

## Working tree and checkpoint recommendation

Existing uncommitted client fixes, attendance changes, report work, due-date implementation, live-policy/Semaphore preparation, private diagnostics and original homepage/login/photo/upload customizations remain present. These should not be blanket-staged. `login.html`, homepage HTML/CSS and shared files contain overlapping earlier customizations: review exact hunks and obtain approval for each group. Phase 11's deferred branch/tag must remain separate.

This task's focused source additions are `scripts/demo-environment.php`, `scripts/demo-router.php`, `scripts/build-demo-artwork.py`, `tests/demo-content.php`, `demo-assets/manifest.json`, ten `demo-assets/books/*.png`, four `demo-assets/announcements/*.png`, and this document. Four existing display files were adjusted: `index.html`, `announcements.html`, `css/index.css`, `css/announcements.css` (announcement full-image display and cache versions only). The previous two-announcement implementation remains intact. No business logic or schema was changed for this task.

Recommendation: first review/checkpoint the earlier approved functional groups selectively; then separately approve the demo tooling/artwork/documentation and the four display hunks. Exclude every private workspace and original customization that has not been approved. A private exact working-tree manifest is retained beside the backup; nothing has been staged or committed.
