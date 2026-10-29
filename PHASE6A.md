# Phase 6A — Reports, statistics and Dashboard accuracy

Implemented locally on `development`, based on `acfd4dc` (Phase 5). Provisionally approved, with final responsive checks completed and fresh print preview confirmed by the user for the Phase 6A checkpoint. No production, main, deployment, push or merge actions. No official school report template was supplied; all print output is explicitly a generic working report. Phase 6B and Phase 7 were not started.

## Scope and findings

The original Reports page loaded several broad datasets and applied date filters only in the browser. It had no reservation or attendance report and no CSV export. It fetched user contact details unnecessarily. Inventory showed physical availability without showing pickup allocations. Its overdue calculation used the device date. The Dashboard monthly inventory chart inferred historical copy totals from current book records even though the database has no stock-history ledger. Recent reservations were taken from a book/queue-ordered endpoint rather than sorted by recency. Dashboard labels did not clearly distinguish physical copies from catalog titles.

The replacement keeps the existing maroon layout, sidebar, navigation and Phase 3A styling. Staff choose Book Inventory, Checkouts, Returns, Borrow Requests, Overdue Loans, Reservations, Attendance or User Statistics. The report has date presets, custom ranges, status and text filters, summary totals, a readable table, CSV and generic printing. Most Borrowed Books remains available in the Checkouts view, ranked by the matching actual checkout records. Announcements and unrelated modules are unchanged.

Reports and Dashboard now use shared read-only query helpers. The legacy borrowing report endpoint uses the same transaction definitions. The old monthly statistics endpoint now provides circulation events/outstanding loans and explicitly stops claiming historical physical inventory. The Dashboard uses a current allocation chart instead. No new chart library or framework was installed.

## Calculation and date definitions

| Item | Definition |
| --- | --- |
| Catalog titles | Number of book records, not copies |
| Physical copies | Sum of `books.total_copies` |
| Borrowed copies | Sum of `books.borrowed_copies`, matching Manage Books |
| Copies in library | Physical copies minus borrowed copies; includes pickup holds |
| Held copies | Borrow requests with `awaiting_pickup` plus reservations with `ready`, using existing `BOOK_HOLDS_SQL` |
| Available for new requests | Per-title `max(0, total - borrowed - held)`, using `addBookAvailability` |
| Actual loan | Timestamped checkout from a borrow request or fulfilled reservation; origin-prefixed key prevents ID collisions and duplicate counts |
| Legacy approved loan | Existing Phase 2B convention: historical `approved` is a checked-out loan, using its preserved `approved_at` when `checked_out_at` is absent. New approvals are `awaiting_pickup` and do not count as loans |
| Checkouts | Filter by actual `borrowed_at`; show current outcome of those checkouts |
| Returns | Filter by actual `returned_at`, including loans checked out before the period |
| Borrow Requests | Filter by `requested_at` and show current outcomes; do not call every request a loan |
| Overdue | Current timestamped outstanding loans with due-date calendar day before today in Philippine time; due today is not overdue. Current snapshot, independent of date controls |
| Reservations | Current status filtered by explicitly selected creation date or scheduled pickup date; unscheduled rows excluded from pickup-date selection |
| Attendance | Filter by Time In date; total visits, distinct linked students, manual/unlinked visits, same-day registered repeats, and daily totals have separate meanings |
| Attendance repeats | Additional visits for the same linked account on the same Philippine date; classified before status/search filtering so an actual repeat remains a repeat when filtered |
| Occupancy | Existing Phase 5 count of open tracked visits across all dates, shown only when departure tracking is enabled; otherwise Not tracked |
| User Statistics | Grouped counts by role and current approval status, filtered by account registration date; no passwords, names or contact details in this report |
| Active reservations | Waiting plus Ready; fulfilled loans are counted as outstanding loans, not active waiting/pickup allocations |

Date presets are computed server-side in Asia/Manila: Today, Monday–Sunday this week, and this calendar month. Custom endpoints are inclusive calendar dates; SQL uses the next day's exclusive upper bound for attendance/accounts. Invalid dates, reversed ranges, invalid report/status/basis and oversized searches are rejected. Timestamps are shown directly as PHT without device-timezone conversion. Inventory and Overdue are explicitly current snapshots; date controls are disabled for them. Status outcomes and account approval states are current, not reconstructions of past states.

