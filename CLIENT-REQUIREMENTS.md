# Final client requirements — verified local checkpoint

28 October 2026. Branch `development`, parent `84476be8e5a3aeebf04581d47f6a2cde8f12eda4`. Approved for a scoped local checkpoint; no push, merge, deployment or change to main.

## Already correct

Borrower login is now labeled School ID or Email. Staff login is Staff ID, Username or Email. Students use existing Student IDs; teachers use employee/faculty IDs; librarians use approved staff IDs; admins use admin usernames. Registered email remains a compatible identifier. The schema has no separate student/teacher username column. No arbitrary aliases or new username field were invented. Colliding identifiers still fail generically instead of selecting the first match or granting admin precedence. Normal borrower login/teacher borrowing continue through the same engine.

QR data and checkout APIs already authorize staff on the server, enforce CSRF for writes, preserve token ownership/state and reuse inventory transactions. Scanning itself never checks out a book. Attendance already supports configurable tracking, actual server exit timestamps, occupancy and duplicate-departure rejection. The existing eight report types, provider workflows and persistent consumed claim remain intact.

## Confirmed problems and targeted changes

1. The admin login endpoint delegated to the general handler without restricting borrower authentication. It now sets a server-owned staff-only flag; the handler rejects every non-admin/non-librarian before creating a staff login. A valid pickup parameter also requires staff even when posted directly to the general endpoint. Error redirects stay in the staff portal.
2. Borrower sessions received a staff API denial but no clear sign-in route. Both 401 and 403 now direct the scanner to the branded Staff Pickup Verification login. No verification details are rendered before authorization.
3. The official school statistical formats and formatted Excel export were absent. They are added separately below the existing Reports interface.
4. Manual attendance required a fictitious Section/N/A. Walk-in name and type are required; organization (50 characters) and purpose (25 characters) are optional. No ID, account or photograph is required.
5. Time Out controls existed but were difficult to find. A top-page explanation links to the renamed Time Out tracking and capacity settings. Inside Library, Awaiting departure and Tracking disabled for this visit replace ambiguous empty states.

## QR staff authentication and confidentiality

The server restricts authentication independently of JavaScript. Only an accepted librarian or an existing administrator can sign in for pickup verification. Student, teacher and visitor credentials are rejected there, while ordinary borrower sign-in stays functional. Existing approved staff sessions go straight to verification; invalid/unknown tokens return no borrower details to unauthorized users. Used requests stay ineligible; actual handover and Confirm Checkout remain required.

Only an exact 64-character lowercase hexadecimal token is preserved. The return destination is fixed to the internal verification page, never an arbitrary URL. Scanner/login HTML responses are no-store, old detail nodes are cleared before each recheck, and both pages suppress referrers; the existing API also sends no-referrer and no-store. QR URLs remain bearer secrets: protect server access logs and use HTTPS on deployment. Authorization remains on every protected API, not on the page shell.

## Report definitions and classification

- **Inventory:** present physical stock (`total_copies`) and current availability. It cannot reconstruct historical monthly holdings. The existing inventory report remains the inventory source.
- **Monthly circulation:** checkout transactions whose actual loan date falls in the month, including fulfilled reservation loans. One loan represents one copy. Returns count in their original checkout month; approval/ready holds alone do not count.
- **Current books borrowed:** outstanding loans/borrowed copies now. This is neither inventory stock nor all historical monthly checkouts.

Both new official outputs use monthly circulation. This is explicit on the screen; confirm the table's intended institutional metric with the librarian before official submission. The source is the existing `reportTransactions()` engine: legacy approved/returned loans without checked_out_at use approved_at only under the existing legacy rule. Included legacy records and undated exclusions are reported. No dates or historical transactions are fabricated. Current catalog classifications are used; the database does not retain past classification snapshots.

All thirteen client category labels are preserved exactly. TEXTBOOK/TEXTBOOKS, SIP/WIP/SIP & WIP and RIZ/RIZALIANA/RIZ - RIZALIANA category values take precedence over DDC. Otherwise a valid three-digit DDC, optionally decimal, maps to its hundred range. Circulation without a valid mapping appears as an extra Unmapped row with a warning, so totals never silently lose loans. Librarian confirmation is needed for other local catalog labels.

The table shows the selected ending month. The chart compares the selected 1–12 months ending in that month, chronologically across years. School year is an editable consecutive YYYY-YYYY label; it is not used to invent historical records or enforce a school calendar. Default is the current calendar year pair and must be confirmed by staff.

