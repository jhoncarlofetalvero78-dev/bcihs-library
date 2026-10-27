# Phase 7B.3 final SMS integration review

Date: 2026-10-28. Branch: development. Base HEAD: 2bd8351. No commit, push, merge or deployment is authorized by this review.

## Outcome and minimal changes

The prior separately authorized one-message UniSMS acceptance succeeded. This review sends no SMS or provider API request and creates no database fixture. The old auxiliary watchdog's invocation/lifetime problem was reproduced with a synthetic file-only worker: detached launch returned success but produced no startup/completion marker or error; the identical worker completed in the foreground. Historical logs cannot identify an exact termination signal. The defect was trusting an unacknowledged background launch, not a demonstrated MAMP permission failure.

The acceptance flow no longer relies on that watchdog. After the existing permanent claim is consumed, the UniSMS boundary synchronously resets private file settings to simulation/live-disabled/auth-disabled **before** invoking its one transport. If reset cannot be completed, no API call is made and the slot stays consumed. Other private values remain unchanged. Semaphore's normal production path does not run this reset.

SMS Logs now explicitly explains local-only Refresh, absence of provider polling, possible stale statuses and the distinction between provider sent and handset receipt. The control reads Refresh local logs. No historical row is changed or fabricated as Delivered. A verified tablet-width search squeeze was fixed by giving search its own row. No page redesign or unrelated CSS change was introduced.

## Preservation and fixture policy

The completed acceptance's disposable student, single linked SMS log and persistent consumed claim are retained. Their exact IDs/reference, recipient, private backup and evidence are outside Git and reported privately to the owner. The foreign-key review found one SMS-log link and no borrowing, reservation, attendance or announcement links for the disposable account. The normal history-aware delete protection remains enabled.

Recommended cleanup, only after explicit approval: preserve a fresh private backup plus exact user/log/claim and provider/receipt evidence; choose SMS audit retention first. Prefer retaining the linked audit until that decision. If removal of both disposable records is approved, recheck exact identities/hashes and all foreign-key references immediately, remove only that child log and its parent fixture in a transaction, assert affected-row counts, and compare every non-fixture table/record. Any additional linked history stops cleanup. Keep all legitimate historical logs and the consumed claim. Never delete a parent to bypass history protections or restore/reset the whole database.

No database schema change or migration is needed. The review uses full row fingerprints for all ten tables, including the retained acceptance records, and hashes of the real private configuration and consumed claim. All eight unrelated homepage/login/photo/upload files are separately hashed. Provider/API secret and full real recipient values are checked in memory against only the exact final manifest; no values are printed.

Private sms-local.php and private temporary reset files are Git-ignored. The real guard directory, database/config backups, test scripts and acceptance exports are outside the repository and web root, so ordinary git add cannot stage them. The index must remain empty until a separate commit approval. Git ignore can be overridden by force-add; the final allowlist and secret scan remain mandatory. No scanner or ignore rule is claimed to prevent a privileged operator deliberately publishing a secret.

## Supported workflows and limitations

Simulation, UniSMS and Semaphore share account approval, borrow approval/rejection, reservation ready, borrowing/reservation checkout with actual due dates, and existing password-reset handling. Source-aware event hashes, lock/log reservation, masking and redaction remain common. Provider selection/turnover uses private configuration, credential and sender settings only; no workflow code or historical replay is required.

UniSMS's current default Unisoft student sender is restricted to owner-verified Globe/DITO recipients and no links. The private map is not an online carrier lookup; porting means prefixes are not authoritative. Its current live acceptance policy permits only the exact account-approval scope. Other events remain in simulation until a broader live-testing policy is reviewed. Semaphore keeps its normal approved account/sender behavior without that UniSMS test scope. Neither provider's all-event real delivery is proven by mock tests.

Credentials, consent, sender registration, available credits, outbound HTTPS/TLS and account/network eligibility must be reviewed for the actual deployment. The previously observed billing applies to that acceptance only, not a permanent pricing promise. Unicode in ordinary templates may create multiple segments; the acceptance text is fixed basic GSM-7. Provider internal retries/billing/delivery cannot be controlled by the application.

Provider synchronization remains future work. Local Refresh does not poll either provider; authenticated status queries/webhooks would need separate authorization, privacy and reconciliation review. Reminder/overdue scheduling, return/cancellation messages and bulk announcements remain unimplemented/disabled pending client policy. There is no configured reminder scheduler, automatic fallback or retry.