Reports return one consistent read transaction for records, current counts and settings. Equivalent Dashboard/Reports current summaries share the same helper. Counts can naturally differ if separate page loads occur around a real library action. Source data is never repaired merely to make totals agree. A warning flags missing checkout timestamps or any per-title borrowed-copy mismatch; mismatches on different titles cannot cancel out in aggregate. Missing checkout dates are excluded from checkout-event totals and flagged rather than invented.

## Export and printing

CSV is generated from the loaded filtered dataset and includes its report title, period/snapshot scope, search/status filters, generated PHT time, visible summary totals and the matching table. Attendance adds matching daily totals. The displayed and exported cells use shared column/value definitions. Values retain Unicode and multiline content; CSV quotes are doubled, all cells quoted, and leading formula characters (including whitespace-prefixed formulas) receive an apostrophe. UTF-8 BOM aids spreadsheet compatibility. Export/print are disabled while loading or after errors; stale responses cannot overwrite newer filter results.

The actual saved browser inventory CSV was verified read-only:

`/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase6a/inventory-export.csv`

It contains exactly two matching fixture titles: 7 physical copies, 2 borrowed, 2 held and 3 available for requests. Headers agree with the data. Unicode, a comma/quoted title, an embedded newline, zero counts and a formula-like author value passed validation. The user supplied this same file during testing. SHA-256: `672f0c26dda1e7568fea5d395a6b94e1eea7ef44f13191be311c35cc87e5e952`.

Actual attendance printing was opened in Chrome. The first preview had a trailing blank page caused by screen minimum-height rules. Print CSS now resets that height; a subsequent preview had one landscape page. The user completed the native Save as PDF dialog after automation timed out. The saved file retained Chrome's default filename:

`/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase6a/Reports _ BCIHS Library.pdf`

Its one 792×612 point landscape page was text-checked and rendered with Poppler, then visually inspected. It includes title, period, selected search/status, generated time, totals, all attendance columns, departure blanks/statuses and daily totals. Navigation, filter inputs and action buttons are absent. No signatures, official numbers or approval marks were invented. Chrome's own date/URL headers and footers depend on the user's print settings.

A later screen-table minimum-width fix resets those widths to zero for print. Its CSS and syntax passed tests, but a final new PDF after that last responsive-only adjustment could not be saved because browser access was disabled. The validated attendance PDF predates that screen adjustment. Large/multipage reports and every other category's final print output still need librarian visual acceptance.

## Database safety

No database schema change or migration was needed. Application reporting endpoints are read-only SELECT queries; PHP/MySQL connection timezones are session-local. Tests used clearly prefixed local fixtures and actual existing checkout/return actions, then removed only their own records. All original row fingerprints and settings matched after cleanup. Test auto-increment counters can advance; no original row was renumbered or replaced. No permanent fake reports were created.

Private backups were taken before fixture work, outside the repository and MAMP web root, with SQL and hash manifests mode 0600. Examples:

- Before browser fixtures: `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase6a-ui-20261027-181941-abace3.sql`
- Before final reporting suite: `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase6a-tests-20261027-183326-50cbca.sql`

All backup companions remain private. The browser fixture credentials/manifest were removed on cleanup. No private config, generated screenshots, PDF, CSV or database backup is included in the source manifest. The test helpers enforce the existing local-host/database guard before connecting.

## Automated verification

Final results, zero failed checks:

| Suite | Passed |
| --- | ---: |
| Phase 6A reporting API | 154 |
| Phase 6A JavaScript | 52 |
| Phase 2A Books API | 64 |
| Phase 2A Books JavaScript | 4 reported test groups |
| Phase 2B Borrowing API / JavaScript | 164 / 50 |
| Phase 4 Reservations API / JavaScript | 169 / 48 |
| Phase 5 Attendance API / JavaScript | 153 / 37 |
| PHP syntax | 70 files; private db-local.php excluded |
| Application JavaScript syntax | 30 files |
| Git working-tree and staged whitespace | Passed; index empty |

