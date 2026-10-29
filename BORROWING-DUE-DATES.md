# Flexible borrowing due dates — October 29, 2026

Status: implemented for review; no commit or deployment. The actual local default is still 14 calendar days. No active loan, SMS setting, credential or historical record was changed.

## Verified original behavior

The existing default is `BORROWING_DAYS = 14` in includes/borrowing.php. Ordinary checkout and reservation fulfillment previously set due_date using DATE_ADD(NOW(), INTERVAL 14 DAY). Manual Add Borrowed Student checkout also used that default when its date field was blank; a staff-provided date was stored at 23:59:59 PHT. Approval/ready alone did not start the loan. These were inspected before implementation, rather than assumed.

The initial default remains 14. No active borrowing-policy file has been created and no default edit was submitted. New settings are private and apply only after an administrator explicitly saves an approved change.

## Staff workflow

Borrow Requests, reservation checkout, QR pickup verification and manual checkout now use one shared due-date confirmation dialog:

1. Use current default (the actual configured number is shown).
2. Custom number of calendar days, such as 3.
3. Custom date, through 23:59:59 on that Philippine date.

The dialog shows the exact server-calculated deadline in PHT before Confirm Checkout. Cancel/Escape submits no checkout. Invalid dates/durations or unavailable policy leave confirmation disabled. A late response cannot replace a newer selection. Checkout/availability/authentication/stock locks remain in their original business endpoints.

The server stores a random, session-bound preview token containing the exact record/source/deadline, policy version and ten-minute expiry. It accepts that preview only for the same staff session and checkout source/record. This makes the displayed timestamp and saved due_date identical and prevents substituting an arbitrary client timestamp. Relative periods are calculated at preview time; final confirmation uses that explicitly reviewed timestamp, while the actual handover timestamp is recorded at checkout. If a default policy changes or the preview expires, staff must review again. Custom selections remain independent of later default edits.

The server remains authoritative. Original API callers without a preview still receive the current default, calculated from the checkout timestamp. Existing manual API date overrides remain supported through the common date validator. New custom UI choices use previews.

## Administrator default policy

Borrow Requests has a Default borrowing period section. Librarians can view the current default and choose per-checkout overrides; only administrators can save a changed default. Saving requires authenticated administrator access, CSRF, a valid version, an approved-policy reason and explicit confirmation that only future checkouts change.

The private includes/borrowing-policy.json stores days/version and its audit together. Each change records administrator ID, before/after days, reason and PHT timestamp. A lock and atomic rename keep simultaneous administrators from overwriting one another; stale screens receive HTTP 409. Corrupt/unreadable policy blocks new checkout instead of silently choosing another deadline. Existing returns/reporting remain available. Reads do not create the policy file. No database migration or new table is needed.

The policy JSON, its stable lock and temporary files are Git-ignored and denied by the existing includes/.htaccess. Protect/backup this file alongside private server configuration; a database-only SQL dump does not include it. The isolated test runner excludes it from copies, so school defaults/audit reasons are not imported into fixtures. This persistence follows the current single-server file-settings approach; multiple independent application servers would need a shared policy store and are not introduced here.

Inputs support 1–365 calendar days or a valid future end-of-day date within 365 days, capped at the existing TIMESTAMP date range before January 19, 2038. This is an implementation validation bound, not a claim that the school approved a 365-day loan. Staff must use the approved borrowing period. No weekend, holiday, renewal, maximum-extension or automatic-shortening policy is invented.

## Saved date consistency and preservation

Ordinary records retain due_date in borrow_requests; reservation records retain due_date in reservations. Student histories, transactions and reports already select those saved fields. The existing notifyCheckoutEvent reads the saved due_date after commit, so the unchanged checkout SMS template uses that same deadline. UniSMS/Semaphore restrictions and duplicate suppression remain in their shared engine; no new SMS event was added. Philippine timezone display was made explicit in the affected Borrow Requests and Transactions formatters to avoid an overseas device showing the previous date.

