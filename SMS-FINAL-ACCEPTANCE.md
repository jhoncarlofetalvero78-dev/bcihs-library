# Final SMS acceptance review — 28 October 2026

Branch development; starting checkpoint 324086e1da7b37ba043bce6319d371c270b02acb. The owner subsequently authorized a local checkpoint for the seven-file manifest below. No live provider API, balance/status lookup, SMS, purchase, push, merge, deployment or main change is part of this checkpoint.

## Verdict and credit decision

No additional real SMS was necessary. The retained BCIHS account-approval acceptance already established PHP-to-UniSMS connectivity, HTTP 201, provider Sent, actual handset receipt and one credit deducted. This review exercises the remaining business hooks, payload construction, recipients, logs and failure paths through real local endpoints against schema-only disposable databases, with file-only fake transports. Another handset message would not establish these cases better. Zero extra credits consumed; **8 remains the last user-confirmed balance**, not a newly queried balance.

Semaphore is verified against its API contract and through mock submissions, not through the school's live account. It is technically ready for configuration and a separately authorized school acceptance test, not certified for production delivery.

## Complete implemented event inventory

All staff-triggered actions below require an authenticated administrator or accepted librarian and CSRF. Notifications run after the successful business transaction commits, never while its stock/status changes are in flight. Phone is read from the linked user's current `users.phone`, normalized to domestic 09 form; it is not taken from an arbitrary staff-submitted recipient. Missing/unusable numbers are logged as skipped, without undoing the business operation.

| Event / action | Successful transition | Recipient | Immutable deduplication identity |
|---|---|---|---|
| Student account approval | Pending or rejected -> accepted | Approved student | account_approved + account:{user ID} |
| Teacher account approval | Pending or rejected -> accepted | Approved teacher | account_approved + account:{user ID} |
| Visitor/librarian account approval (also supported) | Pending or rejected -> accepted | Approved visitor/librarian | account_approved + account:{user ID} |
| Borrowing approval | Pending -> awaiting_pickup; physical stock unchanged | Request owner, student or teacher | borrow_ready + borrow:{request ID} |
| Borrowing rejection | Pending -> rejected | Request owner | borrow_rejected + borrow:{request ID} |
| Reservation ready | Waiting -> ready after stock/capacity checks | Reservation owner | reservation_ready + reservation:{reservation ID} |
| Borrowing Confirm Checkout | awaiting_pickup -> checked_out, increment stock loan balance, assign stored due date | Request owner | checkout + borrow:{request ID} |
| Reservation Confirm Checkout | waiting/ready -> fulfilled, increment stock loan balance, assign stored due date | Reservation owner | checkout + reservation:{reservation ID} |
| Password-recovery request | One uniquely matching registered phone/optional role creates a session-bound challenge | Uniquely matched user, including supported borrower/staff-user roles | password_reset + reset:{user ID}:{random challenge marker} |

Account approval is one event shared by four roles, and checkout is one event with two source namespaces. There are **six distinct named event categories**, represented by the nine workflow rows above. Administrator accounts have no separate phone-recovery identity in the admins table. Account rejection has no SMS hook. The legacy notifyBorrower helper has no remaining call sites; it is not an additional active event.

## Exact source templates

`{title}` is trimmed, whitespace-collapsed and truncated to 45 Unicode characters; blank titles now fall back to `your book`. No names, student IDs, QR tokens or links are added. Dates use the stored timestamp interpreted in Asia/Manila, formatted `M j, Y g:i A`; no approval due date is invented.

```text
account_approved:
BCIHS Library: Your account is approved. You may sign in to the library portal.

borrow_ready:
BCIHS Library: "{title}" is approved and held for pickup. Show your request QR to staff. Not yet checked out.

borrow_rejected:
BCIHS Library: Your request for "{title}" was not approved. Please contact library staff.

checkout (borrow and reservation):
BCIHS Library: "{title}" checked out. Due {due_date} (PHT). Please return on time.

reservation_ready (scheduled):
BCIHS Library: "{title}" is ready for pickup on {pickup_at} (PHT). A copy is held; staff must confirm handover.

reservation_ready (no scheduled date):
BCIHS Library: "{title}" is ready for pickup. A copy is held; staff must confirm handover.

password_reset:
BCIHS Library: Your password reset code is {six-digit code}. It expires in 15 minutes. If you didn't request this, ignore this message.
```