API coverage includes every category, authorization and stale-role denial, method validation, invalid filters, PHT midnight and preset boundaries, real pickup holds/checkouts/returns, reservation-origin records, no duplicate origin keys, current overdue boundaries, independent return-event dates, literal search, same-day attendance repeats and multi-day unique accounts, account status grouping, empty ranges, historical legacy dates, missing timestamps, per-title inventory inconsistencies and original-row preservation.

An intermediate Phase 4 run correctly refused to run while the Phase 6A browser fixture schedules existed. After browser cleanup it ran in full and passed 169 checks. This was fixture isolation, not a product failure. An early reporting test fixture used an invalid future pickup time/action name; the fixture was corrected to a valid weekday slot and the existing `fulfill` action. No reservation business rules were changed to accommodate tests. The final full run above is authoritative.

JavaScript tests exercise actual report column/value definitions, CSV encoding and metadata, Unicode/multiline/formula cases, zero/missing values, PHT display on a Honolulu device timezone, snapshot controls, enabled custom dates, escaped visitor text, failure states, export/print disabling, stale-response ordering and print CSS rules. These DOM harness checks are not claimed as actual browser/device tests.

Reproduce sequentially from the project root using `/Applications/MAMP/bin/php/php8.3.30/bin/php` for each PHP file and `node` for each JS file:

- `tests/phase6a-reports.php` and `.js`
- `tests/phase2a-books.php` and `.js`
- `tests/phase2b-borrowing.php` and `.js`
- `tests/phase4-reservations.php` and `.js`
- `tests/phase5-attendance.php` and `.js`

Run local fixture tests with library activity stopped. Do not run them on production. Phase 4/5 tests refuse incompatible existing schedules/open visits instead of changing genuine policy/data.

## Initial browser evidence and limitations (superseded by final verification below)

Chrome at `127.0.0.1:8888` used disposable staff/student/book/transaction fixtures, separate from the user's localhost sign-in. Actual checks completed:

- Dashboard current copy/hold/requestable/active reservation figures and recent records loaded with the fixture data.
- All eight Reports categories loaded with expected counts/statuses; checkouts 4, returns 2, requests 5, reservations 5, attendance 3 visits/1 linked student/1 repeat/1 manual visit, grouped user counts and 1 overdue fixture loan.
- Today, week and month displayed PHT ranges (27 October; 26 October–1 November; 1–31 October 2026). Empty custom dates displayed zero rows, and reversed dates displayed the server validation error with print/export disabled.
- Filtered inventory CSV was saved through the actual native browser dialog and independently validated.
- Actual attendance print preview and user-saved PDF were verified as described above.
- Desktop 1440×1000 presentation was visually checked and screenshots saved. Tablet 768×1000 was visually checked; the page fit its viewport and the wide table scrolled within its container. That inspection identified overly narrow name cells, so a minimum screen column width was added, with print overrides retained.

**Browser access was then disabled in Work settings.** The final requested browser call was rejected with “Local browser use is disabled in Work settings.” No alternative automation bypass was attempted. Therefore final visual retest of the tablet column-width adjustment, 390px mobile report/Dashboard layouts, final UI warning changes and a fresh print after the screen-width fix are pending. No mobile pass is claimed. Responsive CSS is implemented and syntax/harness checks passed, but that is not a substitute for mobile visual acceptance.

A temporary Chrome viewport override of 768×1000 and the disposable report tab may remain because the disabled tool also prevented viewport reset/tab closure. No native save dialog remained after the user saved the PDF. Fixture database rows and credentials were cleaned independently and verified. Re-enable browser access to reset the viewport/close that temporary tab and finish those visual checks.

Evidence directory (outside Git):

`/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase6a/`

Files: `dashboard-desktop.jpg`, `inventory-desktop.jpg`, `attendance-desktop.jpg`, `reports-tablet.jpg` (before column-width adjustment), `inventory-export.csv`, `Reports _ BCIHS Library.pdf`, and `attendance-print-preview.png`.

## Exact Phase 6A source manifest

8 modified files:

