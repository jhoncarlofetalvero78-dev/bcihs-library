# UniSMS live delivery preparation

Current status: the owner explicitly authorized local production activation after personally confirming handset receipt for account #368. The local backend is now UniSMS / Live / production authorized, with administrator permission ON and no active acceptance scope. Production was activated without generating a provider request. Historical preparation/test procedures below remain as evidence. Phase 11 remains deferred.

## Why delivery previously stayed simulated

The selected provider was UniSMS, but private mode was `simulate` and `live_enabled` was false. The administrator ON setting permits notifications; it does not authorize paid transport. Previously every live UniSMS request also required the approval-only acceptance guard, whose account:364 claim is consumed. Password recovery therefore refused UniSMS explicitly.

The adapter already uses POST `https://unismsapi.com/api/sms`, JSON `recipient`, `content`, `sender_id`, HTTP Basic authentication with the API secret as username and empty password, and fixed default sender `Unisoft`. Connection/request timeouts remain 5/10 seconds. Redirects, automatic retries, provider fallback, bulk sending and historical replay are absent. A returned reference with pending/sent status never proves handset receipt.

Official references reviewed for this change: [SMS API](https://unismsapi.com/docs/sms) and [student/default-sender restrictions](https://unismsapi.com/docs/students). Only public documentation was accessed, with no account/API credentials.

## Separate authorization paths

The existing private `mode`, `live_enabled`, provider, credentials, network verification and administrator permission gates remain required. Two private settings are now recognized:

| Setting | Safe default | Purpose |
| --- | --- | --- |
| `unisms_delivery` | `acceptance` | Select the existing one-attempt test policy or the separately reviewed `production` policy. Unknown values fail closed. |
| `unisms_production_authorized` | `false` | Explicit private production approval; the administrator checkbox cannot change it. Strict Boolean true is required for production. |

Acceptance retains its exact account/event/recipient/content match, ten-minute maximum window, owner-only persistent ledger, atomic consume-before-transport reservation and reset-to-simulation-before-transport behavior. A new separately approved test requires a new private directory and new disposable account event. Editing an event cannot reuse the old directory. Never reset, overwrite, delete or reuse the account:364 claim. Production requires `live_test` to be null so it cannot reinterpret an acceptance scope as general authorization; preserve historical claim storage separately before any eventual approved configuration turnover.

Production requires all private live gates, production policy and strict production approval. Only supported, bound library events with an actual user ID are accepted. Account events must match that user; borrowing/reservation events need their canonical record prefix; recovery needs the exact user/challenge binding. Unsupported legacy sends fail closed. Business modules retain their existing authenticated state transitions and recipient lookup. No migration is needed.

UniSMS still requires an owner-verified current Globe/DITO network mapping; number prefixes cannot establish a ported number's current carrier. The default sender rejects links. A local one-segment limit now counts GSM-7 basic characters, double-width extension characters and Unicode UTF-16 units. This may skip long/non-GSM book-title templates instead of spending several credits. Templates and inventory/borrowing logic are unchanged. Semaphore does not inherit UniSMS's carrier, link or segment rules.

## Seven live-capable scenarios

| Scenario | Shared event / source | Authorization |
| --- | --- | --- |
| Account approval, supported roles | `account_approved` / `account:<user>` | Acceptance for its one exact approved test, or production |
| Borrowing approval | `borrow_ready` / `borrow:<request>` | Production |
| Borrowing rejection | `borrow_rejected` / `borrow:<request>` | Production |
| Reservation ready | `reservation_ready` / `reservation:<reservation>` | Production |
| Ordinary checkout | `checkout` / `borrow:<request>` | Production |
| Reservation checkout | `checkout` / `reservation:<reservation>` | Production |
| Recovery OTP | `password_reset` / `reset:<user>:<challenge>` | Production; rate limits and session-bound challenge retained |

Administrator OFF suppresses all paths centrally, records safe skipped-event information and does not queue messages. Re-enabling never replays suppressed events. An already-approved account cannot be approved again to force a resend. Existing database locks and reserved event logs suppress duplicates, including timeouts/uncertain responses. SMS Logs remains a local view, not provider polling. OTPs remain redacted from stored/public logs; simulation or disabled delivery does not issue an undeliverable recovery challenge. A mode switch between the recovery precheck and dispatch also cannot count a simulation as a submitted OTP.

## Current dashboard prerequisites: pending owner confirmation

The safe checker can establish only file/configuration state, not remote key acceptance or current credits. Do not read private configuration into terminal output, dump environment variables, log Basic headers or print raw provider bodies.

Before any live authorization the owner must privately confirm revoked exposed keys, active current key, eligible account/default Unisoft sender, supported consented DITO recipient, current credit balance and actual one-segment charge. Last confirmed balance was 8 credits; it has not been freshly checked. Official student documentation currently describes one credit per 160-character standard message and PHP 0.50 per SMS; dashboard/account terms determine actual cost. Stop if charge is uncertain or exceeds one credit. No authenticated account/status request was made in this preparation.

## First new account-approval test: not armed or bound

No new active-database account was created; a new exact account ID/event cannot be assigned honestly yet. Users #364/#365 and all current accounts/logs remain untouched.

1. Owner confirms dashboard prerequisites and separately authorizes creation of exactly one disposable pending student with the privately consented recipient. Take a current private backup before that change. Keep simulation/live-off and production approval false.
2. Identify the resulting account ID and immutable `account:<new ID>` event. Prepare a new canonical owner-only 0700 directory outside the web root and repository, with no existing claim. Bind the exact normalized private recipient. Preserve the old claim and acceptance evidence. Check MAMP PHP's owner/write permissions without printing the number or credentials.
3. Present the sanitized approval summary: new account ID, exact event, UniSMS/Unisoft, private recipient match confirmed, exact content, one-segment estimate, current dashboard cost and one-attempt scope. Do not approve the account before the live authorization; an earlier simulated approval cannot be replayed.
4. STOP for explicit authorization of that exact event/recipient/message/provider/charge. The message is exactly: `BCIHS Library: Your account is approved. You may sign in to the library portal.` It is 79 basic GSM-7 characters with no link, prefix or Unicode. Expected cost: one credit if confirmed by the owner/provider; last reported balance 8 would become 7.
5. Only after authorization, privately arm `unisms_delivery=acceptance`, a fresh exact `live_test` with maximum ten-minute issue/expiry window, `mode=live` and `live_enabled=true`; keep `unisms_production_authorized=false`. Do not use environment-forced live overrides. The administrator permission must be ON, but every other event/recipient is rejected by the central test guard.
6. Owner performs the authenticated staff approval once. The guard atomically consumes the new claim and restores simulation/live-off before transport. Never retry an uncertain attempt or switch providers. Independently run the safe checker, confirm the retained new claim and correlate the single local reference with the dashboard. Confirm handset receipt separately and record exact credit deduction. Keep evidence/fixtures until reviewed.

There is no public Send Test SMS control. A successful acceptance does not turn production on.

## Separate production activation checklist

After live acceptance and a separate school/owner approval, review supported/consented recipients and current-network mappings, content/segment restrictions, available balance, sender eligibility, key privacy, HTTPS deployment, staff access and recovery policies. Archive both consumed claim directories privately without modifying them. Only then configure `provider=unisms`, `mode=live`, `live_enabled=true`, `unisms_delivery=production`, `unisms_production_authorized=true`, `live_test=null` and the private current secret/network map. The administrator ON switch is still required. Start with a reviewed recipient scope; adding recipients requires private network verification. Never automatically enable production after the acceptance test.

Turning administrator permission OFF stops future admitted sends; an already in-flight provider request cannot be recalled. Disable private live authorization for maintenance. Do not replay historical logs. Scheduled reminders/broadcasts are not added. Delivery webhooks/polling remain future work; the local Refresh button reloads local records only.

For eventual Semaphore turnover: switch the private provider/credentials/sender, remove active acceptance scope (`live_test=null`), retain simulation until school-funded credits, approved sender, recipient consent and a separately authorized school-account live acceptance are complete. No borrowing/reservation/account changes or database migration are required. No live Semaphore delivery is claimed by these mock tests.

## Verification and preservation

The schema-only isolated runner copies no legitimate records or private credentials and disables cURL execution in its web workers. Production policy tests run the real policy against mock responses; the legacy permission probes use their existing copy-only acceptance stub. The actual one-attempt guard is tested separately for exact scope, concurrent reservation, restoration failure, timeout, process termination, old-ledger preservation and independent new-ledger enforcement. The connected workflow matrix tests all seven scenarios against simulation/UniSMS/Semaphore, including actual recovery endpoints for mock production providers.

Final measured results:

- 770 schema-only isolated workflow checks passed, including seven-scenario production UniSMS/Semaphore mocks, simulation, OFF/ON behavior, actual recovery endpoints, duplicates/concurrency, failure paths, and borrowing/reservation/authentication regressions.
- 94 shared PHP SMS checks, 105 additional UniSMS adapter checks, 120 real one-attempt guard checks, and 60 production policy checks passed with mocked transports. Guard coverage includes an independently scoped second ledger while retaining the old consumed ledger byte-for-byte.
- 45 SMS Logs, 40 SMS Settings, 53 QR, and 162 Phase 8 UI/security JavaScript checks passed. 28 PHP security checks and the safe-diagnostics regression passed.
- Changed PHP files passed syntax checks; SMS Logs JavaScript syntax and Git whitespace checks passed.

The active preservation baseline was captured at 22:34:10 PHT on October 28, 2026. During this work the active application recorded checkout of legitimate request #299 at 22:34:25 PHT: book #300 borrowed count increased by one, request #299 gained checked-out status/timestamp and due date, and skipped checkout SMS log #270 was added. Log #270 has request_attempted false. An in-memory comparison accounting for only those changes reproduces the three original table hashes exactly. No rows were reverted or treated as fixtures. All other active tables, private configuration, consumed account:364 claim, uploads and homepage/login/Phase 9 customizations matched the baseline. The implementation/tests did not issue an active-database business action; the concurrent checkout is preserved. There is no unexplained difference in the comparison.

No legitimate active record is modified to satisfy tests. No real SMS or provider account request, credit consumption, active fixture creation, staging, commit, push, merge or deployment is part of this preparation. The schema-only disposable test databases were removed by the owned runner. Historical evidence, backups and the Phase 11 deferred branch remain in place.

## Files changed for this preparation

- `includes/sms.php`
- `includes/sms-core.php`
- `includes/sms-permission.php`
- `includes/sms-local.example.php`
- `backend/forgot-password-request.php`
- `js/sms-logs.js`
- `tests/phase7b-sms.php`
- `tests/phase7b-live-guard.php`
- `tests/sms-acceptance-install.php`
- `tests/sms-acceptance-workflows.php`
- `tests/unisms-production-policy.php` (new)
- `UNISMS-LIVE-READINESS.md` (new)

Previously added uncommitted safe diagnostics (`tools/sms-config-status.php`, `tests/sms-safe-diagnostics.php`, and the summary helper in `includes/sms.php`) are retained. Unrelated photo/upload/UI changes and `PHASE10B-ACTIVATION.md` are excluded from this implementation manifest. The actual private SMS configuration is not edited.

## Final account-approval preparation review — October 28, 2026

Owner confirmations accepted: the cellphone is owned/consented, its current network is DITO, the intended provider is UniSMS, the Unisoft sender is eligible for this recipient, and sufficient credits were checked in the dashboard. Do not request these confirmations again unless eligibility/configuration changes. The numeric dashboard charge was not supplied; report it as pending rather than deriving it from an old balance. Local presence checks cannot establish a secret's issuance/rotation history or uncompromised provenance. Owner confirmation that the configured secret is the latest uncompromised rotation remains required; never request the secret itself.

The dedicated safe checker confirms readable private configuration with safe permissions, key present, UniSMS selected, simulation enabled, live disabled, production authorization disabled, acceptance unarmed and the account:364 claim still consumed. Git confirms the private configuration is ignored. These are local Boolean checks, not a provider authentication test.

No additional implementation is needed for an independent one-attempt acceptance. Exact matching, exclusive persistent reservation, reset-before-transport and fail-closed behavior remain enforced. The new disposable account and its actual ID do not exist yet; no fabricated ID is assigned. New private binding/ledger creation remains pending separate approval. Tests use synthetic IDs only, outside the active system.

Database safety procedure prepared, not executed:

1. After separate approval for backup/restore verification and one pending fixture, pause manual library activity. Use the existing `scripts/local-backup.php` helper `backupLocalDatabase` against the local active database with a matching MAMP `mysqldump` executable. Both MySQL 5.7 and 8.0 clients are installed under `/Applications/MAMP/Library/bin`; choose the client matching the server. Do not run a migration script to obtain a backup.
2. Use a dedicated 0700 directory outside the web root and Git repository. The helper creates 0600 SQL/manifest files, passes credentials in a temporary private options file, and deletes its options/error files in cleanup. Never put passwords in command arguments or display SQL contents. It takes row-count/hash snapshots and rejects activity during the dump. Record a private backup checksum and verify all 12 tables are included.
3. Create a new, uniquely named, owned disposable restore database only after that operation is authorized. Inspect the dump privately for database-selection/creation directives and active-schema qualifications; refuse an unsafe import. Import using an explicitly selected disposable target and private credential handling. Do not point an application, event worker or SMS transport at the restored database. Do not restore into `bcihs_library`.
4. Compare every restored table's schema, record count and row fingerprint with the saved manifest. Also recheck active-table fingerprints after restore. If any verification fails, preserve evidence and stop before fixture creation. Remove only the owned disposable restore database after successful review/verification; keep the private backup.
5. With simulation/live-off/production-off still confirmed, create exactly one clearly named pending disposable student using the existing registration workflow only after account-creation authorization. Use the already privately configured consented recipient; do not display or copy it into reports. Capture the actual returned database ID, verify pending status, and compare all pre-existing records with the snapshot. Do not approve the account in simulation, because an approval event is not replayable.
6. Prepare the exact `account:<actual ID>` binding in private configuration only after its preparation is authorized, with a fresh owner-only persistent ledger directory outside the web root. Verify exact normalized recipient equality and DITO mapping in memory and report only Boolean matches. Keep mode simulate, live_enabled false, production authorization false and test authorization disabled; do not issue live timestamps or create/consume a claim.
7. Present the actual event/account, the fixed 79-character message, UniSMS/Unisoft, a Boolean recipient match, the owner's numeric dashboard cost and the one-submission limit. A separate explicit approval is required before arming a maximum-ten-minute authorization, enabling the short live test window, or performing the staff approval. The owner may then approve through the existing authenticated workflow once. Preserve both old/new evidence; never retry or fall back on an uncertain result. Production remains separately disabled after acceptance.

No backup/restore operation or active/disposable database mutation was executed in this final preparation-only review. The earlier connected workflow results remain recorded; they were not rerun because this review prohibits database mutation. Fresh database-free runs passed 94 shared SMS, 105 UniSMS, 120 guard, 60 production-policy, safe-diagnostic, 45 SMS Logs and 40 Settings JavaScript checks. The exact unchanged message was validated as one basic GSM-7 segment with no link. No sender-rule change is needed for the owner-confirmed eligible recipient.

Final-review preservation comparison: concurrent application activity returned request #299 at 22:46:09 PHT, reducing book #300's borrowed count by one. Accounting for only that return in memory reproduces both starting table hashes exactly. All other tables, SMS logs/audit, private configuration, consumed claim, uploads and customizations matched. No application business request or database write was made by this preparation; the legitimate return remains preserved. Consequently the report must not claim all database records were literally unchanged during the review.

## Owner-authorized local production activation — October 28, 2026

The owner confirmed handset receipt for account #368 and explicitly authorized routine local production notification attempts for all seven supported scenarios, subject to existing restrictions. Read-only verification found account #368 accepted and log #271 for its account approval: UniSMS, one request attempted, HTTP 201, local status pending. No poll was made and the local pending state was not fabricated as delivered. Handset receipt is owner-confirmed; current exact balance/deduction is not independently queried.

Production was activated at approximately 22:57 PHT using the existing private fields: provider unisms, mode live, live_enabled true, unisms_delivery production, unisms_production_authorized true, live_test null. Administrator SMS permission remains ON. Both consumed claims were verified and retained byte-identically outside the web root; their private event/directory references are retained in `consumed_live_tests`, with the old `previous_live_test` evidence also preserved. A private preactivation configuration copy and table/file/claim fingerprints were saved outside Git and the web root. No claim was cleared, reused or replayed.

The dedicated status checker now reports the effective mode and production policy through allowlisted values/Booleans, plus consumed-claim verification for both accounts even after live_test becomes null. Its regression checks confirm no secret, recipient or private path enters output. The backend reports Live and ready through the same smsDeliveryStatus function consumed by SMS Settings. Browser access is disabled in Work settings; the actual page was not visually checked and that setting was not bypassed. The owner can refresh SMS Settings to confirm its display.

Fresh activation regressions: 770 isolated connected workflow checks, 94 shared SMS PHP checks, 105 UniSMS adapter checks, 120 one-attempt guard checks, 60 production-policy checks, safe diagnostics, 45 SMS Logs and 40 SMS Settings JavaScript checks passed. Mock processes used no live transport. All 12 active tables, both claims, uploads and existing customizations matched the preactivation fingerprints after the private configuration change. No active account or business action was created, approved or submitted during activation. No provider/API request, extra SMS or credits were consumed by activation. No Git staging, commit, main change, push, merge or deployment occurred.

Routine authorized production sends remain live after completion; they do not run the acceptance-only simulation reset. The administrator OFF switch still blocks future submissions centrally. Only privately network-verified Globe/DITO recipients are eligible; other recipients are safely skipped. One-segment/no-link restrictions, OTP security, deduplication and unknown-response no-retry behavior remain enforced. No automatic fallback, bulk send, scheduler or historical replay is enabled. Additional verified recipients require explicit private network-map maintenance, never prefix inference.
