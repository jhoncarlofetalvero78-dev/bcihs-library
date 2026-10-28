# Phase 8 — Finalization and verification report

Completed 28 October 2026 on `development`, starting from `d62abcd8181a4aab49231bff71fafdbcbcc89838`. This report describes the reviewed Phase 8 checkpoint. The authorized final acceptance review reconciled all 124 files. Only Phase 8 HTML hunks are included for the homepage/login; the pre-existing photo cache versions, CSS and uploaded assets remain uncommitted. No push, merge, deployment or change to `main` is authorized.

## A. Executive summary

Implemented the confirmed audit fixes, hardened authentication and recovery, clarified borrower identity, standardized shared UI behavior, replaced native browser confirmations, corrected sidebar scrolling, and verified real workflows in a disposable local database. The existing PHP/PDO/vanilla JavaScript architecture, maroon branding, inventory transaction engine, QR implementation and SMS providers were retained.

The system is ready for **internal testing and a guided capstone demonstration of the verified workflows**. It is suitable to begin client acceptance review, but final acceptance is pending school policy, official report templates, manual print review, and deployment checks. It is **not certified for production deployment** or exhaustively tested across every browser/button/volume scenario.

All 10 active database tables matched their pre-work row-count and row-hash snapshots. Student #364, SMS log #265, their relationships, private configuration, both verified backups and the consumed SMS claim remain intact. No real SMS/provider request or additional credit was consumed. The remaining balance is the last user-confirmed eight credits; it was not queried again.

## B. Before and after

| Area | Before | Final behavior |
|---|---|---|
| Catalog/Profile/dashboard rendering | Text escaping was reused in quoted attributes; quotes could create attributes | Both quote types are encoded. Parser-based malicious-title and Unicode regressions cover catalog, profile, dashboard and announcement escape functions. Existing announcement fix retained. |
| Login | Username-only label, arbitrary first account match and administrator precedence on collisions | School ID or Username label; existing school IDs, employee IDs, registered email and administrator username accepted. Ambiguous matches fail generically. New registrations/visitor email updates check the administrator namespace too. |
| Registration | School IDs randomly generated; four legacy pages had no CSRF token path | Enter an actual school-issued ID; accounts remain pending. Legacy URLs redirect to the existing secure, token-initialized role form. No existing ID was changed. |
| Password recovery | Phone formats differed between request and reset; legacy schema/field routes broken | Same canonical phone normalization in both steps, one matching account, same-browser challenge, bounded attempts, hashed code in server session and opaque challenge ID in the existing short DB column. Obsolete route no longer exposes OTPs in a URL. |
| Profile | School-account email looked editable but backend discarded it | School-ID roles no longer offer that edit. Visitor email remains editable with uniqueness protection. Save/cancel guard prevents in-flight duplicate or accidental close. |
| Activity display | NULL Last Login shown as Never | Shows Not recorded. No historical timestamps were fabricated or database login writes introduced. |
| Reports | Inventory/overdue snapshots failed on irrelevant reversed custom dates | Snapshot requests ignore irrelevant date fields; date-based reports still reject invalid ranges. Official template remains explicitly pending. |
| Roles | Staff tables described all borrowers as students; attendance lookup only students; accepted visitor could request loans | Approved teachers reuse the same loan engine without staff rights. Student/teacher attendance uses linked accounts and distinct labels. Visitors have catalog viewing and authorized walk-in attendance, not self-service borrowing. |
| Confirmations | Native browser dialogs plus inline consequential panels | Shared centered application confirmation with literal text, cancel-first focus, Escape support, duplicate-dialog suppression, and destructive styles. Existing account/announcement/Time Out/QR confirmations now use centered native HTML dialog elements with existing handlers preserved. |
| Staff navigation | Body and sticky sidebar could scroll together; mobile navigation initially expanded | Desktop main content scrolls independently; sidebar stays at viewport top/bottom. Its navigation can scroll separately. Mobile menu starts collapsed and remains available without an overlay trap. |
| Tables/actions | Uneven styles and sometimes hidden overflow/actions | Shared semantic styles, progress feedback, keyboard-focusable scroll regions, overflow cues only when needed, no automatic conversion of all tables to cards. Fake coming-soon buttons are explicitly unavailable. |
| Narrow account pages | Three pages overflowed at 320px | School branding wraps; account forms fit 320px without changing images or logos. |

