# Phase 7A — User Management and Announcements

Implemented locally on `development`, starting from `41267ceb6abf149920da454920c35a8272331499` (Phase 6A). Implementation and the final stored-XSS correction are verified. The user has authorized the Phase 7A checkpoint after the final checks below. No schema migration, production access, new dependency, deployment, or Phase 7B work was performed.

## User Management

- Staff authorization is checked against the actual administrator/librarian database record. A stale session role cannot authorize an account action.
- Listing returns relevant account fields, a CSRF token, server date, and a history flag. It never returns password hashes.
- Search supports names, student IDs, employee IDs, and email. Role and approval-status filters work together. User-controlled identifiers and names are escaped before rendering.
- Pending accounts can be approved or rejected. Rejected accounts can be reconsidered for approval, matching the existing controls. Repeating the same action returns a conflict. Approved accounts cannot be rejected using this pending-account review workflow.
- Named, in-page confirmations and disabled in-flight buttons reduce accidental actions. History restrictions have a visible explanation. Approved means permitted to sign in; it does not mean currently online.
- Deletion checks every existing user foreign-key relationship: borrowing requests, reservations, attendance, SMS logs, and announcement authorship. Accounts with any of these references are preserved. Librarians cannot delete their own signed-in account. No deactivation policy or new account status was introduced.
- Registration validates the four existing registration roles: student, teacher, librarian, and visitor. Accounts explicitly start pending. Student and employee identifiers, visitor email, mobile numbers, required fields, lengths, password confirmation, and CSRF are validated on the server.
- Identifiers share one sign-in field, so duplicate detection checks across student ID, employee ID, and email, rather than just one column. Passwords use the existing PHP password hashing API. The existing generated-ID registration UI remains in place.
- Profile updates operate only on the signed-in, approved patron. Student/employee identifiers cannot be changed through profile JSON. Unsupported address editing was removed because the schema has no address field. The UI reports success only after the server confirms the save.
- Administrator profile lookup now reads `admins`, avoiding the previous possibility of editing a patron with the same numeric ID. That profile is read-only; administrator credential editing is outside this module. Staff editing of arbitrary patron details was not part of the existing Users design and was not added.
- Registration chart months use the Philippine server date. Its scale uses distinct whole-number labels even with one registration.

## Announcements

- A separate authenticated management endpoint supplies staff data and form tokens. The public endpoint remains available to guests and students and exposes no author identity or private token.
- Save, edit, and removal require current staff authorization and CSRF validation. Titles, descriptions, categories, IDs, and uploads receive server validation.
- Saving publishes immediately, preserving the existing schema and behavior. There are no draft, hidden, or scheduled states. Removing a notice withdraws it from public pages. The management page explains this before publishing.
- Edits retain the original publication date. Notices are sorted by publication date descending, then ID descending, and dates display in Philippine time. No fabricated “new” date is added on edit.
- A session form key prevents a repeated request from inserting a second notice. Identical retries return the saved ID; changing an already-used form produces a conflict. Both create and edit forms disable repeat submission while saving.
- Existing image support accepts JPG, PNG, or WebP up to 5 MB. MIME inspection and image decoding reject spoofed uploads. Random filenames avoid user-controlled paths. Replacing/removing a photo cleans the application's matching generated upload file after the database write succeeds.
- Search and category filtering, named removal confirmation, readable long text, and honest loading/empty/error messages retain the existing design. Failed reloads clear stale cards and disable new publishing until a successful reload.
- Public announcements and homepage notices use escaped text and Philippine dates. The homepage no longer substitutes fictional notices after a fetch failure. The protected homepage HTML and photo CSS were not edited.

## Issues found and corrected

The original account-review and announcement writes lacked CSRF checks and relied on session roles. Review actions allowed inconsistent repeated transitions. History deletion checks omitted SMS and announcement references. Identifier search/rendering and approval-status filtering were incomplete. Registration validation and shared-identifier duplicate checks needed strengthening.

Profile reads could confuse administrator and patron IDs, and the browser could report a successful local save after a server failure. Announcement forms could be submitted repeatedly, dates depended on the device timezone, and public failures could look like a valid empty result or show fabricated homepage notices.

During actual browser testing, a missing removal-confirmation panel caused announcement initialization to fail. The panel was corrected, and the JavaScript suite now checks every referenced control ID against its HTML. Responsive screenshot review also exposed repeated chart labels for small counts; the scale was corrected and rechecked at all three widths.

## Implementation notes for student developers