Single-server guard/filesystem limitations, reset failures, process termination before reset, Windows ACL support and multi-server storage requirements are documented in PHASE7B-LIVE-GUARD.md. Expiry/consumed-slot checks remain independent of configuration restoration. No newly discovered unresolved application defect is to be hidden as a test limitation.

## Final uncommitted integration manifest

Exactly 17 source/test/documentation files relative to base commit 2bd8351:

```text
.gitignore
PHASE7B.md
PHASE7B-UNISMS.md
PHASE7B-LIVE-GUARD.md
PHASE7B-HARDENING.md
backend/sms-logs-data.php
css/sms-logs.css
includes/sms-core.php
includes/sms-local.example.php
includes/sms.php
includes/sms-live-test.php
js/sms-logs.js
sms-logs.html
tests/phase7b-sms.php
tests/phase7b-sms.js
tests/phase7b-unisms.php
tests/phase7b-live-guard.php
```

Exclude the five homepage/login/photo changes and three separately uploaded images. No fixture row export, real recipient, API secret, backup, ledger, screenshot, private configuration or generated test output belongs in this manifest. Names/numbers in committed mocks are synthetic; actual private evidence is not copied into this document.

## Final verification

Results are recorded below after the final safe run. Browser verification is localhost-only. No provider dashboard is refreshed, balance queried, SMS sent, credit purchased or additional database fixture created during Phase 7B.3. Reservations' database-writing suite remains not rerun because genuine future reservations exist; Attendance's old historical-data isolation assumption is not treated as an application defect. Database-free JavaScript regressions remain appropriate and are reported separately from historical API suites.

### Completed results

- **305 PHP checks passed:** 83 existing SMS checks, 105 UniSMS/event-matrix checks, 117 strict-guard/restoration checks. cURL entry functions were disabled. Coverage includes shared events/provider switching, exact payloads, duplicates, ten-process competition, repeated requests, timeouts, malformed responses, exceptions, PHP exit, SIGKILL during mock transport, invalid/cancelled scope, permissions, missing/corrupt config, failed temporary writes and environment overrides. Restoration is asserted before mock transport. A reset failure preserves the claim and makes no request.
- **43 SMS Logs JavaScript checks passed**, including local-only refresh wording, stale-status explanation, absent Delivered status and safe reset-failure/no-request labels.
- Seven database-free regression suites passed: Books (four groups), Borrowing (50), Reservations JS (48), Attendance JS (37), Reports (52), Users/Announcements/XSS (187), Pickup QR (50). These are not new database-backed API test passes.
- Eight PHP and two JavaScript syntax checks and working-tree/index whitespace checks passed. No unexpected stderr was accepted in the final test run.
- Localhost browser verification passed at **1440, 768 and 390 pixels**: readable status explanation/mode banner, filters/search/empty state/local refresh and mobile navigation; table overflow stayed inside its scroll container. Document width matched viewport at all three widths. The tablet search squeeze was corrected and visually rechecked. The temporary viewport override was reset. Error/loading behavior is covered by the DOM harness rather than a claim of new real network-failure browser testing.
- All ten current table fingerprints, including the retained real acceptance fixture/log, match the read-only Phase 7B.3 baseline. Private configuration, consumed claim and pre-fixture backup integrity remain unchanged. Simulation/live-disabled/auth-disabled settings are verified. The retained fixture has one SMS-log relationship and no other reviewed user foreign-key links. No record is deleted or updated in this phase.
- All eight unrelated photo/upload file hashes match. Actual API secret and full private recipient strings are absent from all seventeen manifest files. Private configuration/temp patterns are ignored; backup/ledger/evidence remain outside Git/web root. The index remains empty, and the actual changed/untracked set must equal the manifest plus the eight known exclusions.
- No live provider request, status/balance API lookup, SMS, credit purchase, database fixture, migration, reset, commit, push, merge, deployment or main change occurred in Phase 7B.3. The prior acceptance remains the sole verified live integration send; its safe evidence is preserved privately.

**Readiness:** ready for the owner's separately approved Git checkpoint, with the documented deployment limitations. No unresolved code defect was identified by the final review. This is not approval to enable live operation or remove acceptance records. A new live SMS, provider synchronization, broader UniSMS live policy, scheduled reminders, multi-server guard or Windows storage implementation requires its own review/authorization.
