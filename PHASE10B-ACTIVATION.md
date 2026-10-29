# Phase 10B — controlled local SMS activation

Completed 28 October 2026, Asia/Manila. Local database activation only. No commit, remote operation, deployment, collection import, live SMS or paid provider request.

## Starting repository and target

Branch: development. HEAD: 5b77d8eae03dfa73c39e9837aee5b22aa08c5a79. Annotated bcihs-phase10-sms-control intact. The three earlier Phase 9 tags and main remain unchanged. Initial working tree contained the eight original homepage/login/photo/upload customizations and css/phase9.css visual refinements. All were fingerprinted and preserved byte-for-byte.

Verified target: local MAMP MySQL 8.0.44, 127.0.0.1:8889, database bcihs_library. Actual application: http://localhost:8888/bcihs-library/. Both CLI configuration and Apache's authenticated backend indicated existing UniSMS selection and simulation. Live authorization remained false; no live credential was loaded by smsConfig(). No credentials appear in this report.

## Fresh private backup and restoration test

Verified backup: `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase10b-activation-20261028-184740-4c6ed3.sql`

Size: 19473 bytes. File permissions: 0600, private parent directory 0700, outside the application web root. SHA-256: `db3d0d32e4d31fa9896fd2e0d46892fd4e6e02e35cae56aa0f783042ba65ea69`. Schema and data for all ten original tables were present; dump completion/structure and nonempty contents were checked. Private companion fingerprints are retained alongside the dump.

The backup was restored with the MySQL client into a unique owned bcihs_phase10b_restore_* database. Every table's ordered-primary-key row hash, row count and primary key matched. Column types, ordering, nullability, defaults, character sets, collations, indexes, foreign keys, constraints and auto-increment values matched. Initial literal SHOW CREATE comparisons differed only because mysqldump explicitly writes redundant CHARACTER SET utf8mb4 clauses. This was diagnosed before active migration; normalization was limited to those redundant clauses and independently checked against actual information_schema column metadata. No row difference was accepted. A final fresh backup passed the complete test. Earlier diagnostic backups were retained privately rather than overwritten.

The disposable restored database was also used for migration testing and then removed. The active database was never restored from the backup. Ordinary DDL rollback was not assumed.

## Original ten-table integrity baseline

| Original table | Rows before and after | Primary key | Schema and row fingerprints |
|---|---:|---|---|
| admins | 1 | id | unchanged |
| announcements | 1 | id | unchanged |
| attendance | 2 | id | unchanged |
| attendance_settings | 1 | id | unchanged |
| books | 4 | id | unchanged |
| borrow_requests | 6 | id | unchanged |
| reservation_settings | 1 | id | unchanged |
| reservations | 1 | id | unchanged |
| sms_logs | 6 | id | unchanged |
| users | 3 | id | unchanged |

Full raw SHOW CREATE fingerprints, column metadata and ordered-row checksums are stored in private local evidence, not this report or Git. Attendance #120, user #364 and SMS log #265 were checked individually and unchanged. All genuine historical borrowing/reservation, catalog, approved-account, policy and SMS records remain intact.

## Migration review and installation

The unchanged Phase 10 file sql/sms-notification-settings.sql was verified against HEAD. It contains two CREATE TABLE statements and one INSERT initializing OFF at revision zero. The two InnoDB tables have the intended primary keys, singleton/boolean checks and a unique audit revision. It contains no DROP, TRUNCATE or ALTER affecting the original tables.

Disposable tests passed clean installation, OFF initialization, empty audit, repeated-install refusal without data changes, and incompatible-existing-table refusal/fail-closed behavior. Neither new table existed in the active database before installation. Immediately before applying DDL, backup hash and original schema/data fingerprints were rechecked.

Added only:

- sms_notification_settings: one default row, initially OFF / version 0.
- sms_notification_audit: initially empty.

The actual database now has exactly twelve tables. All ten original table schemas and contents still match their pre-migration fingerprints exactly. Partial or unexpected existing schema was never overwritten.

