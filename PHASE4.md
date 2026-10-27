# Phase 4 — Reservations, scheduling and pickup capacity

Implemented locally on `development`, based on commit `bf3019a`, and approved for a Phase 4 Git checkpoint based on the reported tests. No push, merge, deployment, production access, pending QR implementation, Attendance work or SMS feature changes.

## Existing behavior reviewed

Phase 2B already had safe book-row locking, duplicate-active checks, Waiting/Ready/Fulfilled/Returned/Cancelled states, shared pickup holds, explicit checkout/return, and 14-day reservation loans. Those working inventory rules were retained.

The gaps were scheduling quotas, device-local calendar time, an administrator calendar based only on submission dates, missing librarian reschedule/details controls, missing student cancellation controls and complete reservation history, and personal-state fetch failures silently appearing as an empty request/reservation list.

## Completed changes

- Added optional daily and half-hour pickup limits in the existing Reservations page. Blank means unlimited; no quota was invented. Both administrators and approved librarians can manage them.
- Added an authenticated availability endpoint that returns aggregate slot/day occupancy, policy and Philippine server time without exposing patron identities.
- Reused one shared vanilla JavaScript pickup picker on the catalog and staff page. Weekends, past times, full days and full slots cannot be newly selected. Original Monday–Friday, 07:00–16:00, half-hour schedule is preserved, including the existing 16:00 slot.
- New submissions and reschedules revalidate dates/capacity on the server after acquiring locks. A stale calendar cannot overbook a slot or promise stock.
- The administrator calendar defaults to pickup dates, with an optional submission-date view. Historical dates remain selectable for review, even when closed for new bookings. Full daily capacity is indicated.
- Added staff scheduling for Waiting reservations, readable status-specific action buttons, borrower/book information, physical-stock hints and a details dialog with lifecycle dates. Checkout without available stock is disabled in the interface and independently rejected by the server.
- Students can cancel Waiting or Ready reservations from the catalog. A Ready cancellation releases its hold. Checked-out reservations require Return, not cancellation.
- Added a Reservations tab to the existing Profile page showing all reservation statuses and their preferred/confirmed pickup, checkout, due and return dates. Cancelled and returned history is retained. The ordinary borrowing-history query remains unchanged and already includes reservation loans exactly once. Its total is now labeled **Borrowing Records**, distinguishing it from the complete reservation list.
- Catalog personal-state failures now produce a load error instead of offering actions based on falsely empty state. Mutation errors refresh stock/status data; capacity conflicts refresh the picker.
- Kept existing maroon/white styling, page structure, Book QR links and supported authentication/CSRF rules.

## Policy retained and policy decisions still needed

**Scheduling capacity is not inventory.** Only scheduled Waiting and Ready reservations consume capacity. Fulfilled, Returned and Cancelled rows do not occupy planned future pickups. This is a scheduling limit, not a count of all visits already completed that day. The limit applies across all titles.

Waiting does not allocate a copy. Ready holds one requestable copy and competes with approved borrow-request holds using the same Phase 2B stock calculation. Fulfillment consumes the reservation's own hold, increases borrowed inventory once and starts the actual 14-day loan. Return decreases borrowed inventory once. Invalid repeated actions fail without changing counts or dates.

No automatic expiry or arbitrary deadline was added. Staff cancel unclaimed Waiting/Ready reservations. For approved borrow requests, the existing Reject action releases an awaiting-pickup hold; checked-out loans still require Return. No cron job is required.

The existing staff option to confirm a physical handover directly from Waiting remains supported when an unallocated copy is available. It is an explicit checkout, never an automatic promotion based on a chosen date. Mark Ready first remains the normal pickup workflow.

Queue position is advisory, as before; no strict FIFO enforcement or automatic promotion was introduced. No holiday/exception-day policy existed, so weekend closure remains the configured closure rule. Staff must confirm operational daily/slot quotas, whether Waiting and Ready should both occupy their planned schedule, any holiday closures, strict queue priority, and any future abandonment deadline with the librarian/client. Defaults remain unlimited and no deadline applies.

## Database migration and safety

Added only `reservation_settings`: one policy row (`id=1`) with nullable `daily_limit` and `slot_limit`. Positive limits support 1–65535, the storage range; NULL retains unlimited behavior. No existing table was dropped, truncated, reset or reimported. No existing reservation/loan row or historical timestamp was rewritten.

