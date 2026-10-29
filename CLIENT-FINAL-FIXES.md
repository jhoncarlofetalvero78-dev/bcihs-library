# Final client website refinements — October 29, 2026

Status: implemented and awaiting final review. No commit, push, merge, deployment or main change. Browser access is disabled in Work settings, so responsive screenshots and native print-preview acceptance remain pending. No visual pass is claimed.

## Attendance: arrivals only

The page records a registered student/teacher arrival or a manual walk-in. Walk-ins need no account, School ID or photograph; organization and purpose remain optional. Staff authentication and CSRF checks remain required. The three summary cards show visits, unique linked people, and walk-in visits for the selected date. Date/name filters, CSV export and optional daily visit limit remain available.

Removed from the active page/API/reporting presentation: Time Out buttons/dialogs, departure tracking checkbox, occupancy setting, currently-inside/completed filters, occupancy card/counts, departure timestamps, durations and departure status badges. The dashboard and generic attendance reports no longer infer current occupancy from historical open visits. CSV/print tables show arrival details, with repeat-visit information retained in generic reports.

New visits explicitly use tracks_departure=0 and no time_out value. Existing stored tracking/occupancy settings cannot reactivate departure behavior. The old authenticated/CSRF-protected departure endpoint returns HTTP 410 and writes nothing; stale settings clients attempting departure/occupancy changes receive HTTP 422. Only the daily limit can be saved. Historical columns, settings values and timestamps are retained in storage; no migration, drop, reset or historical update is used.

Submission keys still make repeated identical form submissions idempotent. A fresh same-day submission for the same registered person requires explicit confirmation that it is a separate return visit. Different manual visitors are not conflated by name. The policy-row lock still serializes arrivals and daily-limit changes; concurrent arrivals cannot take the last daily slot twice. No automatic departure/timeout process is introduced.

## Homepage: latest publications

Updated by the subsequent owner UI request: the homepage requests announcements-data.php?latest=2 and displays up to two cards, below a separate Our Services section. The three service cards are wider and centered. The latest=1 API view remains supported for compatibility. The existing data model publishes a row when staff saves it; removal deletes that row. There is no draft/inactive status column or separate hidden publication store to invent or migrate. Selection uses created_at DESC, id DESC, preserving existing publication semantics and deterministic same-time ordering. The unqualified collection endpoint and full Announcements page retain all published rows.

The card retains its title/category, image, date and full safely escaped body. Its preview is clamped to three lines; Read more/Show less is a keyboard-operable button with aria-expanded, and existing card-click expansion remains available. Images fit within a bounded area without distorting the source. Long titles wrap; View All remains. No-publication and load-error states remain explicit. No carousel/timer is added.

## Report period labels

| Previous label | New label | Actual meaning |
| --- | --- | --- |
| Ending / table month | Report month | Quantities/ranks in the table are for this one month; the comparison chart also ends with this month. |
| Comparison months | Months in comparison chart | Number of consecutive chart months, including the report month and the immediately preceding months; 1–12 allowed. |
| School year | School year | Heading label only; it does not filter or change the circulation date range. Confirm it with the librarian. |

Each field has an associated helper. The live preview explains the table month and chronological chart span, including year boundaries. For example, January 2026 plus three chart months means a January table and November 2025–January 2026 chart. One chart month means the selected month only. Invalid month/count/school-year combinations are rejected. Changing selections hides stale output and disables exports until Show official reports generates the new result; a late old response cannot restore obsolete output.

Calculations remain monthly circulation: checkout transactions, including reservation loans and returns in their checkout month, with the existing documented legacy fallback. No circulation-to-inventory reinterpretation, hardcoded quantities/ranks, chart layout change, signatory change or print-layout redesign was made. Existing Excel/CSV/print generation uses the generated report's parameters and data.

## Verification

- 814 owned, schema-only isolated connected workflow checks passed through the current attendance test entry point. Covers arrivals, replay/same-day repeats, walk-ins/teachers, role/CSRF checks, daily quota concurrency, retirement of departure writes, historical timestamp preservation, announcement latest/full/empty ordering, report/Excel API generation, login/registration, Manage Books, borrowing, reservations, QR authorization and all seven SMS mock workflows. The runner removes only its own disposable database/copy; active credentials/records are not imported or changed.
- 31 Attendance JavaScript checks; 52 generic Reports JavaScript checks; 19 official period-control checks; 197 users/announcements/XSS checks; 162 UI/security checks; 53 QR checks; 52 borrowing checks; 49 reservation checks; 45 SMS Logs checks; 40 SMS Settings checks passed. Manage Books JavaScript regression groups also passed.
- 38 database-free calculation/Excel checks passed, including chronological comparisons, quantities/ranks, formula safety and native chart XML. The generated fixture workbook is outside Git at /Users/macbookprom116512/.codex/visualizations/2026/10/29/client-final/statistical-calculation-check.xlsx. XML/workbook verification is not an actual Excel visual-render pass.
- 94 shared PHP SMS, 105 UniSMS adapter, 120 one-attempt guard, 60 production-policy, safe-diagnostics and 28 PHP security checks passed. Transport responses are mocked; no real provider request or SMS credits used.
- Nine read-only Apache resource/security checks passed. Changed PHP/JavaScript syntax and git diff --check passed.

The old Phase 5 PHP entry point now invokes the owned isolated suite, avoiding its obsolete active-database departure fixtures. Current attendance/reporting assertions were updated to the arrival-only contract rather than marking old departure expectations as application failures.

