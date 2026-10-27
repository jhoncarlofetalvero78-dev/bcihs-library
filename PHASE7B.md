# Phase 7B — Semaphore SMS Notification Integration (simulation only)

Implemented on `development` after `e64f71d9673dc4765e17365df6973c97c6dd0a2b` (Phase 3B). The user authorized the Phase 7B checkpoint after the final safe review, with browser verification pending if access remains disabled. No production, deployment, scheduler, bulk SMS, or live-provider test was performed. Existing data and policy settings are preserved.

**No real SMS was sent, no provider credits were consumed, and no handset receipt/delivery was tested.** Current settings were checked without reading credentials: mode `simulate`, live gate disabled. Browser use is disabled in Work settings, so responsive visual checks of the modified SMS Logs page remain pending; no visual pass is claimed.

## Existing integration audit

- Existing borrow approval/rejection and reservation-ready actions called `notifyBorrower` after committing their library transaction. This useful separation is retained.
- The old SMS helper contacted Semaphore whenever a key and phone existed; there was no simulation mode. It treated every HTTP 2xx body as `sent`, without validating returned message status, recipient, or message ID. A logging exception could still escape the helper despite its “never throws” comment.
- Registration/account approval and checkout did not generate notifications. Returns, cancellation, reminders, overdue messages, and broadcasts had no implemented notification policy or scheduler.
- Password recovery already called `send_sms`. Its prior response claimed an SMS “has been sent” even when sending failed or was skipped, and its stored message contained the reset code. This existing path now obeys the same simulation controls, redacts codes in logs, and gives a generic non-enumerating response without a sending/delivery promise.
- SMS Logs displayed full phone numbers and raw gateway responses. Its staff check trusted an administrator session ID without confirming the administrator still existed. Dates depended on the viewer's device timezone.
- `sms_logs` already has `user_id`, `phone`, `message`, `status`, `response`, and `created_at`. Its existing status enum is only `sent`, `failed`, `skipped`; versioned JSON in `response` supports the richer statuses without altering the schema or existing rows.

## Notification policy

“Enabled” below means an event hook is present; **all enabled hooks currently simulate**. Nothing in this phase authorizes switching to paid/live traffic.

| Event | Before | Phase 7B policy |
| --- | --- | --- |
| Registration submission | None | Disabled; registration still enters pending |
| Account approval | None | Enabled after accepted transition commits |
| Account rejection | None | Disabled; not added to recommended event list |
| Borrow request approved / held for pickup | Existing | Enabled; explains hold versus checkout |
| Borrow request rejected | Existing | Enabled after rejection commits |
| Confirmed checkout of a registered borrow request | None | Enabled after checkout commits; reads the actual stored due date |
| Reservation ready for pickup | Existing | Enabled; uses stored pickup time when present, otherwise does not invent one |
| Confirmed reservation checkout | None | Enabled using the same checkout template and stored due date, with a distinct reservation key |
| Anonymous manual walk-in checkout | None | No SMS; existing manual records have no linked user/phone |
| Return confirmation | None | Disabled pending client policy |
| Reservation cancellation | None | Disabled pending client policy |
| Due-date/overdue reminders | None | Disabled; no configured scheduler or approved timing |
| Announcement/bulk broadcasts | None | Disabled; no recipient collection or broadcast operation added |
| Password-reset code | Existing | Retained behind simulation/live gates; code excluded from staff logs |

The five recommended event types are account approval, borrow ready, borrow rejection, checkout, and reservation ready. Reservation checkout is the existing registered checkout path using the same notification event, not a new business operation. No other workflow transitions, account permissions, expiry rules, quotas, or inventory behavior changed.

## Templates

Values come from the committed record, not caller-supplied dates. Book titles are normalized and limited to 45 characters. Templates do not include student names/IDs, QR tokens, addresses, or unnecessary private information.