| File | Purpose |
| --- | --- |
| `reports.html` | Report selectors, filters, summaries, tables and generic print heading |
| `css/reports.css` | Responsive controls/tables and generic landscape print rules |
| `js/reports.js` | Report loading, filtering feedback, table rendering, CSV and print actions |
| `admin-dashboard.html` | Precise copy labels and current-scope/circulation information |
| `css/admin-dashboard.css` | Current-statistics text and discrepancy notice |
| `js/admin-dashboard.js` | Shared current summary, allocation chart, recent reservation ordering and PHT dates |
| `backend/borrowing-report-data.php` | Shared actual-loan definitions for existing endpoint consumers |
| `backend/book-monthly-stats.php` | Accurate circulation events/outstanding loans instead of fabricated inventory history |

8 new files:

| File | Purpose |
| --- | --- |
| `includes/reporting.php` | Shared read-only inventory/loan/current-summary/date/filter helpers |
| `backend/reports-data.php` | Validated staff-only reporting datasets and summaries |
| `backend/dashboard-data.php` | Consistent current Dashboard summary |
| `js/reporting.js` | Shared labels, columns, value formatting and CSV serialization |
| `tests/phase6a-reports.php` | Local API/action/consistency/cleanup verification |
| `tests/phase6a-reports.js` | UI and export logic checks |
| `tests/phase6a-browser-fixtures.php` | Private disposable browser setup and fingerprint-verified cleanup |
| `PHASE6A.md` | This implementation and verification report |

## Manual librarian acceptance guide

1. Sign in locally as admin/librarian, open Reports, and compare unfiltered inventory with Manage Books: physical, borrowed, held and requestable copies. Confirm Dashboard agrees for the same unchanged records.
2. Select Checkouts and Today, week, month and a custom range. Confirm only actual checkout events appear, including reservation loans, and inspect the legacy-date flag when applicable. Most Borrowed Books must rank matching checkout records only.
3. Use Borrow Requests to distinguish Pending, Approved / Awaiting Pickup, Checked Out, Returned and Rejected. In a disposable local test copy, approve a request: held increases, borrowed does not. Checkout converts the hold to an outstanding loan exactly once.
4. Return the book and verify borrowed/outstanding/overdue decrease. Use Returns filtered by the return date even if checkout occurred earlier. Verify due today is not overdue; a past-due outstanding loan is.
5. Review reservation creation and scheduled pickup date bases separately. Confirm Waiting and Ready are distinct from Fulfilled / Checked Out, Returned and Cancelled. Verify no Waiting reservation is counted as borrowed.
6. Compare Attendance with the Phase 5 page for a selected Time In date. Distinguish visits, unique linked students, repeats and manual/unlinked visits. Older untracked Time Out values must stay blank. Occupancy must say Not tracked when tracking is off and otherwise include open tracked visits across dates.
7. Check User Statistics grouped by role and current approval status. Confirm no private contact/password fields appear. Registration period is not an activity or historical-approval report.
8. Search/filter each report, export CSV, and compare exact rows and summary totals with the loaded screen. Confirm zero, blank, Unicode, commas, quotes and multiline values. Print only after the desired report has loaded.
9. Open Print Report, inspect all columns and multiple pages for a large selection, and optionally save a PDF. Confirm navigation/controls are hidden and dates/totals are shown. The layout is generic, pending the official template.
10. Repeat the 1440/768/390px checks after future changes, including table widths, sidebar collapse, horizontal scrolling, filters and printing. See final verification below for completed checks and remaining print/cleanup notes.
11. Do not edit genuine database rows to make reporting counts agree. Investigate flagged missing dates or per-title discrepancies through an agreed correction process.

## Remaining limitations and Phase 6B boundary