Deployment privileges remain: a separately authorized DDL/migration account for table creation; application SELECT/UPDATE on the setting and SELECT/INSERT on its audit, plus existing library permissions and GET_LOCK support. The web application does not need routine DDL or audit deletion privileges.

## Actual MAMP/Apache administrator verification

The existing authenticated administrator opened SMS Logs & Settings. Initial OFF, UniSMS selection, effective Disabled mode, configuration explanation and functional controls were read from the backend. Enabling displayed the explicit paid-delivery warning. The authorized OFF → ON change persisted as Simulation, never Live. It was immediately followed by ON → OFF. No business event, test account, book, attendance visit or SMS log was created in the active database.

| Administrator ID | Change | Revision | Displayed Philippine time |
|---:|---|---:|---|
| 1 | OFF → ON | 1 | 2026-10-28 18:49:50 |
| 1 | ON → OFF | 2 | 2026-10-28 18:49:50 |

The two changes were recorded for the existing authorized administrator and committed atomically with the setting revisions. UTC storage converts correctly to PHT. Refresh showed OFF. After signing out and the user signing in again privately, the actual administrator session again showed OFF / Disabled / UniSMS. An initial credential rejection and intermittent browser-control timeouts were investigated; Apache responded normally, the unchanged account was verified, and the later fresh authenticated session loaded successfully. No password, authentication guard or browser security setting was changed.

Actual administrator responsive checks at 1440, 768 and 390px passed, with no unintended document overflow and the final OFF/Disabled state visible. Temporary viewport overrides were reset. The live SMS page was left available for review.

## Authorization and role coverage

Actual unauthenticated Apache GET and POST requests to the settings API returned 401 without writes. Administrator controls were exercised through the real authenticated UI, not by bypassing server authorization.

The active database has three accepted student accounts and no librarian/teacher/visitor test accounts were added. Consequently, librarian view-only browser checks and authenticated student/teacher/visitor denial tests were performed using disposable accounts in an isolated schema, not by impersonating or modifying genuine accounts. Librarian save controls were hidden/disabled at all three widths; server POST returned 403. Borrowers/visitors could neither read nor change settings. Missing CSRF returned 403; invalid values/confirmation were rejected; stale/concurrent revisions were handled safely.

## OFF, ON, race and audit behavior

The central shared engine checks permission under a database-scoped MySQL lock. OFF suppresses all existing account-approval, borrowing-ready/rejection, borrowing-checkout/due-date, reservation-ready/checkout/due-date and password-reset events. Distinct borrowing/reservation event keys are retained. Safe skipped/no-request metadata is saved where appropriate; no queue or replay is introduced. Library business transactions continue normally.

ON allows only the existing mode: simulation stays simulation; separate private live gates, credentials, network rules, consumed acceptance claims and duplicate suppression still apply. There is no automatic retry or provider fallback. Already-admitted submissions finish before OFF returns successfully; already-submitted provider requests cannot be recalled. Database/process loss can leave uncertain outcomes; the existing log reservation and persistent claim remain authoritative and must not be reset.

Disposable concurrent tests proved one accepted setting revision and one rejected stale competitor; duplicate submissions made one mock call. In-flight mock sending was fenced before OFF completed. Missing/corrupt settings, missing audit dependencies and failed audit insertion were tested only in disposable schemas. Audit insertion failure rolled back the preceding setting update. No active table was corrupted to test failure handling.

## Password recovery and provider compatibility

OFF and ON+Simulation recovery issue no code and leave challenge fields unchanged. Missing settings also issue no challenge. Authorized mocked Semaphore live recovery exercises the canonical session-bound reset once; wrong/expired/reused codes are rejected. Timeout and malformed mock responses return an unavailable message, clear unusable challenges, preserve unknown status and never automatically retry. Invalid numbers and unmatched/ambiguous accounts retain validation and anti-enumeration handling. Identity-verified library staff/system-custodian assistance remains the fallback; no insecure password shortcut or unimplemented email recovery is claimed.