| Event | Template |
| --- | --- |
| Account approval | `BCIHS Library: Your account is approved. You may sign in to the library portal.` |
| Borrow ready | `BCIHS Library: "{title}" is approved and held for pickup. Show your request QR to staff. Not yet checked out.` |
| Borrow rejection | `BCIHS Library: Your request for "{title}" was not approved. Please contact library staff.` |
| Checkout | `BCIHS Library: "{title}" checked out. Due {stored due date/time} (PHT). Please return on time.` |
| Reservation ready | `BCIHS Library: "{title}" is ready for pickup on {stored pickup date/time} (PHT). A copy is held; staff must confirm handover.` |

The reservation date phrase is omitted if no pickup time exists. Dates use `Asia/Manila`. Missing/invalid checkout due dates prevent a misleading message and cannot roll back a loan. ASCII examples with a maximum-length title are at most 158 characters; Unicode titles can change provider segmentation/credit cost, so this is not a promise of one credit for every live message.

## Simulation and configuration

Default behavior needs no setup: `simulate`, live disabled, sender empty. Installing an API key alone cannot enable sends. Simulation returns before any transport or credential loading.

For optional local MAMP/XAMPP configuration, copy `includes/sms-local.example.php` to Git-ignored `includes/sms-local.php`:

```php
<?php
return ['mode' => 'simulate', 'live_enabled' => false, 'sender' => ''];
```

PHP environment variables override these settings:

- `BCIHS_SMS_MODE`: `simulate` by default. Unknown modes fail closed.
- `BCIHS_SMS_LIVE_ENABLED`: only the exact environment value `1` enables the second live gate. A private file must use the boolean `true`, not a string.
- `SEMAPHORE_SENDER_NAME`: optional existing registered sender name.

No local settings file was needed or created during implementation. No private key file was opened or modified. Credential loading remains compatible with `SEMAPHORE_API_KEY` from the PHP environment/server configuration, then the existing ignored `includes/semaphore-api-key.php`. It is evaluated only when mode is `live` **and** the explicit live gate is enabled. Do not put an API key in tracked examples, frontend code, URLs, chat, or screenshots.

