# Phase 2A — Manage Books

Implemented locally on `development`, starting from Phase 1 commit `8efdc60`.
No schema migration, private configuration change, deployment, commit, or push
is part of this phase.

## Findings and behavior

The local catalog was empty when inspected, so no historical categories were
available to copy. The category dropdown offers broad school-library choices,
includes all stored category/legacy genre values, and allows a new name. These
choices never assign a DDC. Search, category, and availability filters combine;
Clear filters restores the full result set. Pagination offers 10, 25, or 50 titles.
CSV exports all matching titles, including matches on other pages.

The old save handler reconstructed Call Number from Classification + Cutter +
year, and copied Category over Genre. The updated rules are:

- **Classification Number** stores `books.ddc` only. New/changed values use three
  digits with an optional decimal, such as `001.3` or `500`. The librarian must
  choose the actual classification; blank is allowed.
- **Call Number** stores the complete shelving identifier exactly as entered
  after trimming outer whitespace. Prefixes, Cutter information, and years belong
  here when the library uses them. It is not rebuilt when another field changes.
- `books.cutter_number` remains in the database and API for compatibility. It is
  absent from the Manage Books form, table, and CSV, and is never cleared on edit.
  If an old record has Cutter data but no Call Number, the UI prefills its legacy
  shelving parts for the librarian to review. It does not write changes on load.
- The existing stored Call Number takes priority in Manage Books, the homepage,
  and the QR catalog destination. A DDC or publication year alone is not treated
  as a complete Call Number.
- Omitted edit fields retain their values. A distinct historical Genre remains
  intact; display uses Category first, then Genre. Blank text explicitly clears
  an optional field. Unchanged legacy DDC, year, or ISBN formats remain editable;
  changing them requires the current validation rules.

Title and author are required. Text lengths match the schema. New/changed ISBNs
must be valid ISBN-10/ISBN-13 values with correct check digits. Duplicate ISBNs
are compared without spaces/hyphens; accession duplicates use the database's
existing case-insensitive collation. Existing duplicate identifiers can remain
unchanged while other metadata is corrected. Duplicate ISBNs should normally
be handled by updating the existing title's copy count or checking its edition.

## Inventory and deletion safety

Total copies must be a nonnegative whole number within the existing unsigned
integer range. Borrowed copies are transaction-managed, and available copies
are calculated as total minus borrowed. A book row is locked while editing so
its current borrowed count is used; total cannot be reduced below that count.

The schema's foreign keys cascade deletion to borrow requests and reservations.
Deleting a book is therefore blocked with HTTP `409` if **any** related history
exists, including returned loans and cancelled reservations, or if borrowed
copies are nonzero. Only unused catalog rows can be permanently deleted. No
archive flag was added. Setting total to zero does not mean a title is archived.

Two small related safeguards were necessary: approval and reservation fulfillment
now increment stock only when `borrowed_copies < total_copies` **at the database
update**, and only process an eligible request/reservation once. This prevents
an old availability value from overriding a concurrent total-copy edit. Failed
status checks roll the stock increment back. Existing screens and workflows
remain unchanged; this is not a physical-copy borrowing implementation.

## Security techniques used

- Shared book helpers check the actual administrator or approved librarian in
  the database before changes. Approved patrons retain read access because the
  same catalog API serves Browse Books, reports, and the dashboard.
- The catalog response supplies a random token stored in the PHP session. Save
  and Delete send it in `X-CSRF-Token`; the server compares it with that session's
  token. This prevents another website from submitting a book change using the
  librarian's cookies. Reload Manage Books after a session/token error.
- SQL values use prepared statements. Dynamically assembled column names come
  only from the server's fixed field list, never from user-supplied SQL.
- Transactions group related writes; `FOR UPDATE` holds a row lock until commit
  or rollback. This lets the server validate current inventory rather than a
  stale browser value. Errors roll back changes and remove a newly uploaded file.
- Cover uploads retain the 5 MB JPG/PNG/WebP limit and check actual file content.
  Raw database exceptions are not sent to users. Book endpoints no longer run
  automatic `ALTER TABLE` statements.
- CSV quotes/escapes every cell and prefixes formula-like values with an
  apostrophe. When importing CSV into a spreadsheet, treat ISBN/DDC/accession
  columns as text to preserve leading zeros.

## QR behavior

QR opens `browse-books.html?book=<id>` on the current site's origin and path.
The destination shows the selected catalog title. Scanning does not approve,
reserve, or check out anything. The existing external QR image service remains;
it needs internet access. A localhost QR works on the hosting computer only.
To test from a phone, open the site using its reachable local network URL first,
then generate the QR there. Do not use a production URL for local tests.

## Files changed