- The official librarian/school report template is unavailable. Phase 6B must adapt titles, required fields, paper size, grouping, headings and any authorized signature/form-number requirements once supplied. Nothing here is claimed school-approved.
- Historical physical stock cannot be reconstructed without a stock-change ledger. Inventory is current; no ledger/migration was added. Historical account approval and reservation status transitions likewise are not reconstructed.
- Missing legacy checkout dates require librarian review; they are flagged without fabricated event dates or automatic repairs.
- Current occupancy still depends on the existing Phase 5 departure policy and staff recording departures; attendance quotas, repeat-visit procedure and retention decisions remain unresolved as documented in PHASE5.md.
- Search and status filters apply to rows and associated summaries; current occupancy is explicitly global across dates and all visitors. “Unique registered students” counts linked accounts, not manual identities.
- Queries currently load transactions into PHP for straightforward shared filtering/counts. No pagination cap silently truncates exports; very large histories may require later database aggregation/pagination after real volume measurement.
- Final desktop/tablet/mobile layouts were rechecked after browser access resumed, as recorded below. Large multipage printing remains untested because existing data has only two loan records; no fixtures were recreated for this final review.
- QR, SMS, new borrowing/reservation/attendance policies and unrelated Users/Announcements development remain excluded.

## Implementation handoff Git preservation (before checkpoint)

Branch: `development`. HEAD: `acfd4dc` — Phase 5: Complete attendance and walk-in management. Prior checkpoints remain intact. No staging or commit occurred. Both Git whitespace checks pass.

Phase 6A: 16 unstaged files (8 modified, 8 new). These five unrelated photo changes remain byte-for-byte identical to the initial Phase 6A hashes and outside the manifest:

- modified `css/index.css`
- modified `css/login.css`
- modified `index.html`
- modified `login.html`
- untracked `images/school-entrance.png`

Total expected working-tree entries: 21 (12 modified, 9 untracked). No private credentials, database dumps, downloaded exports, print PDFs, screenshots or generated fixture manifests are staged. `includes/db-local.php` remains ignored and untracked. No production, main, push, merge or deploy action occurred. Stop here for user review; no next phase started.

## Final verification before checkpoint — 27 October 2026

Browser access resumed. Used the existing authenticated localhost staff session and genuine existing records, with no fixture creation or database writes. Confirmed branch development and expected pre-checkpoint HEAD acfd4dc.

- Reports and Dashboard were checked at actual CSS viewport widths 1440, 768 and 390, height 1000. In each case document width equaled viewport width, with no page-level horizontal overflow.
- Final report title columns measured 180px as intended; the wide inventory table remained within its horizontally scrollable container (1444px contents). Mobile controls, summary cards, action buttons and headings were visually readable. Both expanded mobile navigation and collapsed desktop/tablet navigation were exercised.
- Mobile report selection, custom date inputs, status selection, Show Report, invalid reversed-date feedback, disabled print/export on error, empty results and recovery to existing checkouts worked. Empty records displayed a clear message. Existing consistent data correctly kept the discrepancy warning hidden. Loading/error logic and discrepancy calculations retain their automated coverage; the populated warning was not visually forced during this read-only review; no inconsistent genuine data was manufactured to force a warning.
- Saved responsive evidence outside Git under the existing phase6a evidence folder, subfolder `final`: dashboard-1440.jpg, dashboard-768.jpg, dashboard-390.jpg, reports-1440.jpg, reports-768.jpg, reports-390.jpg, mobile-error.jpg, mobile-empty.jpg and mobile-checkouts.jpg.
- Opened a fresh Checkouts print action after final CSS changes, using the two existing loan records. The in-app native print surface was not inspectable by the browser adapter. The user confirmed “Print preview is readable; closed it”, establishing readable columns and no unnecessary blank page for this fresh existing-record report. No long multipage report was manufactured merely to test page count.
- Reset the in-app viewport override. The obsolete Chrome fixture tab 514800259 was listed at the staff login screen, but claim/close and its viewport reset returned “Debugger is not attached”. No unrelated user tabs were closed or changed. This stale Chrome attachment may require manual tab closure; it has no fixture data or live fixture account remaining.
- No application source change was needed during final visual verification. Reran the Phase 6A JavaScript suite: 52 checks passed. Previously recorded full API/regression results remain applicable. Exact manifest, ignored private configuration, unchanged photo hashes, prior checkpoints and both Git whitespace checks were reverified.

The fresh-print check passed by explicit user confirmation, alongside the agent-observed responsive checks. User authorization permits the exact 16-file Phase 6A checkpoint. Large multipage print testing remains a stated volume limitation, not an executed test.
