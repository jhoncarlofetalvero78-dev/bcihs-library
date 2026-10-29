# Phase 5 — Library attendance and walk-ins

Implemented locally on `development`, based on `1fcca0d` (Phase 4), and approved by the user for the Phase 5 Git checkpoint. The implementation handoff status below is retained as a pre-checkpoint record. No production, main, deployment, push or merge actions.

## Inspection and scope

The existing Attendance page recorded typed name, year level and section, using `attendance-add.php` and `attendance-data.php`. The table already had nullable `user_id` and `student_identifier`, but the form did not link accounts. There was no lookup, CSRF validation, submission replay protection, departure tracking or attendance capacity policy. The initial browser date came from the device. The local attendance table had zero original rows.

There are no existing attendance figures in the Dashboard or Reports scripts. Those modules were not changed, and no new Dashboard attendance metric was introduced. Borrowing, checkout, inventory and reservation business logic is unchanged. QR, SMS and Reports development is excluded.

## Implemented behavior and policy

- Staff search accepted student accounts by name or student ID, then select an account. Results include the account ID fallback and year/section; full selected details remain visible below the select on narrow screens. Same names are not treated as the same identity. Search returns at most 20 results; refine the search for more.
- The server verifies the selected account again and snapshots its name, student ID and complete year/section. Typed identity overrides are ignored. Lookup excludes email, phone, passwords and address.
- Manual walk-ins retain the original required name, year level/visitor type and section fields. Use Visitor and N/A when appropriate. No account is created or inferred from a typed name.
- Confirm Time In records server `NOW()` with the PHP timezone `Asia/Manila` and MySQL connection timezone `+08:00`. Browser/device time does not determine attendance dates or timestamps.
- A registered student with an open tracked visit cannot start another visit, even from a different staff session. After a visit today, another Time In requires explicit confirmation that it is a separate return visit. This allows legitimate repeats with Time In-only history as well as completed tracked visits.
- Manual visitors are not deduplicated by name. Staff must identify their actual visit by name, visit number and time. The form prevents an accidental retry from creating another row, but cannot identify two unregistered people as the same person.
- Departure tracking is **off by default**. Enabling it affects new visits only. Old Time In-only records are preserved and excluded from occupancy, with no invented departure times.
- A staff member must open Confirm Time Out and then choose **Yes, record departure** in the page. Cancel leaves the record unchanged. A second departure returns a conflict and cannot overwrite its original time. Completed records remain visible. Duration is elapsed hours/minutes for tracked visits; open durations are a snapshot when loaded.
- Daily capacity counts **visits**, including repeats and walk-ins. The separate registered-students card counts distinct linked accounts on the selected date. Walk-in visits cannot be presented as unique people.
- Current occupancy counts open tracked visits **across all dates**, including overnight visits. There is no automatic midnight closure. Occupancy is only reliable when staff consistently record departures.
- Both limits default to unlimited (NULL). Occupancy limits require departure tracking. Limits cannot be saved below current counts. Tracking cannot be disabled until open tracked visits have been closed. These settings are independent of reservation pickup quotas.
- Date, status and literal name/ID search filters preserve daily summary totals independently of filtered rows. The Currently inside view explicitly covers all dates. Use Apply filters to refresh counts from other staff sessions; there is no background polling.
- CSV exports the currently displayed filtered records, PHT timestamps, status and duration seconds. It quotes values, escapes quotes and neutralizes spreadsheet formula prefixes. The user-supplied saved CSV was subsequently verified against the filtered test visits.

## Security and concurrency explained

Attendance uses the existing database-verified admin/librarian access and shared book CSRF token. Every write uses POST, strict server validation and PDO prepared statements. Students, teachers, rejected/pending accounts, anonymous requests and stale librarian roles cannot write attendance.

A submission key identifies one form operation; its SHA-256 hash identifies the canonical visitor input. Retrying the same key/input returns the saved visit instead of inserting again. Reusing it with different input is rejected. The browser disables the submit button during saving. Sessions retain the last 20 issued keys for retries; reload when a very old form expires.