CSRF tokens prove that a write came from a form initialized in the current browser session. They supplement permission checks; they do not replace them. Existing `borrowingPost` and database authorization helpers are reused.

Transactions keep related checks and writes together. `SELECT ... FOR UPDATE` locks the account or announcement while its action is being decided, so simultaneous staff clicks cannot both act on an outdated state. Errors roll back the transaction.

Registration uses MySQL `GET_LOCK` with a short timeout to serialize registrations using the same application lock while checking identifiers across three columns. It releases the lock on completion or failure. This is an application connection lock, not a new table or schema change. Other future identifier-changing endpoints must follow the same identifier rules.

Announcement form keys are stored in the session with a hash of the submitted values and the resulting ID. PHP session locking serializes requests from that session. This supports safe retries while keeping the implementation small; the most recent 20 issued forms are retained. An old form may require reloading.

`accountHistorySql` contains fixed, developer-written table names and alias `u`; no user input becomes SQL syntax. All request values use prepared statements. Shared text/phone helpers keep validation consistent without introducing a framework.

## Database safety and backups

No migration was needed. Existing tables and columns were reused. Disposable local fixtures were created only for tests, then removed. All 10 tables matched the earliest Phase 7A row-count/hash baseline after browser cleanup and the final regression run. Existing records, inventory, and capacity/attendance settings were preserved. Test inserts can advance auto-increment counters; original IDs were not changed.

Private SQL backups and companion row fingerprints were created outside both Git and the MAMP web root, with mode `0600`, including:

- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase7a-tests-20261027-211609-843bd2.sql`
- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase7a-ui-20261027-211720-4e3b90.sql`
- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase7a-tests-20261027-212627-03beb3.sql`

The disposable browser credential manifest was removed during verified cleanup. Existing local-host/database guards are reused by CLI tests. No backup, credential, cookie jar, generated upload, or screenshot is part of the Phase 7A source manifest.

## Automated verification

All listed suites passed with zero failures. Counts below are checks/assertions, not separate browser scenarios.

| Suite | API checks | JavaScript checks |
| --- | ---: | ---: |
| Phase 2A Books | 64 | 4 test groups |
| Phase 2B Borrowing | 164 | 50 |
| Phase 4 Reservations | 170 | 48 |
| Phase 5 Attendance | 153 | 37 |
| Phase 6A Reports/Dashboard | 154 | 52 |
| Phase 7A Users/Announcements | 151 | 187 |

The Phase 7A API suite covers authentication/roles, stale permissions, CSRF, unsupported methods, account search data, review conflicts, all five history dependencies, self-deletion, profile ownership and immutable identifiers, student/teacher/librarian/visitor registration, duplicates and invalid input, pending/rejected versus approved login, publication/edit/removal, date preservation, safe retries, invalid and oversized uploads, photo replacement/removal, public visibility, and unchanged SMS logs. Every suite cleans only its own fixtures and verifies original rows afterward.

The Phase 7A JavaScript suite is a **DOM harness**, not a browser test: 90 behavior checks and 97 referenced-control checks. It exercises escaping, search/filter combinations, empty states, named confirmations/cancel, Philippine dates with a Honolulu device timezone, numeric/string IDs, repeat-submit guard, distinct chart labels, and failed-load feedback/stale-data clearing.

All 15 changed/new PHP source files passed MAMP PHP 8.3 syntax checking; all 7 changed/new JavaScript files passed Node syntax checking. Git working-tree and cached whitespace checks passed. The index was empty at verification, before the explicitly authorized checkpoint.

The extended JavaScript security tests use Python 3’s standard-library HTML parser through Node’s `execFileSync`; this is a test-only prerequisite, not an application dependency. The Reservations suite includes per-row assertions on concurrent fixture history; its assertion count can vary by one (the latest run passed 170).

Run the API suites with `/Applications/MAMP/bin/php/php8.3.30/bin/php tests/NAME.php` and JavaScript suites with `node tests/NAME.js`. Use only the configured local MAMP database; these tests create and clean disposable fixtures and take private backups.

## Actual browser evidence

Actual in-app browser testing used disposable local accounts and one clearly prefixed temporary notice. This is separate from the DOM harness.

- Approved a pending student, checked the named confirmation/cancel flow, and confirmed the pending filter became empty.
- Rejected a pending teacher, filtered rejected accounts, and checked its details and permitted reconsideration control.
- Verified visible history restrictions on an existing account without changing that account.
- Created and published a notice, edited it, filtered it by search/category, cancelled removal, then removed it and confirmed it disappeared from the public announcement page.
- Rendered a long multiline notice including literal HTML-like text and accented characters safely in staff and public views.
- Checked Users, staff Announcements, and public Announcements at actual CSS widths **1440, 768, and 390 pixels**. The document width matched the viewport at all three. The Users table scrolls within its container on small screens; page controls and announcement text remain usable. The announcement editor also fit at 390px.
- Rechecked the final registration chart at all three widths after correcting its labels. Restored the viewport and closed agent-created fixture tabs afterward; the user's original tab was retained.

Screenshots are outside the repository:

`/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase7a/`

Evidence files: `users-1440.jpg`, `users-768.jpg`, `users-390.jpg`, `staff-announcements-1440.jpg`, `staff-announcements-768.jpg`, `staff-announcements-390.jpg`, `public-announcements-1440.jpg`, `public-announcements-768.jpg`, `public-announcements-390.jpg`, and `editor-mobile.jpg`. The `users-final-1440.jpg`, `users-final-768.jpg`, and `users-final-390.jpg` captures show the final chart after fixture cleanup. Earlier fixture screenshots intentionally depict temporary test records that no longer exist.

Browser scope: registration submissions, approved/unapproved sign-in permutations, profile writes, upload rejection, and HTTP failure injection were checked by API/DOM tests rather than repeated as complete browser flows. The public/student announcement surface was rendered in the browser with the existing administrator session; a separate signed-in student browser session was not used. No claim of browser network-failure simulation or real SMS delivery is made.

## Final stored-XSS correction and verification

The final pre-commit review found a stored-XSS path in image `alt` attributes on staff Announcements, public Announcements, and homepage notices. Assigning text to a temporary element and reading `innerHTML` escapes markup delimiters but leaves quotation marks unchanged. Interpolating that result into a quoted attribute allowed a title to close the attribute and introduce an event handler. The old fake DOM incorrectly encoded quotes, concealing this difference from a real browser.

The existing `escapeHtml` helpers in `js/manage-announcements.js`, `js/announcements.js`, and `js/index.js` now explicitly encode both double and single quotation marks after the browser serializes text. This preserves literal titles, ampersands, angle brackets, Unicode, and entity-like text in both text and quoted attribute contexts. It also protects the closely related homepage book-cover attributes that use the same helper. Generated upload URLs, image handling, saving, editing, publishing, and filtering are unchanged.

The DOM harness now leaves quotes untouched, matching browser text-node serialization. New tests render five ordinary and crafted title cases through all three actual renderer functions and parse their generated markup with Python’s HTML parser. They assert exact decoded titles and image paths, literal body text, no injected elements, and no injected event-handler attributes. Existing staff action buttons retain their intended handlers. A separate test protects the shared book-cover path. A negative-control test removes the production escaping fix **in memory only** and confirms the corrected harness detects the original injected image handler. No vulnerable source is written back to disk.

All final suites passed: 151 Phase 7A API checks, 187 Phase 7A JavaScript checks, and the prior regression suites in the table above. No unresolved test failure remains.

Actual browser verification loaded the repository’s real HTML/CSS/JavaScript for all three surfaces through a temporary localhost-only static server with synthetic JSON responses. The server did not connect to the database and refused writes and private/PHP file serving. The quoted/Unicode test title remained exact in headings and image `alt` values; each image loaded and contained only its intended `class`, `src`, and `alt` attributes. No injected elements appeared and the harmless execution marker remained unset. Staff edit fields retained the exact title, and staff filtering still worked. This isolated rendering check supplements the real-MAMP API and earlier browser workflow tests; it is not claimed as a second database-backed publishing workflow. The temporary server and browser tab were closed afterward.

Additional screenshots, outside Git in the same evidence directory: `xss-fixed-staff.jpg`, `xss-fixed-public.jpg`, and `xss-fixed-homepage.jpg`. They show browser-only synthetic content, not persistent records. A final read-only comparison confirmed all 10 tables, including SMS logs and policy settings, still match the original Phase 7A fingerprints. No real SMS was sent.

## Manual acceptance guide

Use disposable local records. Do not reject/delete legitimate school accounts or remove real notices.

1. Open registration in a signed-out session. Choose Student, supply valid information and matching passwords, and submit once. Repeat with Teacher using its own staff ID and department. Both should enter pending status.
2. Try missing fields, malformed mobile numbers, mismatched passwords, and a duplicate identifier. Confirm helpful errors and no duplicate accounts. A fresh page initializes the CSRF token automatically.
3. Try to sign in before approval; access must remain restricted. Sign in as an administrator/librarian in another session and open Users.
4. Search by name and student/teacher ID. Combine role and Pending filters. Open details and verify information. Cancel approval once, then confirm it. The account should move to Approved and sign-in should work.
5. Reject another pending disposable account. Confirm it remains restricted and appears under Rejected. Reconsider approval if desired. Approved accounts must not expose an enabled Reject action.
6. Sign in as the approved patron. Edit supported profile details and reload to verify persistence. Identifiers stay unchanged. An administrator profile should be read-only. If saving fails, the UI must not claim success.
7. Review an existing account with borrowing/reservation/attendance history without changing it. Its deletion control must explain the restriction. API tests additionally cover SMS and announcement history. Delete only a confirmed disposable account with no linked records; cancel first to check confirmation behavior.
8. Open staff Announcements. Add a clear temporary notice with a category and long multiline body. Saving publishes immediately. Check the public Announcements page and homepage; verify content, ordering, and Philippine date.
9. Edit the notice and verify its original publication date is retained. Try a valid image, replace it, and verify the new image. Try an invalid image or a file over 5 MB; expect rejection. Avoid double-clicks; API tests separately verify retry behavior.
10. Search/filter the notice, cancel removal once, then remove only the disposable notice. Reload both public surfaces and confirm it is gone. An empty list should show an honest empty state.
11. Repeat Users, announcement listing, and editor checks at 1440px, 768px, and 390px. Scroll the Users table horizontally for off-screen columns. Check sidebar navigation, buttons, long text, and modal controls. Restore the browser viewport afterward.
12. With browser network tools available, temporarily block the list request and reload. Expect a retry/error message and no stale record actions. Restore the network and reload. Do not simulate this by changing database configuration.

## SMS boundary and remaining decisions

Registration, approval/rejection, and announcement actions had no existing SMS side effects; this remains unchanged. No real SMS was sent, no Semaphore credential was read or altered, and test SMS logs remained unchanged.

For Phase 7B review only: the existing `send_sms` helper labels an HTTP 2xx request as `sent`. Provider acceptance is not proof of handset delivery. Delivery-status reconciliation, response validation, retry/idempotency policy, consent, and client-approved notification events need separate review. No automatic announcement SMS was added.

Librarian/client decisions remain: who may register as a librarian and how staff verify that identity, identifier issuance rules, any future suspension/deactivation policy for approved accounts, account/notice retention periods, and whether a future recoverable withdrawal/archive workflow is needed. Existing immediate publication/removal and approval rules are preserved. Earlier attendance, quota, holiday, pickup-expiry, and retention decisions remain in their prior phase documents; this phase does not resolve or implement them.

## Exact Phase 7A source manifest

23 modified files:

```text
announcements.html
backend/all-users-data.php
backend/announcement-delete.php
backend/announcement-save.php
backend/announcements-data.php
backend/profile-data.php
backend/profile-update.php
backend/register-process.php
backend/user-approval-action.php
backend/user-delete.php
css/announcements.css
css/manage-announcements.css
css/manage-users.css
js/announcements.js
js/index.js
js/manage-announcements.js
js/manage-users.js
js/profile.js
js/select-role.js
manage-announcements.html
manage-users.html
profile.html
select-role.html
```

8 new files:

```text
PHASE7A.md
backend/announcement-management-data.php
backend/registration-token.php
includes/account-management.php
includes/announcements.php
tests/phase7a-browser-fixtures.php
tests/phase7a-users-announcements.js
tests/phase7a-users-announcements.php
```

## Git safety snapshot before the authorized checkpoint

Branch remains `development`; HEAD remains `41267ceb6abf149920da454920c35a8272331499`. All prior phase commits remain intact. No files had been staged or committed at this verification snapshot. The checkpoint is authorized only for the exact manifest above. No push, merge, deployment, or `main` change is authorized.

Before staging, the 31 Phase 7A files above were uncommitted alongside the five pre-existing unrelated photo changes. SHA-256 comparisons against the start-of-phase baseline verified all five are byte-for-byte unchanged by Phase 7A:

```text
css/index.css                  modified, preserved
css/login.css                  modified, preserved
index.html                     modified, preserved
login.html                     modified, preserved
images/school-entrance.png     untracked, preserved
```

Local database and Semaphore private configuration remain ignored and untracked. Backups, generated screenshots, exports, and temporary test files are excluded from this manifest. After a successful checkpoint, stop for the next user instruction; no subsequent phase is authorized.
