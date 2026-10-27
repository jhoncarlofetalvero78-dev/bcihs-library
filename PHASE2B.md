# Phase 2B — Borrowing, pickup, checkout and return

Implemented locally on `development`, based on Phase 2A commit `98b4fc0`.
No commit, push, merge, deployment, production connection, or physical-copy /
Category QR feature is part of this phase.

## What changed

The old system incremented borrowed inventory on approval and started the loan
period immediately. Returns could be repeated, ready reservations did not hold
stock, and deleting a user could cascade-delete loan history.

The new lifecycle is:

| Database status | Meaning | Stock effect |
| --- | --- | --- |
| `pending` | Patron requested a title | No hold or checkout |
| `awaiting_pickup` | Approved; a physical copy is held | One pickup hold; borrowed unchanged |
| `checked_out` | Staff confirmed physical handover | Hold consumed; borrowed increases once |
| `returned` | Staff confirmed physical return | Borrowed decreases once |
| `rejected` | Pending request rejected or pickup approval withdrawn | Any pickup hold released |
| `approved` (legacy only) | The earlier system already checked this loan out | Remains borrowed until returned |

New approvals never write `approved`. Keeping that old enum value avoids
reinterpreting existing outstanding loans as mere pickup approvals. Existing
approval, due, and return timestamps are not rewritten. New loans have a separate
`checked_out_at`; legacy display falls back to their original `approved_at` only
because the old workflow used that field for handover.

The normal loan period remains **14 days from actual checkout**. New checkout
and return dates come from MySQL, not submitted dates. PHP and each affected
MySQL connection use Philippine library time (`Asia/Manila`, UTC+08:00), without
changing the server's global configuration. This also makes pickup validation
consistent with loan dates.

## Inventory and reservation rules

- Physical availability = total copies minus outstanding checked-out copies.
- Held copies = borrow requests awaiting pickup plus reservations marked ready.
- Requestable copies = physical availability minus holds (never offered below zero).
- Pending requests and waiting reservations do not reserve stock. Library staff
  choose which eligible request/reservation to approve or mark ready.
- New approvals and ready reservations need an unallocated copy. A ready
  reservation consumes its own hold at fulfillment; a waiting reservation can
  be explicitly checked out only using unallocated stock, preserving the earlier
  workflow without taking another patron's held copy.
- Approval does not increment borrowed stock. Only explicit checkout/fulfillment
  does. Return restores stock once. Cancellation/rejection releases holds.
- Catalog edits cannot reduce total below borrowed plus held copies. Book/user
  deletion is refused when related transaction history exists, including rejected,
  cancelled and returned records.

All writes lock the book first, then the request/reservation row. A transaction
holds those locks until commit/rollback. Concurrent operations therefore see the
current count and status rather than an earlier browser value. SQL also bounds
stock increments/decrements; inconsistent stock is rejected for librarian review,
not silently clamped or repaired. Notifications run after commit, and their
failure does not turn a successful checkout/approval into a reported failure.

Approved patrons retain the existing ability to borrow; no new role restriction
or arbitrary total-loan limit was introduced. A registered patron can have only
one active request/loan/reservation for the same title. Different titles are not
given a new global cap. Approval checks and ownership are validated against the
database, and all affected writes require the session CSRF token.

No automatic hold expiry or strict queue-priority policy was invented. Staff
must reject an unclaimed pickup approval or cancel a ready reservation to release
its hold. Rejected/disabled patrons cannot receive new checkouts; staff can still
record returns of their outstanding books.

## Manual Add Borrowed Student

The existing typed-name feature remains available for borrowers without accounts.
It now explicitly says **Confirm Physical Checkout** and asks for confirmation.
Saving it creates a checked-out record immediately because it represents an
actual handover, not an approval waiting for pickup.

A blank due date uses 14 days from server checkout. The existing staff-only
calendar-date override is preserved, strictly validated, and ends at 23:59:59
library time. It cannot be past or exceed the existing MySQL TIMESTAMP range.

A separate form token makes a repeated submission return the original saved loan
rather than increment stock again. Its last 20 completed results are kept in the
PHP session; using a consumed token for different input is refused. This retry
protection is session-scoped, not a durable cross-session transaction key. Staff
should verify Transactions before recreating a manual record after session loss.
Typed names cannot reliably enforce a per-person limit or identify matching user
accounts; no identity is guessed from a name.

## Database changes and protected backups

Two explicit additive migrations were applied **only to local `bcihs_library`**:

1. `sql/borrowing-lifecycle-migration.sql` appends `awaiting_pickup` and
   `checked_out` to the existing status enum and adds nullable `checked_out_at`.
   The original four enum entries retain their order and meaning.