### Safe demonstration without database writes

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/simulate-sms.php
```

This CLI-only script prints five clearly simulated event previews, with a synthetic test recipient, message, event, and `database_written: false`. It does not load configuration/credentials, open PDO, contact Semaphore, create a fixture, change a real transaction, or insert a log row. An optional first argument is a test phone number to exercise validation; it still cannot send. Its example dates are fixed demonstration values, not real loan dates.

The script was also run with `curl_init`, `curl_exec`, and `curl_multi_init` disabled. All five previews were still simulated and validated as JSON, showing that the demo does not depend on a live transport.

Normal application event simulation, if a user later performs a legitimate action, records a new versioned **simulated** log. It is not represented as delivery. Automated tests in this phase use in-memory storage and create no such persistent rows. Do not delete legitimate simulation logs just to make a test baseline match.

## Status accuracy and privacy

The [official Semaphore API documentation](https://semaphore.co/docs) was reviewed on 2026-10-28. Sending uses the standard messages endpoint. Its documented states distinguish queued, in-transit, network-sent, failed, and refunded outcomes; network acceptance is not handset receipt. The returned recipient and positive message ID must match the single-recipient request. No account-balance, live-send, or message-status request was made during this phase.

| Application state | Meaning |
| --- | --- |
| Simulated | No network request or credits; preview of intended notification |
| Pending / reserved attempt | A log was reserved before dispatch; if interrupted, outcome needs review |
| Provider queued | Validated provider response says Queued |
| Pending / in transit | Validated provider response says Pending |
| Provider accepted | Validated provider response says Sent; handset delivery remains unconfirmed |
| Failed / rejected | HTTP rejection or validated Failed/Refunded outcome |
| Unknown / review | Timeout, unsupported JSON/status, or mismatched recipient/message ID; do not blindly resend |
| Skipped | Invalid/missing phone, missing credentials, disabled live gate, or invalid message |
| Legacy sent (unverified) | Historical row from the old helper; retained without rewriting it or asserting delivery |

There is no fabricated Delivered state. Even an unsupported response string `Delivered` is treated as unknown because this implementation has no validated handset-delivery evidence source. No automatic provider polling or delivery reconciliation is claimed.

The existing SQL enum remains unchanged: new queued/pending/accepted outcomes use its `sent` compatibility bucket, failed/unknown use `failed`, and simulated/skipped use `skipped`. **The versioned `response` JSON is the authoritative new status.** The staff endpoint decodes it into canonical statuses instead of presenting the raw enum as delivery. The initial reserved attempt uses the compatibility `skipped` bucket with metadata stating pending/reserved. Existing database rows are untouched. Direct SQL consumers must use the new metadata-aware helpers; the legacy enum alone cannot express these distinctions.

Only safe reason codes, event reference hash, and provider message ID are retained from a new provider response; raw bodies, keys, provider account information, and cURL error strings are not persisted. The staff API omits raw responses/provider IDs, masks all but the last four phone digits, and redacts both new and recognizable historical password-reset codes. Logs remain staff-only, with current administrator/accepted-librarian database checks and GET-only access. Text is escaped before UI rendering; dates use Philippine time.

The existing SMS Logs interface now offers event labels, mode information, accurate filters, safe failure explanations, loading/empty/error/retry states, and an internally scrolling table. No new management page or “send” button was added.

## Duplicate protection and failure isolation

Each transactional notification uses an event/source/record identity: account approval, borrow-ready, rejection, borrow checkout, reservation-ready, and reservation checkout cannot accidentally share a key. The reference is hashed into versioned metadata; no QR token is used. Password-reset attempts use a hash of their newly issued code/expiry, without storing the code in the log.

A MySQL connection advisory lock serializes attempts for the same event. Under that lock, an existing event-key lookup checks both old non-JSON and new JSON rows safely, then reserves a log row **before** invoking the provider. The core business transaction is already committed. Identical event retries—including attempts previously simulated, skipped, failed, unknown, or interrupted—are suppressed rather than automatically resent. Existing business-state checks also reject repeated checkout/approval transitions before reaching notification hooks.

If transport is interrupted after a possible provider acceptance, a retry is intentionally not automatic. If the final log update fails, the reserved row remains pending with a review explanation. A storage/lock/configuration/provider error is caught and cannot throw into the library operation, create another checkout, change inventory, or undo approval. If the initial log reservation fails, no provider call is made.

Student developers should understand two limits of this small design:

- It is best-effort post-commit notification, not a guaranteed outbox. A process crash between the library commit and starting notification may omit an event; no repair worker/scheduler is implemented.
- There is no bulk retry or retry button. Changing simulation to live does not replay old simulated events. An authorized retry policy must first inspect provider outcome and define deliberate retry identities; otherwise duplicate billing/messages are possible.

No additional queue service, daemon, framework, or third-party SDK was added. The JSON event lookup scans the existing small log table; a future high-volume deployment could separately review indexing/outbox requirements. Neither is necessary for this capstone phase.

## Tests actually performed

**No real network delivery or cellphone SMS receipt was tested. No database-writing API/fixture regression was run.**

| Verification | Result |
| --- | --- |
| Phase 7B PHP simulation/mocked-provider/in-memory-PDO checks | 83 passed |
| Phase 7B JavaScript DOM-harness checks | 30 passed |
| CLI simulation previews, including cURL entry functions disabled | 5 events validated, all simulated, no database writes |
| Books JavaScript | 4 test groups passed |
| Borrowing JavaScript | 50 checks passed |
| Reservations JavaScript | 48 checks passed |
| Attendance JavaScript | 37 checks passed |
| Reports/Dashboard JavaScript | 52 checks passed |
| Users/Announcements JavaScript, including stored-XSS regression | 187 checks passed |
| Request-specific pickup QR JavaScript, including refresh fix | 50 checks passed |
| PHP syntax | 11 changed/new PHP files passed |
| JavaScript syntax | 2 changed/new JS files passed |
| SQL compatibility | Deduplication lookup executed read-only against current MySQL without errors |
| Data preservation | All 10 table row fingerprints/settings/SMS logs exactly matched initial baseline |
| Browser desktop/tablet/mobile | Blocked: browser use disabled in Work settings; not passed |

The PHP suite uses an in-memory `PDO`/`PDOStatement` fake and pure mock transport. It checks simulation bypass even with a fake key/live flag, phone formats, invalid/multi-recipient rejection, missing configuration, 400/401/403/429/500 responses, JSON/recipient/ID validation, provider state mapping, no invented delivery, safe exception handling, all five templates, actual stored due date usage, source-separated event keys, duplicate suppression, transaction guards, lock contention, interrupted log writes, privacy projection, and historical status handling. Endpoint integration checks inspect the actual hook placement after commit and the use of existing staff authorization; these are **source assertions**, not a claim of end-to-end HTTP mutation testing.

The JavaScript suite is a DOM harness, not a browser. It checks escaping, Philippine dates on a Honolulu-time device, canonical status/event filters, safe labels, empty/failure/retry behavior, mode feedback, stale-data clearing, and referenced HTML IDs. CSS source was reviewed for 1440px/768px/390px layouts, but that is not visual verification. The browser tool returned “Local browser use is disabled in Work settings”; it was not bypassed.

Database-writing regression suites were deliberately not run on the legitimate active database. The Reservations quota suite remains unsafe with genuine future reservations; the Attendance historical-date suite retains its previously documented fixture-only isolation assumption. Existing historical API results in earlier phase documents are not presented as new Phase 7B passes. Live Semaphore integration, real TLS/provider/account/sender/credit compatibility, real delivery, actual database-backed notification writes/concurrency, and responsive visual acceptance remain unverified here.

## Database preservation and migration

**No schema change or migration. No data reset, deletion, restore, backfill, fixture, simulated log insertion, or policy change.** The new implementation reuses the existing table and keeps historical rows intact.

A read-only transaction recorded initial row counts/hashes outside Git/web root:

`/Users/macbookprom116512/.codex/backups/bcihs-library/phase7b-original-fingerprints.json`

Final read-only comparison confirmed all 10 tables match that baseline. Existing private backups from previous phases were not restored, replaced, or deleted. Since this phase made no database migration or test writes, no new full SQL dump was required. The five homepage/login photo files and three legitimate uploaded images were verified byte-for-byte unchanged. No private database/QR/Semaphore configuration was modified.

## Safe manual acceptance guide

1. Run the CLI simulation script above. Confirm all five events show `simulated`, no provider ID, and `database_written: false`. Try an invalid test phone and confirm `skipped` rather than a network attempt.
2. Run the two database-free new test files:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase7b-sms.php
node tests/phase7b-sms.js
```