Every Time In, Time Out and settings update locks the single attendance policy row inside a transaction. Other staff requests wait for that lock, then check the latest counts before saving. This makes the final capacity place safe even when two different visitors arrive simultaneously. Registered entry also locks the account row so account changes/deletion cannot race the identity check. A unique submission-key index supplies an additional database safeguard.

One narrow Users compatibility change extends its existing transaction-history deletion guard to attendance. Accounts with attendance cannot be deleted through the Users API, preventing a foreign-key SET NULL from breaking historical identity and distinct-student statistics. This guard passed both new and existing regression tests. Permissions and other Users behavior are unchanged.

## Database migration and private backups

Applied only to local MAMP `bcihs_library`, after the CLI refused any non-local host or unexpected database name. The additive migration adds five attendance columns, two indexes and the one-row `attendance_settings` table. No original rows or timestamps were updated. A comparison of every original column in every original table passed before/after migration. Fresh-install definitions were updated separately; the fresh schema was not imported over the existing database.

Private pre-migration backup (outside the repository/web root, mode 0600):

`/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase5-20261027-174958-4b40d7.sql`

Its `.sql.json` companion contains row counts and hashes and also has mode 0600. A further private pre-browser-test backup is:

`/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase5-ui-20261027-175257-37acb5.sql`

Both API and browser test cleanup compared all tables against their original fingerprints and restored policy defaults. Only clearly prefixed test rows were removed. Auto-increment counters may advance during testing; no original row was renumbered or replaced. Temporary fixture credentials/manifests were removed after browser cleanup. No credential or backup is included in the Phase 5 manifest.

Migration command for another local development copy (do not run a schema reimport):

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/migrate-attendance-workflow.php PRIVATE_BACKUP_DIRECTORY /Applications/MAMP/Library/bin/mysql80/bin/mysqldump
```

A second execution here reported already applied and made no changes. MySQL DDL is not transactionally reversible: if a migration fails part-way, keep the backup and inspect the schema before retrying. Do not drop/truncate/reimport to fix it. For a code rollback, retain the added columns/table and all visits. Suspend attendance writes while reviewing a rollback, because older code cannot enforce the new policy. Restoring a full backup could erase newer legitimate work and must be a separately reviewed recovery operation. No rollback was performed.

## Exact Phase 5 file manifest

8 modified files:

| File | Purpose |
| --- | --- |
| `attendance.html` | Lookup, walk-in form, summaries, filters, departure confirmation and settings |
| `css/attendance.css` | Responsive attendance controls using existing maroon panels |
| `js/attendance.js` | Staff UI, validation feedback, PHT display, filters and CSV |
| `backend/attendance-add.php` | Verified student/manual Time In, retry/repeat and capacity controls |
| `backend/attendance-data.php` | Staff history, summaries, policy and session tokens |
| `backend/user-delete.php` | Preserve accounts linked to attendance history |
| `scripts/local-backup.php` | Optional validated backup label, retaining Phase 4 default behavior |
| `sql/schema (2).sql` | Fresh-install attendance fields and default settings |

10 new files:

| File | Purpose |
| --- | --- |
| `includes/attendance.php` | Attendance validation, authorization, time, counts and policy helpers |
| `backend/attendance-students.php` | Accepted-student lookup with limited fields |
| `backend/attendance-time-out.php` | Confirmed departure with immutable completed timestamp |
| `backend/attendance-settings.php` | Validated daily/occupancy policy updates |
| `scripts/migrate-attendance-workflow.php` | Local-only backup and original-value verification |
| `sql/attendance-workflow-migration.sql` | Explicit additive DDL |
| `tests/phase5-attendance.php` | Local API, concurrency and data preservation tests |
| `tests/phase5-attendance.js` | UI logic, escaping, filtering, CSV and duplicate-submit tests |
| `tests/phase5-browser-fixtures.php` | Private disposable local UI fixtures and verified cleanup |
| `PHASE5.md` | Scope, policy, manifest, migration, test evidence and manual guide |

## Automated results

All final runs passed with zero failed assertions/checks:

| Suite | Passed |
| --- | ---: |
| Phase 5 attendance API | 153 |
| Phase 5 attendance JavaScript | 37 |
| Phase 2A Books API | 64 |
| Phase 2A Books JavaScript | 4 reported test groups |
| Phase 2B Borrowing API | 164 |
| Phase 2B Borrowing JavaScript | 50 |
| Phase 4 Reservations API | 170 |
| Phase 4 Reservations JavaScript | 48 |
| PHP syntax | 65 files (private db-local.php excluded) |
| Application JavaScript syntax | 29 files |

The Phase 5 API suite includes real separate-session HTTP requests held concurrently on the same policy row, with lock waits verified through MySQL performance_schema: final daily place, final occupancy place, and same-student duplicate-open races. Other coverage includes replay safety, spoofed identities, same-name accounts, literal wildcard searches, approval/role rechecks, CSRF, malformed JSON, field/date/limit validation, explicit repeat and departure confirmation, overnight occupants, history, durations and protected account deletion.

The JavaScript suite covers the actual UI functions and handlers in a lightweight DOM harness, including differing device timezone, full selected identity, disabled-submit protection, stale response ordering, escaped visitor content, filters, distinct summaries, loading errors and CSV contents/formula protection. It complements browser testing and is not represented as a real browser test.

Reproduce from the project root with the MAMP PHP executable and `node`:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase5-attendance.php
node tests/phase5-attendance.js
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2a-books.php
node tests/phase2a-books.js
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2b-borrowing.php
node tests/phase2b-borrowing.js
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase4-reservations.php
node tests/phase4-reservations.js
```