The explicit additive SQL is `sql/reservation-capacity-migration.sql`; the fresh-install schema includes it too. Existing installations should run the guarded migration, not reimport the full schema:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/migrate-reservation-capacity.php /ABSOLUTE/PRIVATE/BACKUP/DIRECTORY /Applications/MAMP/Library/bin/mysql80/bin/mysqldump
```

The script refuses non-local hosts or another database name through the existing local CLI guard, creates a private backup outside the web root before DDL, verifies original-row fingerprints, and preserves an existing policy on repeat execution. Credentials are passed through a temporary mode-0600 MySQL option file, never command-line password arguments; temporary files are removed.

Protected backups created during this phase:

- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase4-20261027-163928-da2395.sql` — before migration.
- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase4-20261027-165403-07e4c2.sql` — before browser fixtures.

Both have private fingerprint manifests beside them. Keep the new table if rolling back application code; the previous application simply ignores it. No destructive data rollback is needed. Preserve backups and inspect partial migrations before retrying rather than deleting/reimporting existing tables.

### Locking explanation for student developers

Capacity applies across different books, so locking only a book cannot prevent two patrons taking the final appointment slot for different titles. Creation, rescheduling and policy updates first lock the single settings row with `SELECT ... FOR UPDATE`. They then check occupancy inside the same transaction. Creation/rescheduling take the existing book lock after that policy lock. This briefly serializes capacity-increasing operations without a new service or worker.

Other stock transitions keep the existing book-first lock order. Ready does not increase scheduled occupancy, while cancellation/fulfillment can only release it; those actions do not acquire the policy lock. This avoids introducing a reverse policy/book lock order. Existing quota reductions below active schedules are rejected; no historical reservation is changed to make a new limit fit.

`js/reservation-scheduling.js` is a shared form controller, not a framework: each page supplies its save callback, while the controller handles calendar state, Philippine time, availability reads and display. A response counter ignores stale month responses during fast navigation. The server always performs the final validation.

## Exact Phase 4 file manifest

Modified (14):

- `backend/my-reservations-data.php` — include Ready timestamp and author in the existing private history response.
- `backend/reservation-action.php` — capacity-aware rescheduling with a second date check inside the transaction.
- `backend/reservation-process.php` — locked policy/occupancy checks and date revalidation.
- `backend/reservations-data.php` — richer details, stable advisory queue order and shared stock figures.
- `includes/reservation-schedule.php` — explicit Philippine parsing and reusable capacity helpers.
- `browse-books.html` — shared picker assets, capacity guidance and retry control.
- `css/browse-books.css` — move existing picker styles to their shared stylesheet.
- `js/browse-books.js` — shared picker, cancellation, clear errors and accurate state refresh.
- `reservations.html` — optional settings panel, calendar basis, picker and details dialogs.
- `js/reservations.js` — pickup-date calendar, scheduling, details, policy form and appropriate actions.
- `profile.html` — reservation-history tab and shared assets.
- `js/profile.js` — private reservation-history rendering and refresh.
- `sql/schema (2).sql` — fresh-install capacity table/defaults.
- `tests/phase2b-borrowing.js` — load the shared picker dependency in its existing test environment.

Created (11):

- `backend/reservation-settings.php`
- `backend/reservation-availability.php`
- `css/reservation-scheduling.css`
- `js/reservation-scheduling.js`
- `scripts/local-backup.php`
- `scripts/migrate-reservation-capacity.php`
- `sql/reservation-capacity-migration.sql`
- `tests/phase4-reservations.php`
- `tests/phase4-reservations.js`
- `tests/phase4-browser-fixtures.php`
- `PHASE4.md`

## Automated verification — actual executed results

| Check | Result |
| --- | --- |
| Phase 4 HTTP/MySQL suite | **169 passed, 0 failed** on the final run |
| Phase 4 JavaScript suite | **48 passed, 0 failed** |
| Existing Phase 2B HTTP/MySQL suite | **164 passed, 0 failed** |
| Existing Phase 2A book API suite | **64 passed, 0 failed** |
| Existing Phase 2B JavaScript suite | **50 passed, 0 failed** |
| Existing Phase 2A JavaScript suite | Passed all four reported groups |
| PHP syntax, excluding private configuration | **58 files passed** |
| JavaScript syntax | **32 files passed** |
| Changed HTML pages | All three preserve original IDs, have unique IDs and valid local assets |
| Working-tree whitespace | `git diff --check` passed |

Phase 4 API checks cover guests/unapproved accounts/staff permissions, CSRF, invalid dates and times, duplicate active reservations, unavailable/held titles, Waiting without borrowed stock, competing Ready allocations, reservations versus approved-request holds, duplicate concurrent fulfillment and return, 14-day checkout dates, hold cancellation, quota validation/reduction, reschedule rollback, private aggregate availability and own-allocation exclusion, concurrent slot and daily limits across different books, and concurrent duplicate submissions from independent sessions for the same patron. History, Transactions, Reports and stock consistency were checked. Actual lock waits were observed in MySQL, not inferred from sequential calls.

Tests create only uniquely prefixed local fixtures with no phone numbers, send no SMS, remove only their own records/photos/cookies, and verify original rows afterward. Capacity tests refuse to run while unrelated future active schedules exist, since they temporarily exercise global limits. Run them on a quiet local installation.

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase4-reservations.php
node tests/phase4-reservations.js
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2b-borrowing.php
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2a-books.php
node tests/phase2b-borrowing.js
node tests/phase2a-books.js
```