## C. Functional verification by module

| Module | Status | Evidence and limits |
|---|---|---|
| Authentication, registration, approval | FIXED AND VERIFIED | Disposable student/teacher/librarian/visitor/admin identities, actual login/registration/approval endpoints, namespace collision rejection and credential-bound session rejection. Current real database used only for reads. |
| Password recovery | FIXED AND VERIFIED | Actual isolated request/reset endpoints with normalized formats, invalid code, successful reset, replay rejection and redacted logs. Only the disposable server copy used a deterministic code for this test; production source still uses random_int. Administrator recovery remains a trusted-custodian policy item. |
| Books/inventory | FIXED AND VERIFIED | Add/edit/delete endpoints, duplicate ISBN, invalid DDC, nonstaff denial, executable-content rejection, real PNG multipart upload with safe random non-executable path, history deletion protection. UI add and cancel-delete tested. |
| Borrowing/returns | FIXED AND VERIFIED | Pending → held pickup → checkout → return; due date only after handover; duplicate actions; teacher loans; visitor denial. Independent staff sessions made parallel approval/checkout attempts against one copy: one succeeded, one was rejected and stock remained correct. Not a sustained load/stress test. |
| Request-specific QR | FIXED AND VERIFIED | Token generation/ownership/state, staff verification, existing checkout engine and replay rejection; browser request → approval → Show pickup QR → staff sign-in return → centered Confirm Checkout → used QR → return. No new physical-phone test; historical user confirmation remains recorded. Catalog QR controls remain removed. |
| Reservations | FIXED AND VERIFIED / NEEDS CLIENT CONFIRMATION | Real isolated waitlist, invalid past time, schedule edit, unavailable stock, ready hold, checkout/return/cancel and duplicates. Browser calendar, preferred time, waitlist feedback and cancellation exercised. Existing weekday/hour/slot/capacity rules unchanged; school holidays/expiry/FIFO policy not invented. |
| Attendance/walk-ins | FIXED AND VERIFIED / NEEDS CLIENT CONFIRMATION | Teacher account lookup by employee ID, identity snapshots, idempotent entry, manual walk-in, tracked Time Out and repeat rejection. Department snapshot bounded to existing 50-character field; original department retained. Limits and Time Out policy remain client decisions. |
| Announcements/dashboard | FIXED AND VERIFIED | Publication/removal endpoints and public visibility; escaping including dashboard image attributes; current statistics read without record changes. No draft/hide engine invented. |
| Reports/export/print | PARTIALLY VERIFIED | All eight sources and snapshot/date validation exercised with isolated records. Existing JS export/formula protections passed. A 48-row report loaded for a fresh print attempt, but the native Chrome print surface was inaccessible/timed out. No new readable-column/page-count/blank-page pass is claimed. |
| SMS | PASS — scoped | Shared events/provider mocks and persistent guard regressions passed. Active simulation/live-disabled config unchanged; no provider transport called. Historical handset acceptance and eight-credit balance preserved, not repeated. |
| UI/navigation/accessibility | FIXED AND VERIFIED — scoped | 55 isolated staff width renders, 40 guest width renders, 20 student catalog/Profile-tab renders, then 55 final MAMP read-only staff width renders. Three 320px auth overflows fixed and rechecked. Representative screenshots, keyboard/modal cancellation and actual workflow controls inspected. Not every combination of button/role/error/browser was manually exercised. |
| Deployment | PARTIALLY VERIFIED / NEEDS CLIENT CONFIRMATION | PHP 8.3 syntax, MAMP runtime security storage, actual web cookie flags and Apache deny rules checked. HTTPS production configuration, Windows service ACLs, target hosting and official Semaphore setup remain unverified. |