Browser tooling explicitly reports "Local browser use is disabled in Work settings." No alternate automation was used to bypass it. Screenshots at 1440/768/390 and a smaller practical width, announcement image/card inspection, keyboard navigation, and fresh native print preview must be reviewed once browser access is enabled. Responsive CSS and DOM behavior were reviewed/tested, but rendered overflow and pagination are not claimed as visually verified.

## Data, SMS and Git preservation

All 12 active table fingerprints match the starting snapshot, including historical attendance timestamps, SMS logs/audits and legitimate users. The private SMS configuration and both consumed account:364/account:368 claims match byte-for-byte. UniSMS remains Live / production authorized / administrator permission ON; Semaphore is not selected or configured. No genuine business action or provider request was submitted for testing. The isolated server uses only synthetic configuration and mock transports.

Uploads, login photo customizations, css/phase9.css refinements and other existing customizations are preserved. Only approved announcement CSS was added to css/index.css; removing those additions in memory reproduces the initial file hash. Reversing only the asset-version updates in index.html also reproduces its initial hash, proving the earlier photo/HTML customizations remain. Active database data/schema and private provider settings require no migration.

development remains at 5b77d8eae03dfa73c39e9837aee5b22aa08c5a79; main remains 1465cf93735b3e5bff7c1b80e3eec004e16b249c. The feature/phase11-collection-import-paused branch and bcihs-phase11-deferred-v1 tag are retained. Nothing is staged. Existing uncommitted SMS integration/hardening and original photo/upload changes remain uncommitted; they are not part of this task's manifest.

## Exact task manifest — 31 files

1. admin-dashboard.html — updated dashboard asset version.
2. attendance.html — arrival-only controls/columns, three summaries and daily policy.
3. backend/announcements-data.php — optional bounded latest published collection.
4. backend/attendance-add.php — arrival-only insert, repeat/idempotency/daily controls preserved.
5. backend/attendance-data.php — arrival/date/search projection.
6. backend/attendance-settings.php — daily limit only; stale departure settings rejected.
7. backend/attendance-time-out.php — authenticated retired endpoint, no writes.
8. backend/reports-data.php — arrival-only attendance reporting.
9. css/attendance.css — three summaries and simpler table; obsolete departure styles removed.
10. css/index.css — bounded latest announcement presentation, preserving prior customizations.
11. css/reports.css — accessible period-helper and preview spacing.
12. includes/attendance.php — active daily-policy projection; occupancy helper retired.
13. includes/reporting.php — remove inferred occupancy from current summary.
14. includes/statistical-report.php — validation wording matches new labels; calculations unchanged.
15. index.html — refreshed asset versions only, preserving existing photo customization.
16. js/admin-dashboard.js — remove occupancy wording.
17. js/attendance.js — arrival-only presentation/actions/export.
18. js/index.js — two latest notices and accessible preview expansion.
19. js/reporting.js — arrival-only attendance columns/labels.
20. js/reports.js — remove departure status options for attendance reports.
21. js/statistical-report.js — period preview, validation and stale-selection protection.
22. reports.html — clear labels/helpers and refreshed assets.
23. tests/client-workflows.php — isolated contract/regression checks.
24. tests/phase5-attendance.js — arrival-only UI/export tests.
25. tests/phase5-attendance.php — safe isolated regression entry point.
26. tests/phase6a-reports.js — arrival-only presentation expectations.
27. tests/phase6a-reports.php — updated legacy reporting assertions; not run against active data.
28. tests/phase7a-users-announcements.js — two-card, expansion and empty-state regressions.
29. tests/phase8-workflows.php — current attendance contract and retired endpoint expectations.
30. tests/client-report-periods.js — new focused period-control tests.
31. CLIENT-FINAL-FIXES.md — implementation, evidence, manifest and pending visual review.

## Final client review

Attendance: select a registered person or enter a walk-in, then Record Attendance. Review the selected-date records or export CSV. Keep or adjust only the optional daily limit. Confirm separate same-day registered visits explicitly.

Homepage: confirm up to two latest announcements, use Read more/Show less, and View All for the full collection. Reports: choose Report month and Months in comparison chart, read the preview, then Show official reports before export/print. Confirm the monthly-circulation definition and school-year heading with the librarian.

Remaining acceptance work is visual/responsive/native-print review and client sign-off. Semaphore school credentials, funded credits, approved sender and separately authorized live acceptance remain a future provider-turnover task. No Semaphore activation is included here.

## Follow-up spacing and layout refinement (2026-10-29)

The borrowing-policy form now uses a compact days column, flexible reason column and aligned Save action. Tablet layouts move the action onto its own row; mobile layouts stack the fields. Existing default-policy values and save behavior are unchanged. Homepage services and announcements use separate semantic sections. Announcements use two columns on desktop and one on mobile, retaining Read more and View All. No notices are duplicated when fewer than two exist.

Files touched for this follow-up: index.html, css/index.css, js/index.js, backend/announcements-data.php, borrow-requests.html, css/checkout-due.css, tests/phase7a-users-announcements.js, tests/client-workflows.php and this document. Browser verification remains pending because local browser access is disabled in Work settings.

Follow-up verification: 197 announcement/user JavaScript checks, 17 checkout due-date JavaScript checks and 896 isolated connected workflow checks passed. PHP/JavaScript syntax and Git whitespace checks passed. The two-latest API ordering and invalid-limit rejection are covered. Active database row hashes, private SMS configuration, consumed claims, uploads and unrelated photo/login files matched the pre-change snapshot; only the requested homepage HTML/CSS differed in that preservation snapshot. Zero live provider calls.