| File | Change |
| --- | --- |
| `manage-books.html` | Updated fields, filters, compact columns, pagination, modal help |
| `css/manage-books.css` | Grouped cells, horizontal actions, responsive form/table styles |
| `js/manage-books.js` | Forms, metadata mapping, filters, pagination, CSV, QR and error handling |
| `includes/book-management.php` | Shared access, CSRF, input and cover-cleanup helpers |
| `backend/books-data.php` | Compatible catalog response plus session token; no automatic DDL |
| `backend/book-save.php` | Validated create/edit, metadata preservation, locked inventory checks |
| `backend/book-delete.php` | Locked history check before deletion; safe error responses |
| `backend/public-books-data.php` | Category fallback support, safe errors, no automatic DDL |
| `backend/borrow-request-action.php` | Current-stock/eligible-status guard for approval; safe SQL errors |
| `backend/reservation-action.php` | Current-stock/eligible-status guard for fulfillment; safe SQL errors |
| `js/browse-books.js`, `js/index.js` | Honor stored Call Number and Category in catalog displays |
| `tests/phase2a-books.php` | Local HTTP/database integration tests and fixture cleanup |
| `tests/phase2a-books.js` | Dependency-free checks of the actual Manage Books JavaScript |
| `PHASE2A.md` | This implementation and verification guide |

## Verification

Run from the project directory with MAMP servers running:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase2a-books.php
node tests/phase2a-books.js
```

The PHP test accepts a different localhost base URL as its first argument for
other Apache ports or XAMPP. It refuses remote databases/URLs. Temporary accounts,
books, related transactions, photos, and cookie files are removed afterward;
existing records are not reset or deleted. No SMS is sent. Its deterministic
concurrency probes use MySQL 8 `performance_schema.data_locks`; they report SKIP
on servers without access to that instrumentation. Node is only an optional
local test runner; the website has no Node runtime or package dependency.

Executed on local MAMP/MySQL 8:

- **PASS:** 63 API/integration checks, including valid creation, metadata edits,
  identifier validation, authentication/approval, CSRF, uploads, inventory limits,
  history-safe deletion, repeated processing, and both concurrent stock edits.
- **PASS:** JavaScript checks for filtering, pagination, exact/legacy Call Number
  handling, formula-safe CSV, cross-page export, and the local QR URL.
- **PASS:** Browser Add/Edit persistence, existing values, custom category,
  combined filters/search, clear filters, pagination, page-size changes, and an
  unchanged legacy Genre longer than the Category column limit.
- **PASS:** Actual filtered CSV download and field/count verification; external
  QR rendering and selected-book destination with unchanged inventory. A browser
  CSV export included all 14 matches while only 10 were visible on the page.
- **PASS:** Desktop layout at 1280 × 720 and mobile layout at 390 × 844. Mobile
  tables scroll horizontally; the form stacks vertically. Existing navigation
  and temporary viewport settings were restored after testing.
- The first concurrency probe's wait detector used a transaction-state view that
  did not reflect the waiting row. It was corrected to inspect actual data locks;
  the complete 63-check run then passed.

- **PASS:** syntax checks for all 49 PHP files and 28 JavaScript files; `git diff --check`.
- **PASS:** all API fixtures and 14 browser fixture titles were removed. The
  catalog returned to its original empty state. Existing administrator data and
  the private configuration were preserved. No SMS was sent.
Windows/XAMPP, physical phone scanning, printing, offline QR generation, very
large catalogs, and the complete borrowing/reservation workflows were not tested.

## Manual review before acceptance

1. Reload Manage Books and add a clearly marked local title with real catalog
   metadata. Check both an existing category and Add a new category.
2. Edit it, reopen it, and verify DDC, the full Call Number, publication details,
   cover, and total/borrowed/available counts. Review any legacy-format notice.
3. Combine search/category/availability, change pages, and clear the filters.
4. Export a filtered CSV and inspect the code/identifier columns as text.
5. Open QR, check its URL, and try a phone only after configuring a reachable
   local URL. Confirm it opens details without checking out a copy.
6. Confirm deletion is refused for a historical book. Delete only your unused,
   clearly marked local test title after reviewing its confirmation.

Known limits: pagination/filtering currently load the whole catalog in the
browser; identifiers are checked by these APIs, without new database unique
indexes; historical titles cannot yet be archived. Direct SQL imports must be
reviewed for duplicates. The external QR service remains an internet dependency.
The older password-reset mismatch from Phase 1 is outside this phase.

## Phase 2B recommendations — not implemented

Design a separate physical-copies table linked to catalog titles, with one
unique accession identifier and status per copy. Migrate existing aggregate
counts only after an inventory audit and a reviewed, reversible migration.
Link loan records to those copy IDs, add an auditable retirement/archive policy,
and review return/cancellation concurrency across the remaining handlers.

Use copy-specific QR links that open a confirmation screen. Require staff
authorization and an explicit checkout/return action; a scan alone must never
change inventory. Decide on stable hosting, label printing, lost/damaged-copy
handling, and database uniqueness rules before implementing that workflow.