Existing older PHP suites that write fixtures were not run against `bcihs_library`. The historical Reservations refusal remains a legitimate-future-reservations safety guard; the historical Attendance mismatch remains a test-isolation assumption, not a confirmed application defect. New Phase 8 integration coverage ran separately and does not falsely relabel those older runs as passed.

## D. UI/UX implementation

- `css/library-ui.css` consolidates maroon, surface, border, text, success, informational, warning and danger tokens, button focus/disabled styles, dialogs, compact status badges and account-page styling. Existing page layouts and user photos remain the design source.
- Main positive actions use maroon; secondary actions use neutral/outlined treatment; delete/reject/cancel use danger treatment. Returned/completed badges are green; active loans and pickup states are informational; pending/waiting amber; simulation neutral. Text always carries the meaning. SMS acceptance never means handset delivery.
- Shared cards use restrained borders/shadows; typography, table spacing, field heights and action targets build on prior refinements. Long titles wrap or remain in explicitly scrollable tables. Teacher identity is a supporting line rather than another oversized data column.
- Desktop body no longer drives staff page scrolling. Main content, sidebar navigation and accessible logout region have separate bounded areas. Top/middle/bottom tests observed main scroll positions 0, approximately 99 and 292px while sidebar top remained 0, bottom 900, and document scroll remained 0.
- Mobile staff navigation starts collapsed; opened navigation has a bounded internal scroll area. At 320px an opened menu stayed within document width. No permanent blocking drawer overlay was introduced.
- `js/library-ui.js` exposes one Promise-based confirmation helper. It rejects simultaneous duplicate dialogs, inserts messages as textContent, resolves cancellation without a request, restores focus and relies on native HTML dialog focus containment. Existing consequential panels retain their handlers and are opened as dialogs. In-progress confirmations cannot Escape-close when their action controls are disabled.
- Existing edit/detail overlays gain dialog semantics, focus containment/restoration and safe Escape cancellation. Source action guards remain authoritative; security never relies on a disabled button.
- Scrollable table regions gain keyboard access, labels and responsive cues; duplicate existing hints are reused. A skip-to-content link and live status announcements are added. Reduced-motion preference is respected.
- Registration styles use the shared maroon action hierarchy instead of arbitrary role-specific action colors. Three narrow-page overflow defects were fixed after browser reproduction.

## E. Bugs fixed and source responsibility

1. Attribute injection: `js/browse-books.js`, `js/profile.js`, `js/admin-dashboard.js` and related escape helpers now encode both quotes; actual parser tests preserve ordinary text, apostrophes, ampersands, Unicode and attack strings without extra attributes/elements. Existing announcement protection preserved.
2. Recovery mismatch and obsolete routes: `includes/security.php`, `backend/forgot-password-request.php`, `backend/reset-password-process.php`, legacy endpoint/page/scripts and `js/forgot-password.js`. Opaque challenge markers fit existing varchar(10); no schema change. Verification binds session, normalized phone, requested role, account ID, expiry and current database challenge under a row lock. Codes are hashed in the session and redacted from SMS logs.
3. Authentication ambiguity: `backend/login-process.php`, `backend/register-process.php`, `backend/profile-update.php`. Exactly one account identity must match across user and administrator tables. New identifier changes are serialized using the existing registration lock; collisions never silently promote a user.
4. Session/abuse protections: `includes/session.php`, `includes/security.php`, session-starting endpoints, access helpers. HttpOnly, SameSite Lax and strict session mode; Secure under direct HTTPS; session regeneration retained. A bound password fingerprint invalidates initialized sessions after credential changes.
5. Discarded Profile email and duplicate-save behavior: `js/profile.js`; permitted server fields unchanged for school roles, visitor identifier uniqueness strengthened. In-flight cancel/save guarded.
6. Legacy registration/ID invention: role pages/scripts and `select-role.html`/`js/select-role.js`. Redirect to token-initialized form and require actual IDs; preserve pending approval and all current identifiers.
7. Irrelevant snapshot dates: `backend/reports-data.php`; invalid date ranges still rejected where dates apply. Actual isolated endpoint and browser reproduction from the audit covered the defect.
8. Misleading activity: `js/manage-users.js` uses Not recorded. No current database timestamp changed.
9. Visitor/teacher confusion: `includes/borrowing.php`, catalog capability response/UI, attendance lookup/save, circulation metadata/labels, dashboard/reporting wording. No separate teacher loan engine or role promotion introduced.
10. Native dialogs/sidebar/320px overflow: shared CSS/JS plus page wiring and relevant action handlers. Cancellation, handover, menu and width evidence captured with disposable records.