Default edits never update active loans. The optional Change Due Date action for active loans is not implemented in this focused change. Existing endpoints reject attempts to edit deadlines through another action. Tests verify an existing deadline survives default edits and attempted unsupported changes; successful active-loan editing is not claimed. A future editor needs separately approved shortening/extension rules, a reasoned before/after audit and an explicit decision about borrower notification. There is no assumed due-date-change SMS trigger.

All 12 active table fingerprints, private SMS configuration, both consumed acceptance claims and existing homepage/login/image/upload/Phase 9 customizations match the starting snapshot. Actual UniSMS remains Live / production authorized / administrator permission ON, untouched by these changes. All write-based verification used the owned disposable database and mock transports. No real SMS/provider request or credit consumption occurred. Phase 11 remains deferred, main is unchanged, and nothing is staged/committed/pushed/deployed.

## Verification

- 894 schema-only isolated connected workflow checks passed. New cases cover the original 14-day default, audited admin-only future-default changes, default five-day checkout, custom three-day ordinary/QR/reservation checkout, exact preview-to-record equality, cross-session/source/record rejection, changed-policy preview rejection, duplicate QR and manual submissions, same saved dates in student history/transactions/reports/SMS, invalid dates/durations, corrupt-policy rollback, and protection of active deadlines. Existing borrowing, reservation, QR, report, attendance, authentication and seven-event mock SMS regressions are included.
- 35 database-free due-date checks passed, including leap years, PHT end of day, expiry, TIMESTAMP range, unchanged initial policy, private audit permissions and simultaneous policy writers (one successful version, one stale rejection).
- 17 shared checkout-dialog JavaScript checks passed: default from server, exact deadline, custom three days, disabled invalid selection, Cancel/Escape, duplicate-dialog prevention, server-error handling, stale-response rejection, and shared assets on all checkout surfaces.
- 53 borrowing UI, 49 QR UI and 49 reservation UI regression checks passed. Borrow Requests and Transactions dates remain on the Philippine calendar day on a Honolulu-configured test device.
- 94 shared PHP SMS, 105 additional UniSMS adapter, 120 one-attempt guard and 60 production-policy checks passed with mock transports; 45 SMS Logs and 40 SMS Settings JavaScript checks passed. No production transport is available in mock workers.
- Changed PHP/JavaScript syntax and Git whitespace checks passed.

Browser access is disabled in Work settings. Native dialog rendering, keyboard focus and responsive visual acceptance remain pending; no screenshot or browser visual pass is claimed. Automated DOM behavior tests do not replace that review.

## Exact task manifest — 27 files

1. .gitignore
2. includes/borrowing.php
3. includes/loan-due.php (new)
4. backend/borrowing-policy.php (new)
5. backend/checkout-due-preview.php (new)
6. backend/borrow-request-action.php
7. backend/borrow-request-add.php
8. backend/reservation-action.php
9. scripts/phase8-isolated-qa.php
10. js/checkout-due.js (new)
11. js/borrowing-policy.js (new)
12. js/borrow-requests.js
13. js/reservations.js
14. js/pickup-verification.js
15. js/transactions.js
16. css/checkout-due.css (new)
17. borrow-requests.html
18. reservations.html
19. pickup-verification.html
20. transactions.html
21. tests/phase2b-borrowing.js
22. tests/phase3b-pickup-qr.js
23. tests/phase8-workflows.php
24. tests/flexible-due-workflows.php (new)
25. tests/loan-due.php (new)
26. tests/checkout-due.js (new)
27. BORROWING-DUE-DATES.md (new)

Earlier uncommitted client refinements, SMS integration/hardening, custom photos/uploads and documentation remain preserved. The actual private borrowing policy and lock are excluded from this source manifest; they have not been created in the active system during implementation.

## Review guide

Open Borrow Requests to view the current default. On any eligible physical checkout, choose default, custom days or custom date and review Due before Confirm Checkout. For a three-day loan, choose Custom number of days and enter 3; this does not change the library default. Use the administrator section only after the school approves a new default, supplying its reason. Check the resulting saved due date in student history, transactions, reports and SMS Logs. Because the active UniSMS policy is live, legitimate confirmations may consume credits; this implementation/testing did not execute those actions on real records.