3. Once browser access is available, sign in as authorized staff and open SMS Logs. At 1440px, 768px, and 390px, verify the mode banner, filters, safe event/result labels, masked numbers, internal table scrolling, sidebar, refresh button, and long messages. Historical `sent` rows should read Legacy sent (unverified); no raw gateway/OTP should be visible. Test an empty search and a temporary network failure, then restore connectivity. These visual steps remain pending.
4. Do not approve/reject/checkout real school records just to generate demo messages. Existing data is legitimate. If later authorized to perform a disposable local workflow, first take a private backup and identify exact fixtures; keep simulation on. Account approval, borrow approval/rejection, confirmed borrow/reservation checkout, and reservation-ready events should create one accurately labelled simulated log after each successful transition. Verify QR/manual checkout, due dates, inventory, and duplicate rejection before removing only declared fixtures. No such database workflow test was performed in this phase.
5. Do not delete historical or user-generated logs to make counts match. Do not run old database-writing regression suites on the current database without reviewing their assumptions.
6. In simulation, password-reset requests do not deliver a usable code by SMS. Codes are deliberately redacted from staff logs; do not weaken that protection for a demo. Use the normal authorized account recovery procedure instead of claiming receipt.

## Live setup prerequisites — requires separate explicit user authorization

Do not enable live mode under this phase's authorization. Before any paid/provider test, the owner must approve the specific recipient/event and credit budget, validate consent and phone ownership, and review sender/account availability. Keep `simulate`/live-disabled until then.