## F. Actual roles and identifiers

- Students sign in with existing school-issued Student IDs; old accounts keep their IDs. New forms never invent an ID.
- Teachers use their existing employee/faculty ID and a staff-approved teacher account; they share patron catalog, request, reservation, checkout, return and history logic. Teacher role grants no staff dashboard/action access. Client must confirm eligibility and lending policy before school use.
- Administrators retain their existing username. Librarians use the existing approved librarian identity/employee ID and staff authorization. Registered email remains a backward-compatible identifier; no fictional patron username column was added.
- Duplicate/colliding identifiers are rejected at login and checked on supported identifier creation/edit paths. Existing conflicting records, if any, are never rewritten to force resolution.
- Visitors can view the catalog and be recorded as authorized manual walk-ins without creating permanent student records. Attendance does not grant borrowing. Visitor accounts cannot self-submit new borrowing/reservation requests; linked historical records remain untouched.
- Existing staff-only manual checkout is retained with a clear borrower label; it can create an unlinked staff-verified borrowing record. Whether the school requires a permanently linked borrower account for every manual checkout remains a client decision. No visitor entitlement was invented.
- Administrator recovery is not silently added to a phone-only endpoint. The recovery page explains the authorized custodian route; librarian recovery uses the actual librarian role.

## G. Final automated test results

| Suite | Passed |
|---|---:|
| Phase 7B shared/Semaphore PHP mocks | 83 |
| UniSMS PHP mocks | 105 |
| Persistent one-SMS guard/fault tests | 117 |
| New database-free security tests | 28 |
| New isolated actual HTTP/database workflow suite | 133 |
| Borrowing JS | 52 |
| QR JS | 50 |
| Reservations JS | 49 |
| Attendance JS | 37 |
| Reports JS | 52 |
| Users/announcements JS | 189 |
| SMS Logs JS | 43 |
| New UI/escaping/dialog JS | 160 |
| Books JS | Four passing test groups |

Totals: **333 database-free PHP checks + 133 isolated integration checks; 632 named JavaScript checks + four Books groups**. PHP syntax: **99 files**; JavaScript syntax: **43 files**. Git whitespace checks passed. Final safe suites passed; no unresolved assertion failure remains.

During development, the first runs exposed missing harness state for asynchronous confirmations, strict test payload requirements, an accurate teacher snapshot edge case, and a wording assertion. These were corrected and rerun. Incorrectly invoked SMS mock suites refused before execution until their required disabled-cURL command was used; those refusals were not counted as passes. No live API was used.