Run database suites sequentially with local activity stopped. Attendance tests refuse existing visits today or open visits before changing temporary policy. Each suite removes its own fixtures and checks original data. Do not run these fixture suites against production.

## Browser results and limitations

Used disposable prefixed accounts at `127.0.0.1:8888`, separate from the user's localhost sign-in. All fixture tabs were closed; viewport overrides were reset.

Passed UI flows: Attendance navigation and empty default state; search/select student; Time In and summaries; same-day repeat prompt and confirmed repeat; saving daily/occupancy settings; tracked student arrival; duplicate active rejection; manual walk-in blocked by occupancy; Cancel and Confirm departure; completed history; successful manual arrival; inside filter; mobile departure; daily capacity still rejecting after occupants leave; restoring unlimited/untracked defaults; filtered student-ID history retaining full-date totals. Chrome reported no JavaScript errors in the final page session.

Responsive checks used actual CSS viewport widths 1440, 768 and 390 (height 1000). The document had no horizontal overflow beyond the viewport. Wide attendance tables scroll within their container, with an explicit hint and usable action buttons. Both manual and registered layouts were checked; the mobile select has wrapping full identity text below it. Tablet/sidebar behavior and existing mobile navigation were preserved. These are browser viewport checks, not physical-device testing.

Evidence (temporary local fixture data, not current totals) is stored outside Git:

- `/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase5/desktop-registered.jpg`
- `/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase5/tablet-registered.jpg`
- `/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase5/mobile-registered.jpg`
- Manual form evidence: same directory, `desktop.jpg`, `tablet.jpg`, `mobile.jpg`.

The first native browser confirmation stalled the in-app automation. It was replaced with the in-page departure confirmation, which then passed Cancel and Confirm in Chrome, including mobile. The obsolete temporary tab was recovered and closed. No pending dialog remains from that test.