Ranks use descending competition ranking: 1, 1, 3 for tied leaders. Zero quantities have no rank. Quantities, ties, totals and all comparison series come from one shared calculation for HTML/CSV/Excel. No sample 147 or example rankings are hardcoded.

## Excel, CSV, HTML and signatories

Reports → Official School Statistical Reports → choose ending month/comparison length/school year → Show official reports.

- **Formatted Excel (.xlsx):** two worksheets, merged centered headers, black table grid, gray header/total, highlighted SIP & WIP, wrapped category labels, quantities/ranks and signatories. A native clustered vertical column chart includes month legends and classification codes (full names remain in the table); its source data remain visible below the chart, outside its print area. Count ticks are whole numbers. Classification codes keep horizontal-axis labels readable. The chart heading uses the selected month followed by (BOOKS). Print areas/scaling are supplied separately per sheet.
- **Raw circulation CSV:** classifications and monthly quantities only, with formula-prefix protection and quoted fields. It does not promise Excel formatting/charts.
- **Print official table and chart:** separate HTML pages with named portrait-table / landscape-chart page CSS and signatories. The old generic Print Report hides the new controls/outputs; the new print action hides the old generic report. Chrome preview now shows exactly two pages for the populated fixture: A4 portrait table and A4 landscape chart. No physical print success is claimed.
- **Signatories:** load a report, open Configure report signatories, enter verified current names/titles and Save. Example names are deliberately blank. Only authenticated staff with CSRF can save. Values persist in Git-ignored `includes/report-local.json`, protected by includes/.htaccess, file locking and POSIX 0600 permissions. Windows ACLs must be verified at turnover. Invalid submissions are validated before file creation/writing. Existing exports are snapshots.

All user-supplied workbook strings use explicit text cell types; Excel cannot interpret them as formulas. HTML uses literal textContent/SVG text nodes, not interpolated signatory markup.

### Dependency and maintenance

PhpSpreadsheet 5.10.0 plus five transitive packages are recorded in composer.lock; vendor is ignored. It is a maintained PHP spreadsheet writer, avoiding hand-written OOXML/ZIP and native chart relationships. Official documentation: https://phpspreadsheet.readthedocs.io/en/latest/ and https://phpspreadsheet.readthedocs.io/en/stable/topics/reading-and-writing-to-file/ . No runtime Node/Python server or new application framework is required.

On MAMP/XAMPP deployment install Composer, use PHP 8.3 and run `composer install --no-dev --prefer-dist` and `composer check-platform-reqs` before serving exports. PHP ZIP, XML/DOM/XMLReader/XMLWriter/SimpleXML, mbstring, GD, fileinfo, iconv, filter, ctype and zlib are required by the locked packages. All requirements passed on MAMP PHP 8.3.30. Dependency installation reported no security advisories. Composer validation passed with an advisory that this existing application has no declared project license; confirm ownership/licensing at turnover. Keep composer.lock and package licenses; update through a reviewed dependency change. Apache rejects direct composer files/vendor access (403 checked on MAMP); verify equivalent rules on the target host.

## Visitor and teacher workflows

Attendance → Visitor type → Add Walk-in Visitor → name → External visitor / BCIHS student without account / Teacher-faculty without account / Other visitor → optional organization/purpose → Confirm Time In. BCIHS visitors need not enter the school name. The app reuses existing year_level (type), year_section_snapshot (organization) and section (brief purpose) fields for new manual visits; no migration or historical rewrite. Legacy rows remain readable. Attendance display/CSV and the existing attendance report retain all three details. Longer metadata would require a separately reviewed schema extension.

Registered students and teachers are selected by actual account/ID, preserving linked borrowing/history. A manual visit does not create borrowing rights. Visitor portal registration is an optional staff-approved portal account, separate from attendance; its form now states that walk-ins need no account and visitor accounts grant no borrowing permission. Legacy registration URLs still redirect to that form.

Teacher registration, approval, authentication, requests, checkout, return and reporting are preserved and rerun in the isolated suite. No teacher is promoted to staff.

## Time Out instructions

1. Authorized librarian opens Attendance and follows How to enable Time Out.
2. Expand Time Out tracking and capacity settings.
3. Enable **Enable Time Out Tracking (new visits only)** and Save attendance settings, after confirming library policy. This work did not enable it in the active database.
4. Record a new arrival. Its status is Inside Library with Awaiting departure and Confirm Time Out.
5. Once the person leaves, select Confirm Time Out and confirm in the centered dialog. The server records actual exit time, marks Completed and reduces occupancy.
6. Duplicate departures are rejected. Older untracked visits say Tracking disabled for this visit; they are not retroactively treated as occupants. Close all open tracked visits before disabling tracking.