## Real browser and mobile checks

Used independent disposable student/staff sessions on local loopback, preserving the user's normal localhost sessions. Actual browser controls were used for student submission, month navigation, full-slot display, librarian rescheduling, Mark Ready, Confirm Checkout, Confirm Return, Details, student Ready status/history and Returned history. Native confirmation dialogs were retained and accepted using native browser controls where the automation dialog API timed out; no application confirmation bypass was introduced.

The browser fixture initially had zero copies. Its own stock was then made available, after which Mark Ready held exactly one copy. Checkout recorded the actual handover and due dates; return restored availability. Dashboard showed the expected extra loan during checkout, and Transactions showed each test reservation once. Student history retained the returned record.

The capacity form was opened and its controls/confirmation inspected; the final browser capacity-save attempt was cancelled. Quota saves and reductions were tested through the real HTTP API. Student cancellation was verified through the HTTP API; its Waiting/Ready controls were inspected in the browser. No separate completed browser student-cancellation click is claimed.

| Surface | Desktop | Tablet | Phone |
| --- | --- | --- | --- |
| Staff Reservations + capacity controls | 1440px, pass | 768px, pass | 390px, pass |
| Student Reservations history | 1440px, pass | 767px, pass | 390px, pass |
| Student picker | 1440px, pass | 767px, pass | 390px, pass |

Actual DOM widths were measured. No horizontal document overflow was found; wide tables scroll inside their containers. The picker and Cancel/Join waitlist actions also fit at **390×844**. Native keyboard navigation reached its actions. Existing expired/closed dates remain non-bookable, while staff can review historical calendar records.

Screenshots, viewport measurements and full test logs are outside Git in:

`/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase3a/phase4/`

Fixture screenshots show temporary test data that has since been removed. The browser fixture tool supports repeatable local acceptance testing:

```sh
php tests/phase4-browser-fixtures.php setup /ABSOLUTE/PRIVATE/DIRECTORY
php tests/phase4-browser-fixtures.php stock /ABSOLUTE/PRIVATE/DIRECTORY
php tests/phase4-browser-fixtures.php cleanup /ABSOLUTE/PRIVATE/DIRECTORY
```

Use MAMP's PHP binary if `php` points to another installation. Credentials and the identity manifest are kept privately outside the web root; cleanup verifies the original row snapshot before committing its own deletions.

## Checkpoint approval and pending confirmation

The implementation is approved for a Git checkpoint based on the reported automated tests. **Final UI confirmation of capacity-setting saves and student cancellation remains pending.** Their HTTP API tests passed; the completed browser actions and limitations above remain the actual verification record.

Librarian/client confirmation of actual daily and slot quotas, holiday closures, and pickup expiry rules also remains pending. This checkpoint does not establish those policies. Until confirmed, the documented unlimited quotas, weekday schedule and manual cancellation/no-automatic-expiry behavior remain in place.

## Manual acceptance guide

1. In Reservations, open Pickup capacity settings. Blank limits mean unlimited. Confirm actual librarian quotas before setting them; a reduction below existing schedules should be refused.
2. Using designated disposable accounts/titles, try reserving an unavailable title. Confirm weekday/future/half-hour rules, full-slot/date labels and the stock disclaimer.
3. Check the pickup-date calendar against the preferred schedule. Switch to submission dates and back. Open Details and reschedule a Waiting reservation.
4. Confirm Mark Ready fails without stock. Once an unallocated copy exists, mark it Ready and check held/requestable counts in Manage Books and the student's catalog.
5. Confirm physical checkout. Verify the actual checkout timestamp, due date 14 days later, one borrowed copy, Transactions, Dashboard, borrowing history and reservation history. Confirm Return and verify stock restoration and retained history.
6. Cancel another Waiting/Ready reservation using its owner or staff account. Confirm the hold/capacity is released, while the Cancelled record remains in history. Checked-out records must not be cancellable.
7. Review the 390px layouts and keyboard access. Use the existing automated concurrent-request tests for last-copy and last-slot races rather than manually clicking twice.
8. Clean up only the designated fixtures and verify original records. The official Reports template and pending QR proposal remain separate work.

## Final data/Git state

All eight pre-migration table fingerprints match their original values after tests and browser cleanup. Settings are restored to unlimited. Final original row counts: admins 1, users 1, books 1, borrow requests 2, reservations 0, SMS logs 1, attendance 0, announcements 0. The new settings table has its one default policy row. No fixture credentials/manifest remains.

The Phase 1, 2A, 2B and 3A commits remain intact. The Phase 4 checkpoint contains only the 25 files in the manifest; unrelated photo work is excluded.

Unrelated uncommitted image work was preserved byte-for-byte and not staged or modified by this phase:

- `css/index.css`
- `css/login.css`
- `index.html`
- `login.html`
- `images/school-entrance.png` (untracked image asset)

No other phase was started. Checkpoint approval does not close the pending UI and librarian policy confirmations above.