UniSMS and Semaphore retain the same shared business hooks, templates, recipient lookup, deduplication, logs and permission boundary. UniSMS-specific Globe/DITO/no-link restrictions do not leak into Semaphore. The actual selected provider and private settings were not changed. The current consumed UniSMS guard remains approval-only and cannot authorize general recovery or another test. Switching to Semaphore later remains private configuration only, subject to school deployment prerequisites.

## Regression results

- 771 isolated workflow checks passed, including additional Phase 10B missing-setting and uncertain-provider recovery cases. Extra fault checks existed only in the private QA copy, not in production code.
- A separate 192-check schema-only browser QA setup passed. Disposable workflows did not import genuine rows.
- 315 PHP SMS mock/guard checks passed: 93 shared, 105 additional UniSMS, 117 persistent one-attempt guard. Network entry points were disabled for mock suites.
- Settings JavaScript 40 and SMS Logs JavaScript 45 passed.
- Borrowing 52, QR 53, reservations 49, attendance 37, reports 52, users/announcement XSS 192 and shared UI/security 162 JS checks passed; Manage Books search/export/QR-removal checks passed.
- Security 28, statistical calculation/Excel 38 and Apache resource/security 9 checks passed.
- Syntax checks passed for 107 PHP and 45 JavaScript files. Git whitespace checks passed.
- Actual administrator and isolated librarian responsive checks at 1440/768/390px passed.

No UniSMS or Semaphore endpoint was contacted. No SMS credits were consumed. Provider balance was not queried or changed; the last user-confirmed balance remains the reference. Existing real-handset acceptance evidence was preserved, not repeated.

## Final persisted state and files

Allow SMS Notifications: OFF. Setting version: 2. Audit rows: 2. Effective delivery: Disabled. Selected provider: UniSMS. Private mode: simulate. live_enabled: false. Consumed test claim, private configuration and existing backups remain byte-identical. All owned restore/QA databases were cleaned up.

No production/application code fix was required in this phase. The only new repository file is this report. Migration execution and backup/test helpers remain private outside the web root. No fixture data, backup or credentials are staged. The existing nine uncommitted customizations remain preserved. No Phase 11 importer was implemented or run.

Exact final Git status is recorded below after the final check. HEAD remains 5b77d8eae03dfa73c39e9837aee5b22aa08c5a79; all four annotated checkpoints and main remain unchanged. No commit, push, merge or deployment.

## Readiness and remaining school deployment work

Phase 10B local activation and verification is complete. The feature is operational in MAMP and deliberately left OFF. Its scope is a single primary MySQL deployment; distributed/multi-primary coordination needs separate review. Refresh reloads local records only; provider-status polling and handset delivery receipts remain future work. No scheduled reminder or bulk-SMS feature was added.

Before official Semaphore use, separately authorize deployment, provision the school-owned account and approved sender, verify funded credits/recipient consent and billing/segment/network requirements, configure private credentials and provider selection, retain simulation during readiness review, then authorize a bounded live acceptance test separately. The ordinary administrator permission toggle cannot supply keys or authorize live transport. Never reuse/reset the consumed UniSMS acceptance claim or automatically replay old skipped/simulated/uncertain events.

## Final Git status

```text
 M css/index.css
 M css/login.css
 M css/phase9.css
 M index.html
 M login.html
?? PHASE10B-ACTIVATION.md
?? images/school-entrance.png
?? uploads/announcements/cab2b00b4032d7442f091c16d66e5ce7.jpg
?? uploads/books/0b868fc0ef0b5b1e2dba5255a94be14b.webp
?? uploads/books/1e9ecf96dab133c920fd6cbeefb497a6.webp
```

Final integrity verification passed after the fresh sign-in and responsive checks. Staging is empty. Only the two new SMS tables contain new data; original schema and row hashes, private file hashes, protected records and all checkpoints remain unchanged.