## Verification and limits

- Final isolated actual HTTP/database run: **194 passed**, including nine role/endpoint denial combinations, no detail disclosure, fixed safe return URLs, staff admin/librarian login, invalid/used QR, staff-only reports, CSRF-protected/invalid signatory updates, native chart download, optional-field walk-in, Time Out and occupancy, plus previous Phase 8 workflows.
- Database-free PHP: **333 existing mock/security checks + 38 calculation/Excel checks passed**. New tests cover empty periods, tied leaders (1, 1, 3), unmapped categories, special mappings, year boundaries, twelve months, totals, formula injection, chart relationships/axes and well-formed workbook XML. A populated official total also matches an independent borrowing-plus-reservation SQL count.
- JavaScript: **637 named checks + four Books groups passed**; QR and Attendance expectations updated for required staff routing/optional fields. Syntax: 104 first-party PHP and 43 first-party application/test JavaScript files checked (dependency directories excluded); changed files rechecked after corrections.
- Browser: 185 page/view/width combinations inspected across staff, guest, student and teacher surfaces at exact 1440, 1024, 768, 390 and 320 CSS-pixel widths. Measured states showed no unintended document overflow, wrapped badges or invisible action SVGs. Users View, Reject, cancelled approval and confirmed approval were exercised on owned isolated fixtures. Librarian/self-delete restrictions and mobile sidebar SMS Logs/Logout visibility were checked. This is coverage of the documented states, not every possible data combination.
- Excel: the corrected workbook opened with two sheets and no repair prompt. Vertical clustered columns, chronological month legends, classification codes and whole-count axes rendered. Native Excel previews show one A4 portrait table page and one A4 landscape chart page, including signatures. Both were cancelled.
- Chrome print: supplied screenshots demonstrated a leaked skip link and an unnecessary blank third page. Print-only CSS hides the skip link and retains the final named page context. The print handler temporarily moves the existing output outside dashboard wrappers, then restores it after printing/cancellation (including a finally block). Two fresh Chrome native previews showed exactly two pages with readable table/chart, signatures, gray header/total and highlighted SIP & WIP. Cancellation restored the Reports page; mock print-lifecycle checks covered cancellation, afterprint, exceptions and repeat invocation. No physical print or new phone scan is claimed.
- No active-database writing regressions ran. Old Reservations/Attendance isolation limitations are not relabeled as active-database passes.

## Preservation and acceptance questions

All ten active tables match full-row hashes/counts from the original audit and acceptance baseline. Student #364, log #265, their relationships, private SMS config, backups and consumed claim are intact. Simulation/live-disabled config and claim hashes are unchanged. No live provider call, SMS, retry/fallback or credit use; eight remains the last user-confirmed balance, not a newly queried figure. Only owned disposable databases/files were written and removed. Seven unrelated paths remain byte-identical. In login.html only the newly authorized identifier label/placeholder changed; reversing those two replacements reproduces its original pre-review hash, including its untouched photo cache-version customization. The local checkpoint excludes the unrelated customizations.

Still needed: the two actual report reference images (only the detailed text attachment was available); human official-reference comparison and physical-printer acceptance; confirmation that official table quantities mean circulation; authoritative category exceptions and school year; verified signatories;  visitor metadata/retention policy; attendance policy approval; target XAMPP/hosting, HTTPS/ACL/dependency verification. These are not represented as established school rules.

Ready for guided internal client demonstration of the new outputs and workflows. Final capstone acceptance remains conditional on the above reference/metric/policy confirmations and target-host verification. This is a local development checkpoint, not a production-readiness certification.

## Exact implementation manifest (38 paths)

```text
.gitignore
.htaccess
admin-login.html
attendance.html
backend/admin-login-process.php
backend/attendance-add.php
backend/login-process.php
backend/reports-data.php
css/library-ui.css
css/manage-users.css
js/admin-login.js
js/admin-shell.js
js/attendance.js
js/login.js
js/manage-announcements.js
js/manage-users.js
js/pickup-verification.js
js/reports.js
login.html
manage-books.html
reports.html
scripts/phase8-isolated-qa.php
select-role.html
tests/phase3b-pickup-qr.js
tests/phase5-attendance.js
tests/phase7a-users-announcements.js
tests/phase8-workflows.php
transactions.html
CLIENT-REQUIREMENTS.md
backend/statistical-report.php
composer.json
composer.lock
css/statistical-report.css
includes/statistical-report.php
includes/statistical-xlsx.php
js/statistical-report.js
tests/client-statistical.php
tests/client-workflows.php
```