**Saved CSV export verified from the user-supplied file.** The automation did not receive a download-completed event and initially found no file at the expected Downloads filename. The user then supplied `/Users/macbookprom116512/Documents/library batangas/attendance-2026-10-27.csv`, which was inspected read-only. It contains exactly the two filtered registered-student test visits (#11 and #10), with matching student ID, year/section and PHT Time In values (17:53:30 and 17:53:22). Both are correctly Time In only, with blank Time Out and duration. All nine headers, UTF-8 BOM, consistent column counts and unique visit numbers passed validation. No CSV changes were made. This saved file confirms export output; the automation event itself remains unavailable. Content escaping/formula protection passed the separate JavaScript tests.

Verified CSV SHA-256: `cda7ba82bab7b88b94a470fb2dcdffa402f559d80066f22afaf8d10ebd402081`.

## Administrator manual acceptance guide

Use a local test copy with disposable accepted students and no genuine active visits when testing capacity settings.

1. Sign in as admin/librarian and open Attendance. Confirm unlimited policy, no fabricated occupants, and the Philippine date.
2. Search by student name and by student ID. Select the correct identity, read the full details below the select, and Confirm Time In. Check the visit number, timestamp and date summary.
3. Select that student again. Confirm the repeat warning appears and that no new row is saved until the separate-return checkbox is selected and submitted.
4. Choose Manual walk-in; enter a test name, Visitor and N/A. Confirm Time In. Check walk-in visits increase without increasing the unique registered count.
5. Expand Attendance capacity settings. Enable departure tracking with blank limits. Save and record a new student visit. The old records must stay Time In only; only the new tracked visit should count inside.
6. Attempt another arrival for that same student while inside. Confirm rejection. Choose Confirm Time Out, then Cancel and verify no change. Repeat and choose Yes, record departure. Check the completed timestamp and duration.
7. Record a confirmed return visit after departure. Check it is a new row and the earlier completed row remains unchanged.
8. Temporarily set occupancy capacity to the current open count. Attempt a different visitor; confirm rejection. Close a real test departure and retry. Test two staff sessions against the last remaining place if desired; the API suite already verifies this race.
9. Set daily capacity equal to today's visit count. Confirm new arrivals are rejected even after everyone leaves. Reset limits to blank, close all tracked test visits, and disable tracking. Existing history must remain visible.
10. Apply date, status, name and ID filters. Check date summary cards stay unfiltered. Currently inside must include any still-open previous-day test visits. Use Apply filters to refresh another staff member's updates.
11. Export a filtered view, open it and compare row count, identifiers, PHT times and statuses. The supplied saved test export already passed this file-content check. When testing another browser, confirm its own save/download behavior.
12. Check the page at desktop/tablet/mobile widths. Confirm full selected identity, form labels, sidebar collapse, horizontal table scrolling, and departure controls are usable.
13. Confirm a student/teacher cannot access staff attendance endpoints, and an expired session cannot save. Accounts with attendance should be preserved by Users deletion protection.
14. Restore default policy, remove only your own disposable test records using the documented local fixture cleanup workflow, and verify genuine records remain unchanged. Do not delete real attendance to clean up a test.

## Remaining librarian decisions

- Confirm whether to enable departures and how staff will reliably handle missed/overnight departures. No expiry or automatic closure has been invented.
- Confirm actual daily and occupancy quotas, or retain unlimited defaults. The current daily rule counts visits; a unique-person quota would require an agreed policy, especially for unregistered visitors.
- Confirm permitted manual visitor categories and whether their year/section should use Visitor/N/A.
- Confirm the legitimate same-day repeat-visit procedure and who may change capacity settings (currently existing admin/librarian staff permissions).
- Confirm attendance retention/correction policy; there is no edit/delete-history feature in this phase.
- Conduct librarian usability acceptance. Saved CSV output was verified from the user-supplied export; the automated download event was unavailable. Phase 4's previously pending final capacity/cancellation UI and quota/holiday/pickup-expiry decisions remain separate and unchanged.

## Implementation handoff Git status (before the approved checkpoint)

Phase 5 contains 18 files: 8 modified and 10 new, all unstaged. The five unrelated photo files remain byte-for-byte identical to their initial Phase 5 hashes and are excluded from this manifest:

- modified `css/index.css`
- modified `css/login.css`
- modified `index.html`
- modified `login.html`
- untracked `images/school-entrance.png`

Total expected working-tree entries: 23 (12 modified and 11 untracked). Nothing staged. `git diff --check` and `git diff --cached --check` pass. Private `includes/db-local.php` remains ignored/untracked. Backups, fixture manifests, transcripts and screenshots are outside the repository. Branch remains `development`, HEAD remains `1fcca0d`; prior checkpoints `bf3019a`, `679a068`, `98b4fc0`, and `8efdc60` are preserved. No commit was created.