Reset codes are hashed in the requesting session, not stored in staff-visible logs. A short database marker binds the session challenge. Unknown/ambiguous numbers receive a generic response, not an arbitrarily selected account/code. Rate limiting, expiry, wrong-code/reuse rejection, and session invalidation on password change remain covered by the isolated workflow suite.

For a 45-character basic Latin title and representative dates, measured lengths are account 79, ready 147, rejection 127, checkout 131, scheduled reservation 159, recovery 125 characters. Single-digit days/hours can shorten these figures. Character count is not a universal segment estimate: GSM extension characters use two septets, and non-GSM titles use Unicode billing. UniSMS's 160-character check does not promise single-credit delivery for arbitrary Unicode titles. Only the previously approved exact 79-character live-test message was independently constrained to a basic GSM-7 subset. Link-looking titles are blocked on restricted UniSMS rather than silently rewritten; Semaphore does not inherit this filter. No templates were casually shortened or transliterated.

## Provider contract review

Official sources inspected 28 October 2026:
- [Semaphore API](https://semaphore.co/docs): HTTPS POST /api/v4/messages; form apikey, number, message, optional sendername; documented default registered Sender Name when omitted. 120 submissions/minute limit; no application bulk/retry loop added.
- [UniSMS API](https://unismsapi.com/docs/sms): HTTPS POST /api/sms; Basic API-secret username and empty password; JSON recipient, content, sender_id; reference ID and pending/sent/retrying/failed states.
- [UniSMS student restrictions](https://unismsapi.com/docs/students): Unisoft, Globe/DITO only, no links. Account/network approval and charges remain provider-controlled.

Both transports use fixed HTTPS URLs, finite connection/request timeouts, and no redirects. UniSMS uses +639 payloads and Semaphore uses domestic 09 payloads. Neither accepts multiple recipients. UniSMS current network must be explicitly owner-verified in private configuration; a prefix never establishes a ported number's network.

UniSMS: payload/auth wiring, pending/sent/retrying/failed, HTTP authentication/credit/network/validation/rate-limit errors, invalid numbers, missing/mismatched reference IDs, malformed JSON, timeout and transport exceptions tested with mocks. Raw arbitrary provider bodies and credentials are not persisted in response metadata.

Semaphore: exact apikey/number/message/sendername payloads; registered-default sender support; Queued -> queued, Pending -> pending, Sent -> accepted by network, Failed/Refunded -> failed. Malformed/missing ID/wrong-recipient/unrecognized status -> unknown. HTTP 400 validation errors (balance, sender, number) remain safely generic rather than guessing undocumented error structures. HTTP 401/403 -> authentication rejection; 429 -> rate-limited; HTTP 0/5xx -> unknown. No provider status is translated to handset Delivered.

## Connected integration verification

Run on MAMP PHP 8.3.30:

```text
php scripts/phase8-isolated-qa.php --run --sms-acceptance
php scripts/phase8-isolated-qa.php --run
php -d disable_functions=curl_init,curl_exec,curl_multi_init tests/phase7b-unisms.php
php -d disable_functions=curl_init,curl_exec,curl_multi_init tests/phase7b-live-guard.php
node tests/phase7b-sms.js
```

The optional SMS QA mode copies only the active database schema into a randomly named owned database; no real rows/private SMS files are imported. It replaces smsTransport only in the disposable application copy with a local file-capture transport. Real curl_exec is disabled in the copied server. To test non-account UniSMS adapter payloads, the guard is stubbed **only inside that disposable copy**. The production account-only one-attempt guard remains unchanged and is tested separately. These tests do not claim production UniSMS permits the other events live.

Per provider (simulation, UniSMS mock, Semaphore mock), actual endpoints were exercised for student/teacher registration and approval, visitor/librarian approval, borrower request approval/rejection, physical checkout and stored due date, teacher reservation ready and physical checkout, and password recovery. Each verifies exact linked account/phone, exact template, correct payload/sender, one log, correct provider/state and exact mock-call count. Simulation makes zero transport calls. Registered pending accounts generate no approval SMS before acceptance. Failed stock checks and unauthorized actions generate no message. Repeat approval/checkout/fulfillment and recovery throttling generate no extra call. Concurrent staff approvals produce one 200 and one 409, with one mock submission.

Timeout, malformed provider response and PHP exit during transport preserve the committed account approval and reserved log. A subsequent provider switch plus repeated approval never resends. An interrupted log stays pending/delivery unconfirmed; timeout/malformed replies stay unknown. Original guard tests include ten-process competition, claim-before-transport, persistence/restart/SIGKILL and restoration faults.

## Duplicate handling, logs and delivery limitations

A MySQL named lock serializes each SHA-256(event + immutable source ID). The versioned SMS log is inserted before transport. Any existing event identity blocks another attempt, including skipped, simulated and uncertain events. Therefore simulation events are **not queued for later live replay**. Locks require the shared MySQL server; logs must not be deleted to force retries. A database-log insert failure suppresses submission. A post-transport log-update failure leaves the reserved pending row rather than permitting a retry. This is at-most-one automatic attempt, not guaranteed eventual delivery: committed business actions may have no SMS if logging/configuration fails. There is no outbox worker/retry scheduler.

SMS Logs are staff-only; phones are masked and reset codes redacted in the API/UI. The private database retains normalized recipient phones for its historical audit, protected with the rest of the database; this review did not erase them. Public/API log projections expose only validated provider references, safe reason codes, attempt flags and HTTP status. Refresh reloads local database records only. No delivery polling/webhook reconciliation is implemented. A provider's Sent is separate from physical receipt; test log 265 can remain locally pending even though provider Sent and handset receipt were independently confirmed.

## Provider switching and school turnover

1. Keep private includes/sms-local.php at mode simulate, live_enabled false. Preserve the consumed claim and historical logs. Back up approved private configuration outside the web root; never put credentials in Git or chat.
2. For simulation set provider simulation. For UniSMS preview set provider unisms, keep simulation gates, configure unisms_secret privately and owner-verified recipient/network entries. Unisoft/no-link restrictions apply only to UniSMS.
3. For school deployment set provider semaphore while still simulated. Configure sender to the school's approved Sender Name. Supply the school key through SEMAPHORE_API_KEY or ignored includes/semaphore-api-key.php returning a string. sms-local's unisms_secret is not used by Semaphore.
4. Set live_test to null for normal Semaphore configuration; preserve the consumed claim file/evidence separately. A non-null acceptance-test scope intentionally blocks Semaphore, even when disabled. Do not delete the consumed claim or reuse it.
5. Inspect environment variables BCIHS_SMS_MODE, BCIHS_SMS_PROVIDER, BCIHS_SMS_LIVE_ENABLED and SEMAPHORE_SENDER_NAME: they override private fields. Confirm the actual deployed runtime settings, approved sender, credits, consenting recipients, TLS/cURL, file permissions, logs/backup access and Philippine timezone.
6. Obtain separate explicit school live-test authorization. Only then set mode live and live_enabled true for that approved operational test. Revert to simulate/false afterward unless official live operation has separately been approved. Missing credentials fail locally; omitted sender uses Semaphore's account default only if the provider has an approved registered default. An unapproved/missing provider sender fails at the provider and must be reviewed, never retried through UniSMS.
7. Do not replay historical simulated/failed/uncertain events or change notification IDs to force a send. Reconcile uncertain attempts with the provider dashboard first. No module, schema or legitimate record change is required to switch providers.

Current UniSMS implementation is deliberately limited to the expiring, exact account-approval live-test authorization and the default sender. It is not a general-purpose live capstone SMS mode for every event. Broadening that live policy or supporting custom UniSMS senders requires a separately approved guard/configuration change. Semaphore does not inherit Globe/DITO, no-link or 160-character restrictions, but provider registration/billing policies still apply.

## Optional/missing notifications — not enabled

| Candidate | Current behavior | Potential usefulness and cost/approval |
|---|---|---|
| Book return | No SMS | Optional receipt; one extra submission per return; librarian approval |
| Reservation cancellation | No SMS | Optional change notice; extra per cancellation; clarify staff versus borrower cancellation |
| Borrower cancellation | No SMS | UI/history confirmation already available; extra cost if added |
| Upcoming due dates | No SMS | Useful reminder; needs approved schedule, timezone, consent, per-loan identity and repeated-run controls |
| Overdue | No SMS | Potentially useful; needs scheduler, reminder cadence, caps and recurring-credit budget |
| Registration pending | No SMS | On-screen pending status; extra cost before approval, no approved requirement |
| Announcements | No SMS broadcasts | Potentially many paid recipients; opt-in/content/recipient scope and budget approval required |
| Attendance | No SMS | High recurring volume; separate recipient/guardian policy, consent and approved budget needed |
| Renewals | No renewal workflow/SMS found | Define renewal policy/workflow before adding a notification |

All implemented notifications are event-triggered. No background scheduler, paid reminder job, provider fallback or new event was introduced.

## Corrections and changed files

Confirmed corrections: Semaphore HTTP 0/5xx now remains unknown rather than definitive failed; blank book titles use the existing meaningful fallback. No approved message meaning or business/inventory logic changed.

Approved local checkpoint manifest:
- includes/sms-core.php
- scripts/phase8-isolated-qa.php
- tests/phase7b-sms.php
- tests/phase8-workflows.php
- tests/sms-acceptance-install.php (new)
- tests/sms-acceptance-workflows.php (new)
- SMS-FINAL-ACCEPTANCE.md (new)

The eight unrelated homepage/login/photo/upload paths remain excluded and byte-identical. The owner authorized staging only the seven task paths for the final local checkpoint.

## Final recorded results and preservation

- **592 connected isolated workflow checks passed**, including the original 194 workflows and the added SMS event/provider/fault/privacy cases. The standalone original 194-check suite also passed without SMS mock mode. Final runs exited successfully; all owned databases, copied applications and temporary credentials were removed.
- **310 SMS PHP checks passed**: 88 shared SMS, 105 additional UniSMS, 117 persistent-guard/restoration checks. Real cURL entry functions were disabled. A further 28 security and 38 statistical/Excel database-free checks passed.
- **43 SMS Logs JavaScript checks**, plus Borrowing 52, QR 51, Reservations 49, Attendance 37, Reports 52, Users/Announcements/XSS 192, Phase 8 UI/security 161 and the four Books groups passed: 637 named JavaScript checks plus Books groups.
- All six changed/new PHP files pass syntax checks; Git whitespace checks pass. No SMS UI source changed. Prior 1440/768/390 responsive SMS Logs results remain recorded in PHASE7B-HARDENING.md; this review reran the DOM suite and real isolated Logs API, not a new responsive-browser matrix.
- Test scaffolding initially refused a macOS /var versus canonical /private/var path and later hit the old simulation-only final assertion after intentionally mocked adapter submissions. Both harness assumptions were corrected; neither was an active application/data defect. Only the final successful counts above are acceptance results.
- Read-only snapshots of all ten active tables still match both original audit and acceptance fingerprints. Student 364 remains accepted, log 265 still references user 364. Private SMS config and persistent consumed claim hashes are unchanged. Simulation is enabled, live sending and test authorization disabled. Private backup integrity is retained.
- Full private configured secret/recipient values are checked in memory against the seven task files, without printing them. No credential or private fixture file is staged; the index is empty. Existing ignored private configuration stays ignored; backups and consumed claim stay outside the repository/web root.
- All eight unrelated customizations are byte-identical. HEAD remains 324086e1da7b37ba043bce6319d371c270b02acb; main remains 1465cf93735b3e5bff7c1b80e3eec004e16b249c. Seven task files are changed/new and uncommitted, in addition to the eight preserved unrelated paths.

Evidence: local external sms-final/integration-final.txt, standard-regression.txt and results.json in the owner's Codex visualizations directory. They contain synthetic fixture assertions only. No additional live-test guard was created, armed, reset or consumed. No live Semaphore delivery, new handset delivery or newly queried credit balance is claimed.

### Acceptance-review working-tree status (before checkpoint)

```text
 M css/index.css
 M css/login.css
 M includes/sms-core.php
 M index.html
 M login.html
 M scripts/phase8-isolated-qa.php
 M tests/phase7b-sms.php
 M tests/phase8-workflows.php
?? SMS-FINAL-ACCEPTANCE.md
?? images/school-entrance.png
?? tests/sms-acceptance-install.php
?? tests/sms-acceptance-workflows.php
?? uploads/announcements/cab2b00b4032d7442f091c16d66e5ce7.jpg
?? uploads/books/0b868fc0ef0b5b1e2dba5255a94be14b.webp
?? uploads/books/1e9ecf96dab133c920fd6cbeefb497a6.webp
```

## Approved checkpoint verification

The final local checkpoint reruns the isolated SMS matrix and focused mock/guard/UI tests, checks the staged manifest for private data, and leaves all eight unrelated changes uncommitted. Both providers retain shared notification hooks and templates; provider-specific sending restrictions still apply. The consumed claim is preserved; no live authorization is reset. The Git commit itself records the resulting checkpoint hash.