## Preserved unrelated paths

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


## Focused final visual/login acceptance review

Only the text file was supplied in the new attachment directory; no image files were present. The comparison below follows its explicit feature descriptions. Pixel-level matching against Image A/B is not established.

| Reference feature | Final implementation and evidence |
|---|---|
| A: centered report/month/school-year headings | Merged/centered Excel headings and centered HTML headings; selected month and editable consecutive school year |
| A: classification/quantity/rank, thirteen categories | Exact full official names in the table; data-driven quantities and competition ranks; optional unmapped row preserves reconciliation |
| A: gray header/TOTAL, black grid, highlighted SIP & WIP | Present in Excel and HTML; sample total 147 never used |
| A: signature areas | Staff-configured names/titles, blank by default; Excel rows wrap; HTML signature group avoids page splitting |
| B: grouped vertical bars | Corrected from horizontal bars in native Excel and HTML SVG; no metric changes |
| B: selected month + (BOOKS), school year, title | Present; chart no longer replaces selected-month heading with the whole comparison range |
| B: classification on horizontal axis, month legend | Official short codes on axis, full names in table; chronologically ordered selected-month series; whole-number count axis |
| Print dimensions and whitespace | Table corrected to horizontally centered A4 portrait, chart centered A4 landscape. Native Excel confirms one page per sheet for the tested populated/unmapped fixture; no extra blank page shown. HTML named page CSS updated accordingly. |

### Metric decision remains explicit

The first table currently counts **circulated/checked-out copies during the selected ending month**, not current holdings. The chart uses the same transaction engine for multiple months, but this does not establish that the school defines the two official reports identically. The table is explicitly labeled monthly circulation. Current inventory remains available as a separate original report. Await librarian confirmation before changing the official table's institutional definition. No quantities, dates, transactions or historical holdings were invented.

### Final login truthfulness

Public label: **School ID or Email**. Supported user identifiers are existing Student ID, faculty/employee ID, or an email already stored on the approved account. Staff label: **Staff ID, Username or Email**, preserving administrator usernames and approved librarian IDs/emails. Collision denial and staff-only pickup authentication remain unchanged. Additional isolated tests authenticate both a student and a teacher by their stored synthetic email. There is no new patron username identity or migration.

### Print acceptance and remaining decisions

Chrome native preview now passes the ordinary populated two-page case. The user-supplied images are screenshots of the application's print preview, rather than the two official institutional reference images; exact official-image matching remains unestablished. Browser headers/footers may be turned off manually for clean institutional output. No printer settings were permanently changed. Very long signatory names, unusually many unmapped categories and target printer drivers still require final librarian/printer review.

### Global component corrections and cross-module QA

The additional global UI requirement was included in the authorized scope. Table overflow wrapping caused role badges to split; shared badge rules now keep labels intact while tables scroll internally. Neutral action backgrounds exposed hardcoded white SVG strokes in Users and Announcements; icons now inherit the button color. Users actions have visible View/Delete labels and a consistent action group. Reject is shown only for pending users; accepted accounts already rejected that operation on the server, and the isolated test verifies 409 with status unchanged. Rejected accounts can still be approved through the existing workflow. Sidebar labels are consistently Manage Books, Borrow Requests and Transactions; sidebar transitions no longer transiently clip content during resizing.

The original 31-path report included a redundant no-referrer tag in pickup-verification.html, which already had that protection. Removing the redundant edit and adding eight justified UI/test paths gives 38 final paths: css/library-ui.css, css/manage-users.css, js/admin-shell.js, js/manage-announcements.js, js/manage-users.js, manage-books.html, transactions.html and tests/phase7a-users-announcements.js. No new backend permissions or business rules were introduced by these UI corrections.

### Commit scope

Only the 38 listed implementation/test/documentation paths are approved. Stage login.html selectively: include the identifier wording, exclude its preexisting photo cache-version hunk. Preserve all eight listed unrelated customizations, private settings, uploads and test evidence. Active data-writing regressions are forbidden; only the isolated suite writes fixtures. Simulation remains enabled and live SMS disabled. Main remains unchanged.