2. `sql/reservation-due-date-migration.sql` adds nullable `reservations.due_date`.
   Only new fulfillment writes it; old reservation due dates stay unknown/null.

`sql/schema (2).sql` was updated for future fresh installations. It was **not
reimported**. No existing table was dropped or reset, and no historical row was
converted, overwritten, or automatically reconciled.

Protected backups, outside the web root and repository, were saved before the
local changes:

- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase2b-20261027-133845-5722a19a.sql`
- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase2b-20261027-134444-f10b2403.sql`

The files and checksum manifests have mode `0600`; the backup directory is private.
Do not commit, upload, share, or serve them: a database backup contains account data.
Original table columns were checksummed before and after each migration, confirming
that all original row values, counts, statuses and dates remained unchanged.

### Running the migration on another local installation

Stop local application activity while migrating. From the project directory:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/migrate-borrowing.php /ABSOLUTE/PRIVATE/BACKUP/DIRECTORY /Applications/MAMP/Library/bin/mysql80/bin/mysqldump
```

Use XAMPP's PHP and matching MySQL/MariaDB dump executable on XAMPP. The script
uses the existing private database configuration and refuses remote database hosts.
Credentials are passed in a temporary `0600` option file, never command arguments;
that option file is removed afterward. A failed backup prevents migration.

The script checks the known schema, existing stock/ready holds and outstanding
loan counts, backs up first, applies only missing known changes, and verifies
original values afterward. It refuses unexpected partial schemas. Re-running a
completed migration reports that no changes are needed. Keep the original schema
and stock under review if a migration stops; do not run reset/truncate SQL.

### Rollback explanation

MySQL schema changes commit independently; a PHP transaction cannot undo an
`ALTER TABLE`. Keep the backups. The least destructive rollback is to leave the
additive columns/enum values in place while fixing application code.

Before removing anything, stop the local app and check:

```sql
SELECT COUNT(*) FROM borrow_requests
WHERE status IN ('awaiting_pickup','checked_out') OR checked_out_at IS NOT NULL;
SELECT COUNT(*) FROM reservations WHERE due_date IS NOT NULL;
```

Only when both counts are zero can a reviewed schema downgrade safely consider
removing the unused additions. Once new loans exist, preserve/export their states
and dates and plan a data-aware downgrade first. Never shrink the enum, drop
checkout dates, or import the old backup over newer legitimate activity blindly.
Restore a backup into a separate local database for comparison if needed. No
rollback/drop/reset was executed in this phase.

## Cross-module effects

- Borrow Requests shows valid actions only, with confirmations and separate
  approval/checkout/due/return timestamps in Details.
- Manage Books and Dashboard retain physical available counts and show pickup
  holds separately. The catalog uses requestable stock rather than offering a
  copy already held for another patron.
- Transactions combines borrow requests and reservations, shows actual checkout
  dates, and counts only outstanding physical loans as currently borrowed.
- Student history no longer counts pending/awaiting requests as borrowed. Failed
  history loading shows an error/unknown counts instead of invented sample loans.
- Reports' immediate loan/summary calculations use checkout dates and distinguish
  awaiting pickup from actual loans. The existing report layout was retained.
- The monthly Dashboard chart now counts outstanding borrow and reservation loans
  at each month boundary instead of accumulating returned loans as borrowed.
  Historical total-stock changes are not reconstructible from the current schema.
- The existing book QR still opens the selected catalog book and preserves login
  return navigation. It performs no approval, reservation or checkout by itself.

New shared files are plain PHP functions, a small JavaScript status/date helper,
and scoped CSS. No framework, package, queue, or alternate authentication system
was introduced.

## Tests and actual results

Run with local Apache/MySQL running:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2b-borrowing.php
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2a-books.php
node tests/phase2b-borrowing.js
node tests/phase2a-books.js
```

The PHP suites accept a localhost base URL as the first argument. Test accounts,
books, loans, reservations, skipped-notification logs and cookie files are clearly
identified and cleaned up. Original records are checksummed before/after the
Phase 2B run. Only temporary fixtures are deleted; no SMS is sent.

The concurrency tests use different authenticated PHP sessions, deliberately wait
on the same locked book row, then compete for approval, checkout, return and
reservation fulfillment. MySQL 8 lock inspection is used when available; another
server can still run concurrent requests without that diagnostic probe.

Final results on MAMP/MySQL 8:

| Check | Result |
| --- | --- |
| Phase 2B HTTP/database checks | 164 passed, 0 failed |
| Updated Phase 2A API regression checks | 64 passed, 0 failed |
| Phase 2B JavaScript checks | 50 passed, 0 failed |
| Existing Phase 2A JavaScript suite | Passed |
| PHP syntax | All 53 files passed |
| JavaScript syntax | All 30 files passed |
| Changed HTML IDs and local asset references | All 9 pages passed |
| `git diff --check` | Passed |
| Migration rerun | Already applied; no changes made |
| Fixture cleanup | Original records verified unchanged; temporary records removed |

