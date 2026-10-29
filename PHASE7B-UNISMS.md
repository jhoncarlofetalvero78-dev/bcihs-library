# Phase 7B SMS provider integration

Current review: Phase 7B.3, 2026-10-28. Base commit `2bd8351`. See [PHASE7B-HARDENING.md](PHASE7B-HARDENING.md) for the final manifest and verification; [PHASE7B-LIVE-GUARD.md](PHASE7B-LIVE-GUARD.md) explains the acceptance guard.

## Shared behavior

The private configuration selects `simulation`, `unisms`, or `semaphore`. Defaults are simulation, mode simulate, live_enabled false. The providers share these existing events and templates:

| Event | Existing trigger |
| --- | --- |
| account_approved | Staff accepts a pending/reconsidered account |
| borrow_ready | Borrow request approved and held for pickup |
| borrow_rejected | Borrow request rejected |
| reservation_ready | Reservation is ready for pickup |
| checkout | Registered borrowing checkout or reservation handover; separate source keys |
| password_reset | Existing recovery request; code redacted from staff logs |

Registration submission, account rejection SMS, anonymous walk-in SMS, returns, cancellations, reminders and broadcasts gain no new send trigger. Unsupported reminder/broadcast template checks remain in place. There is no scheduler. Library permissions, inventory, QR, due-date and reservation policies are unchanged.

Each post-commit notification keeps the same event/source/record hash and database advisory lock. The log reservation precedes dispatch. Existing, simulated, skipped, failed and uncertain events cannot automatically be resent when a provider changes. No fallback, automatic retry, bulk operation or historical replay is added. Provider failure cannot roll back a committed library transaction.

The templates are shared, with normalized titles limited to 45 characters and actual stored dates formatted in Philippine time. UniSMS student-sender restrictions are additional delivery preflight, not different library events. Only the separately authorized account-approval acceptance event may currently submit live through UniSMS; other events remain testable in simulation. Expanding this temporary live policy requires review. Normal Semaphore deployment is not subject to the UniSMS acceptance guard when live_test is null.

## Private setup and turnover

Copy includes/sms-local.example.php to Git-ignored includes/sms-local.php, preserving any existing file. Actual secrets and recipient mappings must never be put in the tracked example, browser code, chat, screenshots or logs.

- `provider`: simulation, unisms or semaphore. Unknown names fail closed.
- `mode`: simulate by default. Real sending requires live and strict boolean live_enabled true.
- `unisms_secret`: actual value only in the ignored local configuration. Installing it cannot enable sending.
- `unisms_verified_networks`: domestic-format recipient keys mapped to owner-verified globe or dito. No carrier inference from prefixes.
- `live_test`: null by default. UniSMS live acceptance requires the exact short-lived scope in the guard guide.
- `sender`: Semaphore's approved sender name. The current UniSMS student adapter uses fixed Unisoft.

BCIHS_SMS_PROVIDER, BCIHS_SMS_MODE, BCIHS_SMS_LIVE_ENABLED and the existing SEMAPHORE_SENDER_NAME environment overrides take priority. An environment-forced live mode/gate is incompatible with the acceptance guard's required safe file reset and therefore blocks the UniSMS request. Apply acceptance settings through the private file, with no conflicting web-runtime overrides. CLI environment and Apache environment are not necessarily identical.

Semaphore retains existing SEMAPHORE_API_KEY/server configuration and ignored semaphore-api-key.php loading, only behind live gates. For school turnover, disable live mode first, review unresolved UniSMS references, set private provider to semaphore, set live_test null, and install the school's authorized key/sender. Verify simulation before any separately approved deployment test. No library code, schema, policy or historical record changes are required. Never use turnover to replay an uncertain event. Remove/rotate unused development credentials through the owner's approved process.

## UniSMS restrictions and response handling

The [official SMS documentation](https://unismsapi.com/docs/sms) and [student guide](https://unismsapi.com/docs/students) informed the adapter. It uses HTTPS POST /api/sms, Basic authentication with the secret as username and empty password, and JSON recipient/content/sender_id. The default sender Unisoft supports Globe/DITO, excludes Smart/TNT/Sun and prohibits links. The application conservatively checks a privately verified recipient map and blocks links, including links supplied in a book title. It applies a local 160-character maximum; Unicode in general templates may require more than one billed segment. Semaphore does not inherit these restrictions.

UniSMS responses require a matching recipient, a safe msg_ reference, and pending/sent/retrying/failed. Unknown JSON/status/recipient, timeout or HTTP 5xx remains uncertain. Rejections receive canonical safe reasons. Credit/network error text is classified only when recognizable; unknown validation text remains generic. Some error-body/HTTP-credit cases are defensive mocks, not a claim of a documented complete error contract.

New log JSON retains provider, attempted flag, safe HTTP status, reference, canonical state/reason and the existing event hash. SQL enum compatibility remains unchanged; metadata is authoritative. Staff output masks phone numbers, redacts OTPs and omits credentials/raw bodies/account details. Numeric Semaphore message IDs and validated UniSMS references are safe staff-visible references. The private database retains destination numbers for existing operational records; no new public phone storage is introduced.

**Refresh local logs reloads BCIHS records only. It does not poll either provider.** A locally pending submission can later be Sent on the provider dashboard without the local record changing. No Delivered state is invented. Provider-reported sent and user-confirmed handset receipt are distinct evidence. Status polling or authenticated webhooks remain future work.

## Verification boundaries

A separately authorized one-message acceptance succeeded before this hardening review: API acceptance, later provider Sent, handset confirmation and a single billed credit were checked. Private fixture IDs, recipient, provider reference, balance and raw records are retained outside Git. No new provider request or SMS is authorized by this document.

This review uses database-free fakes/mocks and localhost read-only browser pages. The guard guide covers restored-before-transport behavior and termination tests. Provider-specific network/account/credential/billing compatibility is not proved by mocks for every notification type. Database-writing regression suites are not run on legitimate records: Reservations retains its future-reservation safety refusal and Attendance its historical-data isolation assumption. These are not relabelled as new API passes.