Reproduce database-free verification from the repository:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php -d disable_functions=curl_init,curl_exec,curl_multi_init tests/phase7b-unisms.php
/Applications/MAMP/bin/php/php8.3.30/bin/php -d disable_functions=curl_init,curl_exec,curl_multi_init tests/phase7b-live-guard.php
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase8-security.php
node tests/phase8-ui.js
```

Each existing `tests/*.js` suite was also run. Do not execute legacy database-writing PHP suites on the active database.

The new isolated runner is explicitly opt-in:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/phase8-isolated-qa.php --run
```

It requires local MAMP credentials with CREATE DATABASE permission, creates a uniquely named `bcihs_phase8_test_<random>` database without IF NOT EXISTS, copies schema only, verifies an empty user table, and starts a separate loopback server at port 8898 with matching database identity. Its private document root excludes real local configuration and uploads. SMS is explicitly simulated/live-disabled and server curl_exec is disabled. Only the client test process can contact its fixed loopback URL. Fixture passwords are random and never printed. For deterministic recovery tests, only the disposable endpoint copy uses a fixed code; the application source retains cryptographic randomness. A private session directory isolates credentials. Finally it stops its workers, drops only its owned generated database and removes owned files/budgets. This runner is MAMP/macOS QA tooling; its Unix process cleanup needs a platform-specific equivalent before Windows use.

Additional browser-only synthetic records, including 45 long-report books, were removed with that owned database. Earlier identifiable disposable PHP sessions from initial harness runs were also removed; no legitimate session matched their test-only identity markers. There are zero remaining Phase 8 disposable databases and no running QA server. The in-app viewport was reset. During final cleanup Chrome stopped exposing its obsolete isolated fixture tab to automation, so closure and Chrome viewport reset could not be reconfirmed; it points to the now-stopped loopback QA server, not the active database.

Coverage limits: these are targeted integration/parallel tests, not broad sustained load testing. A new physical camera/phone test, live Semaphore delivery, every obscure UI error/role combination, Windows runtime and native print pagination remain unverified. Historical SMS/phone acceptance is not relabeled as a new result.

## H. Database and asset integrity

Before write-based verification, a fresh private mysqldump backup and SHA-256 were verified outside the webroot. All test writes then occurred in the validated disposable database. The active database was used for SELECT/metadata/read-only report access; one deliberately invalid synthetic MAMP login exercised filesystem permissions and performed account lookup only.

Final per-table hashes/counts matched for admins, users, books, borrow_requests, reservations, reservation_settings, attendance, attendance_settings, announcements and sms_logs. Original backup, private SMS configuration and consumed claim hashes matched. Current live-enabled flag remains false; simulation remains enabled. Student #364 and log #265 were neither edited nor removed.

The two pending CSS files and four pending photo/upload assets match their starting hashes. Homepage/login HTML retained every original customization: removing only this phase's added UI wiring/labels reproduced their exact original hashes. Added HTML changes are listed separately below. No existing upload was deleted or overwritten.

## I. File manifest

The exact manifest is appended below. Cross-cutting endpoint changes are mainly replacement of session_start with the shared safe bootstrap; their existing business queries and SMS hooks were preserved except where specifically documented. No database migration or SMS-provider core change was introduced.

## J. Git status and review boundary

- Branch remains `development`; parent checkpoint is `d62abcd8181a4aab49231bff71fafdbcbcc89838`. The authorized local commit uses `Phase 8: Finalize workflows and unify system UI/UX`.
- `main` remains `1465cf93735b3e5bff7c1b80e3eec004e16b249c`.
- Exact checkpoint scope: 110 modified and 14 new paths. No blanket staging; the final commit hash is recorded in the completion report.
- Overlap: `index.html` only adds shared stylesheet/script wiring. `login.html` adds that wiring, an auth-page class, and the clearer supported identifier label/placeholder. Existing school photos, cache-version references and user CSS changes are preserved. The modified `js/index.js` depends on the shared wiring; those functional hunks are included together in this reviewed checkpoint.
- The original eight pending paths are listed separately after the manifest. Their pre-existing changes are not staged, reverted or discarded. Only separable Phase 8 hunks in the two overlapping HTML files are included.

## K. Remaining issues by severity

**Critical:** No known unresolved critical application defect was identified in this scoped verification. This is not a guarantee that no undiscovered defect exists.

**High before public deployment:** Actual HTTPS/cookie/runtime/error configuration, service-account/private-file ACLs, target database privileges, operational authentication abuse monitoring and official provider setup need verification. New file-lock budgets are single-server protection; distributed deployment needs shared coordinated budgets. Do not expose the development server publicly as a shortcut.

**Medium:** Native print pagination/readability not established in this run; official report templates pending. School borrower/visitor policy and reservation/attendance quotas, holidays, queue/expiry rules require confirmation. Existing pre-upgrade sessions bind a credential fingerprint on their next authorized access; expire old sessions during rollout to ensure universal revocation coverage. Administrator recovery requires the authorized custodian procedure. Provider status synchronization and scheduled reminders are not implemented.

**Low:** Further human accessibility/contrast, browser-specific and large-table usability review is desirable. Last Login remains Not recorded, not newly tracked. Generic institutional presentation is improved but not a substitute for librarian acceptance.

## L. Client-dependent requirements

Official templates for all eight reports, actual teacher borrowing eligibility/IDs/limits, visitor borrowing rules, approval authority for privileged librarian registration, loan duration/fines/renewals/lost-book policy, reservation daily/slot quotas and hours/closures/expiry, attendance Time Out/repeat/retention rules, authoritative classification/accession-per-copy conventions, photo/upload retention, and evidence cleanup approval remain client matters. No policy was silently invented.

For SMS turnover: obtain the school's authorized Semaphore credentials and approved sender; confirm recipients/consent, tariff/segment costs, balance ownership and replenishment. UniSMS retains its account/default-sender Globe/DITO/no-link restrictions; prefix checks cannot prove a ported number's carrier. Both providers keep the same library events/templates/logs/duplicate prevention; there is no uncertain-outcome cross-provider fallback. Refresh loads local logs only; pending/API acceptance is not delivered. The consumed UniSMS test authorization must not be reset/replayed. Any new real test needs separate explicit authorization.

## M. Guided demo checklist

- [x] Registration with actual IDs, pending approval and approved login — isolated HTTP; guest UI/token initialization checked.
- [x] Student request → approval → QR → staff authentication → physical-handover confirmation → return — isolated API and actual browser journey; no new cellphone scan claimed.
- [x] Teacher borrowing without staff privileges — actual isolated endpoints.
- [x] Reservation schedule → ready hold → checkout → return/cancel — isolated endpoints; calendar/waitlist/cancel browser journey.
- [x] Registered teacher and manual visitor attendance/Time Out — isolated endpoints; no permanent visitor account invented.
- [x] Book CRUD, inventory/history protection and safe uploads — isolated endpoints; browser add/cancel-delete.
- [x] Announcement publication/removal/public visibility — isolated endpoints and escaping regressions.
- [x] All generic report sources and date filters — isolated endpoints and browser selectors.
- [x] Recovery request/code/reset/replay protection — deterministic isolated endpoint copy; real source randomness retained.
- [x] Notification hooks/logs/duplicate protection — simulation, provider mocks and persistent guard tests.
- [ ] Fresh native multipage print acceptance, official client template, target deployment and newly authorized live provider acceptance.

Use a disposable demo environment or explicitly approved records for a future demonstration. Do not run this checklist by mutating the current legitimate database without authorization.

## N. Turnover and implementation notes for student developers

The new features use built-in browser/PHP mechanisms, without a new framework:

- **flock** serializes read/check/write of hashed filesystem abuse counters. Login allows at most 30 attempts per IP and 10 per identifier per 15-minute window; reset requests allow 10 per IP/hour, three per phone/hour and one per phone/minute. Code verification allows five per challenge and 30 per IP/15 minutes. Storage errors fail closed; monitor storage availability. These counters are separate from the existing persistent one-SMS claim and never reset it.
- **Recovery challenge binding** uses a random short marker in the existing column and a full password_hash of the code in the PHP session. The same browser must finish recovery; requesting a new challenge invalidates older ones. This avoids a destructive column migration or public OTP exposure.
- **Session fingerprint** compares a hash of the current password hash with the initialized session value. Credential changes invalidate bound sessions; status/role checks remain database-backed. HttpOnly/SameSite Lax are verified on MAMP; direct HTTPS enables Secure. Trusted proxy HTTPS and Windows ACL behavior need deployment-specific checks.
- **HTML dialog / Promise** keeps confirmation asynchronous. Callers await a boolean; cancellation returns before fetch. MutationObserver adapts existing inline confirmation panels without rewriting their business handlers. ResizeObserver updates overflow cues; textContent prevents markup injection.
- **Shared CSS** adds consistent tokens after page styles and scopes structural scrolling to staff pages. Print overrides remove fixed-screen heights and overflow limits, but their native output still requires human verification.

Apache checks on MAMP returned 403 for private include, SQL, script and test paths, while an existing legitimate uploaded image still returned 200. Upload rules deny executable extensions and directory listing. Confirm AllowOverride, HTTPS, display_errors off, PDO/mbstring/fileinfo/cURL/CA configuration, least-privilege DB access, private backup retention/restore drill and service account permissions on the actual server. Unix chmod checks are skipped on Windows in favor of a required ACL review; Windows deployment is not claimed verified. The QA runner itself uses Unix worker cleanup and is not a production feature.

## O. Overall verdict

**Ready for internal testing and a guided capstone demo of verified scope. Ready to begin client acceptance review, not to declare final acceptance complete. Not yet ready for production deployment.** The application is improved and its original data/credits/assets are preserved. Remaining client/deployment/print limitations are explicit. No next phase or live SMS was started.

### Exact modified Phase 8 paths (110, including two overlapping HTML files)

```text
.htaccess
about.html
admin-dashboard.html
admin-login.html
announcements.html
attendance.html
backend/admin-logout.php
backend/admin-session-status.php
backend/all-users-data.php
backend/announcement-delete.php
backend/announcement-management-data.php
backend/announcement-save.php
backend/announcements-data.php
backend/attendance-add.php
backend/attendance-data.php
backend/attendance-settings.php
backend/attendance-students.php
backend/attendance-time-out.php
backend/book-delete.php
backend/book-monthly-stats.php
backend/book-save.php
backend/books-data.php
backend/borrow-request-action.php
backend/borrow-request-add.php
backend/borrow-request-process.php
backend/borrow-requests-data.php
backend/borrowing-history-data.php
backend/borrowing-report-data.php
backend/dashboard-data.php
backend/forgot-password-process.php
backend/forgot-password-request.php
backend/login-process.php
backend/logout.php
backend/my-pickup-requests-data.php
backend/my-requests-data.php
backend/my-reservations-data.php
backend/pickup-qr-data.php
backend/pickup-verification-data.php
backend/profile-data.php
backend/profile-update.php
backend/register-process.php
backend/registration-token.php
backend/reports-data.php
backend/reservation-action.php
backend/reservation-availability.php
backend/reservation-process.php
backend/reservation-settings.php
backend/reservations-data.php
backend/reset-password-process.php
backend/session-status.php
backend/sms-logs-data.php
backend/transactions-data.php
backend/user-approval-action.php
backend/user-delete.php
borrow-requests.html
browse-books.html
forgot-password.html
includes/.htaccess
includes/approved-user-session.php
includes/book-management.php
includes/borrowing.php
index.html
js/about.js
js/admin-dashboard.js
js/admin-login.js
js/admin-shell.js
js/announcements.js
js/attendance.js
js/borrow-requests.js
js/borrowing-status.js
js/browse-books.js
js/forgot-password.js
js/index.js
js/login.js
js/manage-announcements.js
js/manage-books.js
js/manage-users.js
js/pickup-verification.js
js/profile.js
js/register-librarian.js
js/register-student.js
js/register-teacher.js
js/register-visitor.js
js/reporting.js
js/reports.js
js/reservations.js
js/reset-password.js
js/select-role.js
js/services.js
js/sms-logs.js
js/transactions.js
login.html
manage-announcements.html
manage-books.html
manage-users.html
pickup-verification.html
profile.html
register-librarian.html
register-student.html
register-teacher.html
register-visitor.html
reports.html
reservations.html
reset-password.html
select-role.html
services.html
sms-logs.html
tests/phase2b-borrowing.js
tests/phase4-reservations.js
transactions.html
```

### Exact new Phase 8 paths (14)

```text
PHASE8.md
css/library-ui.css
includes/security.php
includes/session.php
js/library-ui.js
scripts/.htaccess
scripts/phase8-isolated-qa.php
sql/.htaccess
tests/.htaccess
tests/phase8-security.php
tests/phase8-ui.js
tests/phase8-workflows.php
uploads/announcements/.htaccess
uploads/books/.htaccess
```

### Original unrelated pending paths — preserved, unstaged

```text
css/index.css
css/login.css
index.html
login.html
images/school-entrance.png
uploads/announcements/cab2b00b4032d7442f091c16d66e5ce7.jpg
uploads/books/0b868fc0ef0b5b1e2dba5255a94be14b.webp
uploads/books/1e9ecf96dab133c920fd6cbeefb497a6.webp
```


## Final acceptance review — 28 October 2026

The final review retains the exact 124-path manifest below/above; no extra framework, migration, provider change or unrelated asset is included. File-by-file scope review found 34 endpoint changes consisting only of the shared session bootstrap; other changes implement the documented security, identity, feedback, layout and regression responsibilities. Root HTML checks found no duplicate IDs or missing local script/style/image references across 26 pages.

### Targeted corrections found in acceptance

1. Recovery resend errors were written into the hidden request form. Feedback now targets the visible reset form, and request/reset/resend controls reject overlapping submissions. Isolated browser reproduction confirms a throttled resend remains visible.
2. Registration had two submit listeners. One authoritative listener now checks token readiness, matching passwords and an in-flight flag before submitting. Existing role routing and password visibility are retained.
3. Profile tables rendered after initial load missed keyboard-accessible overflow regions. An idempotent observer enhances newly inserted tables only. Teacher history at 390px scrolls inside its region without page overflow.
4. Errors behind native confirmation dialogs could become inert/inaccessible. Staff user/announcement and attendance errors now also appear as literal live-status text inside the active dialog. An isolated stale-record deletion fault verified visible failure and usable cancellation.
5. Legacy ID-based confirmation styles overrode shared centering. Narrow selectors restore automatic margins and white dialog surfaces. Measured centers match viewport centers at all five widths.
6. Four staff headers showed ADMIN for librarians. Books, requests, reservations and transactions now show the authenticated server role; the staff login subtitle includes librarians. All four were checked with a disposable librarian.

### Final evidence and limitations

- Final safe run: **133 isolated HTTP/database workflow checks, 333 database-free PHP checks, 632 JavaScript checks and four Books groups passed**. Syntax: 99 PHP files and 43 JavaScript files. Existing tests requiring live-data assumptions were not run on the active database.
- Teacher coverage includes real registration with faculty ID, pending-login denial, staff approval, approved login, borrowing, checkout, return, own history and circulation/user reporting. It is not merely a label change. Visitors remain catalog viewers/manual attendance subjects without self-service borrowing or staff privileges.
- Extended isolated coverage includes announcement PNG upload/edit/image preservation/delete; attendance repeat-visit confirmation, daily quota and occupancy rejection. Notifications stayed simulated or safely skipped.
- Final browser evidence includes 55 staff and 40 public/guest page-width checks at 1440, 1024, 768, 390 and 320px; centered confirmation measurements at all five widths; mobile teacher history; visible recovery throttling; stale-record dialog feedback; correct librarian headers. Prior student/QR/return and sidebar browser evidence remains recorded. These scoped checks do not imply every possible action/browser combination passed.
- Native report print pagination remains **unverified**. No physical printing, new physical-phone QR test or live SMS/provider request was performed. Historical handset acceptance remains user-confirmed; the last confirmed balance remains eight credits, not newly queried.
- Fresh private backup SHA-256 verified. Final row counts and full row hashes for all ten active tables equal both acceptance-start and original audit snapshots. Student #364/log #265, private SMS configuration and consumed claim are unchanged. Only owned Phase 8 disposable environments and their private credentials were removed.
- Original eight pending user paths remain intact. Homepage/login shared wiring and identifier labels are separated in the index from photo cache-version changes without rewriting the working files. No private configs, backups, claim ledgers, uploads or generated evidence belong to this checkpoint.

Readiness remains internal testing/guided demonstration and commencement of client acceptance, with the deployment, school policy, official report and print limitations in sections K–M still outstanding. Production readiness is not claimed.