When separately authorized, PHP needs cURL with TLS verification, a valid private Semaphore key, an active registered sender/default sender, available credits, outbound HTTPS, and both live gates explicitly configured. Configuration must be applied to the PHP web runtime, not assumed from a terminal-only environment. Review Unicode segmentation and avoid retrying unknown outcomes. The implementation has fixed connection/request timeouts and does not follow redirects. No automatic retries, delivery polling, balance lookup, bulk event, or cron task is enabled.

Reminder timing, overdue frequency, recipient consent, retention rules, and optional cancellation/return/broadcast policy remain client decisions. Automatic reminders would require an explicitly configured and tested scheduler plus a deduplication policy. No automatic reminders are claimed.

## Exact Phase 7B manifest

11 modified files:

```text
.gitignore
backend/borrow-request-action.php
backend/forgot-password-request.php
backend/reservation-action.php
backend/sms-logs-data.php
backend/user-approval-action.php
css/sms-logs.css
includes/borrowing.php
includes/sms.php
js/sms-logs.js
sms-logs.html
```

6 new files:

```text
PHASE7B.md
includes/sms-core.php
includes/sms-local.example.php
scripts/simulate-sms.php
tests/phase7b-sms.js
tests/phase7b-sms.php
```

## Final checkpoint review and limitations

Branch remains `development`; HEAD remains `e64f71d9673dc4765e17365df6973c97c6dd0a2b`. `main` remains at `1465cf93735b3e5bff7c1b80e3eec004e16b249c`. At the pre-checkpoint review, the staging index was empty; only the 17-file manifest is authorized for this checkpoint. No push, merge, deployment, `main` change, or next-phase implementation is authorized.

The authorized checkpoint includes exactly the 17 Phase 7B files above. These eight unrelated files remain preserved and excluded:

```text
 M css/index.css
 M css/login.css
 M index.html
 M login.html
?? images/school-entrance.png
?? uploads/announcements/cab2b00b4032d7442f091c16d66e5ce7.jpg
?? uploads/books/0b868fc0ef0b5b1e2dba5255a94be14b.webp
?? uploads/books/1e9ecf96dab133c920fd6cbeefb497a6.webp
```

Credentials, private configuration, backups, generated test outputs, and screenshots are outside this manifest. No live delivery claim is made. After the checkpoint, stop for the next instruction. Browser-responsive and separately authorized live-provider acceptance remain pending. PhilSMS is assessment-only; no provider switch is implemented or authorized.

### Checkpoint verification record

The 17-file scope and complete source diff were reviewed again. The final safe run passed 83 simulated/mock PHP checks with cURL entry functions disabled, 30 SMS Logs JavaScript checks, and all listed database-free regression suites. All 11 PHP and 2 JavaScript syntax checks and working-tree/staged whitespace checks passed. A read-only transaction confirmed all 10 table fingerprints, SMS logs, and policy settings remain identical to the Phase 7B baseline. All eight unrelated photo/upload hashes are unchanged. No database-writing regression, migration, live send, credit/balance lookup, or provider delivery test was run.

Browser access was attempted again during checkpoint review and remained disabled in Work settings. Responsive visual verification is explicitly pending, not passed. This known verification limitation and the existing best-effort post-commit/no-auto-retry limitations were retained; no unresolved implementation defect was identified by the review. Semaphore, simulation, event workflows, duplicate protection, and SMS Logs remain the implementation being checkpointed.