The coverage includes identity/date tampering, invalid states and IDs, missing CSRF,
revoked approval, duplicate active requests, holds, physical inventory, default and
manual due dates, repeated/manual retries, legacy-date preservation, historical
delete protection, cross-module APIs and cleanup fingerprints.

Browser checks on MAMP covered pickup status with blank checkout/due dates,
explicit checkout with the user's manual confirmation, Dashboard/Manage Books
counts, Transactions dates/status, the manual form, Details, a 390-pixel viewport,
and the existing selected-book catalog route. The temporary UI loan was returned
through an authenticated API and the Returned display was verified in the browser.
The browser's native confirmation could not be automated reliably, and native
Codex app control was blocked; it was not bypassed. Browser settings were restored.

Full student browser submission, manual-form final confirmation, a phone scan,
Windows/XAMPP and actual SMS delivery remain manual acceptance checks. Their
backend paths were tested locally; no real patrons were used.

## Administrator and student acceptance checklist

1. Reload the pages so the new versioned scripts/styles are used. Sign in as an
   accepted student and open a book, including a `?book=<id>` QR link. Request it.
2. As librarian/admin, confirm Pending has no checkout or due date. Approve it.
   Expect Awaiting Pickup, one hold, and no increase in borrowed count.
3. Confirm Checkout only when handing over the book. Expect one borrowed copy,
   a checkout timestamp and a due date 14 days later. Refresh Manage Books,
   Dashboard, Transactions and student history to compare the same transaction.
4. Repeating Checkout must fail without changing counts. Reject a different
   pending request and confirm it cannot be checked out.
5. Confirm Return only after receiving the physical book. Expect stock restored,
   a return timestamp, retained original dates and no second decrement on retry.
6. Test Add Borrowed Student with a clearly marked local name: blank/default due
   date, valid staff override, invalid/past date and a repeated form submission.
7. Test a ready reservation competing for the last copy. Other approvals/manual
   checkouts must not take that held copy. Cancel/reject a hold and verify release.
8. Verify book/user deletion is refused when transaction history exists. Never
   delete legitimate records to test cleanup; use clearly marked fixtures only.
9. Review a phone QR using a reachable local network URL; localhost identifies
   the hosting computer and does not point a phone to that computer automatically.

## Follow-up scope, not implemented

No Category QR, individual-copy table, individual-copy QR or another phase was
started. Later Reservations work should define queue priority and pickup expiry;
Transactions/Reports work can add full event/audit history and historical stock
snapshots. Physical-copy/accession tracking and durable manual-operation keys
require a separate reviewed schema plan. Future Category QR should only filter
the catalog and use the existing authenticated request process. Keep scan,
authorization, handover and return as explicit separate actions.

## Complete file manifest

Modified (40):

- `admin-dashboard.html`
- `backend/all-users-data.php`
- `backend/book-monthly-stats.php`
- `backend/book-save.php`
- `backend/books-data.php`
- `backend/borrow-request-action.php`
- `backend/borrow-request-add.php`
- `backend/borrow-request-process.php`
- `backend/borrow-requests-data.php`
- `backend/borrowing-history-data.php`
- `backend/borrowing-report-data.php`
- `backend/my-requests-data.php`
- `backend/my-reservations-data.php`
- `backend/public-books-data.php`
- `backend/reservation-action.php`
- `backend/reservation-process.php`
- `backend/reservations-data.php`
- `backend/user-delete.php`
- `borrow-requests.html`
- `browse-books.html`
- `css/manage-books.css`
- `includes/book-management.php`
- `js/admin-dashboard.js`
- `js/borrow-requests.js`
- `js/browse-books.js`
- `js/manage-books.js`
- `js/manage-users.js`
- `js/profile.js`
- `js/reports.js`
- `js/reservations.js`
- `js/transactions.js`
- `manage-books.html`
- `manage-users.html`
- `profile.html`
- `reports.html`
- `reservations.html`
- `scripts/check-local-db.php`
- `sql/schema (2).sql`
- `tests/phase2a-books.php`
- `transactions.html`

Created (10):

- `PHASE2B.md`
- `backend/transactions-data.php`
- `css/borrowing.css`
- `includes/borrowing.php`
- `js/borrowing-status.js`
- `scripts/migrate-borrowing.php`
- `sql/borrowing-lifecycle-migration.sql`
- `sql/reservation-due-date-migration.sql`
- `tests/phase2b-borrowing.js`
- `tests/phase2b-borrowing.php`
