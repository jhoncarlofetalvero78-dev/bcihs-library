# Phase 7B.2/7B.3 strict one-SMS acceptance guard

The guard remains consumed from the separately approved acceptance, with simulation/live-disabled settings and authorization disabled. No new live authorization is created by this review. Private acceptance evidence stays outside Git.

## Exact authorization

Live UniSMS dispatch requires the existing provider/mode/live/credential/network gates and a private live_test authorization containing strict enabled true, integer Unix issue/expiry times lasting at most 600 seconds, exact account:<student ID> event_id, canonical domestic recipient and an owner-only persistent directory outside the web root. Actual context must be account_approved, with the same selected user ID and immutable account event ID. The recipient must match the actual destination and the private owner-verified Globe/DITO mapping.

The only permitted live acceptance content is:

> BCIHS Library: Your account is approved. You may sign in to the library portal.

It is 79 basic GSM-7 characters/septets and one standard segment. The boundary independently rejects extra text, Unicode, extension characters, controls, links or a length over 160. Recipient and fixed Unisoft sender are separate JSON fields, not appended text. The actual submitted body is tested verbatim.

Missing, invalid, future-dated, expired or mismatched scope fails closed. Simulation does not consume a claim. Semaphore with an explicit live-test scope is rejected rather than used as fallback; normal Semaphore deployment with live_test null remains unchanged. All shared event hooks/templates, database duplicate protection and logs remain intact.

## Persistent atomic claim

One fixed filename, unisms-live-test.claim, lives in the authorized canonical directory. The code requires directory mode 0700/owner-only, ownership by the PHP process user, writable storage, no symlink path and storage outside the web root. The application never creates a new directory or resets the slot automatically.

Exclusive file creation (x+b) decides the winner atomically. A restrictive umask and 0600 claim are used. Timestamp, event hash and message hash are written, flushed and fsynced before transport. No recipient, API secret or provider body enters the claim. Existing, empty, interrupted and symlinked claims are consumed. Any claim-write/fsync failure blocks transport and keeps the claim. Editing the event/expiry cannot select another filename in the same directory.

Ten independent concurrent PHP processes share a ledger in tests: exactly one mock call is permitted, nine are blocked. Fresh processes, repeated HTTP-equivalent calls, PHP exit and forced termination cannot reuse it. A prior MAMP Apache mock-only probe verified actual web-runtime ownership and 0600 persistent claim behavior; no probe endpoint remains in the repository.

## Phase 7B.3: reset before transport

The original detached auxiliary watchdog is retired. Its shell launcher returned success without a worker startup acknowledgement; the worker did not survive/start reliably after the orchestration call completed. The same synthetic worker completed in the foreground with no permission/execution error. The historical empty log cannot establish an exact OS signal, but the invocation/lifetime failure is reproduced. A successful shell background launch is not evidence of a running watchdog.

The central UniSMS adapter now performs these operations in order:

1. Validate scope/message and exclusively consume/fsync the claim.
2. Restore the private configuration to simulate, live_enabled false and live_test.enabled false.
3. Only after that reset succeeds, invoke the single transport with the already captured authorized payload/key.

This removes dependency on shell detachment, polling, shutdown callbacks or background process survival. Global file settings are safe before a network timeout, PHP exit or SIGKILL during transport. The current one request retains its explicitly authorized payload; subsequent requests see disabled settings or the consumed claim. No second provider call occurs.

The reset requires a canonical, owned, 0600 PHP configuration file supplied internally by smsConfig, matching the exact still-armed scope. Operator cancellation or changed scope blocks transport. Environment-enforced live flags are rejected because a private file cannot reset the web server environment. Settings unrelated to mode/live/authorization-enabled are preserved.

A private same-directory temporary PHP file is exclusively created with mode 0600, fully written/flushed/fsynced, then atomically renamed after checking the original file has not changed. PHP extension prevents serving a temporary secret-bearing file as plain text; its filename pattern is Git-ignored. OPcache is invalidated where available. Expected write failures are handled conservatively. Temporary files are removed on normal failure paths.

Reset failure means skipped/live_test_restore_failed, no transport call and a permanently consumed slot. Staff Logs explains this without exposing paths or secrets. A process killed in the tiny claim-to-reset interval, or an unwritable/full filesystem, may leave file settings armed; the persistent claim and expiry still block every submission in that scope. The operator must restore simulation without deleting/rearming the claim. No filesystem design can guarantee config rewriting after SIGKILL or loss of the host volume.

## Private configuration and future test procedure

Tracked defaults remain simulation, live disabled, live_test null. Real keys/mappings/ledger paths are only private. The prepared/consumed real authorization is not altered in this review.

For a future separately authorized test (not authorized here):

1. Back up the then-current database privately, identify exact disposable records and confirm no existing consumed event is being replayed. Do not reuse the completed acceptance event or remove its claim.
2. Review the current carrier, credentials/sender and one-segment budget. The owner must explicitly approve any new budget/scope/directory. Keep simulation throughout preparation.
3. Configure the exact event and canonical recipient, owner-only persistent directory, issue time and expiry of at most ten minutes. Use private file settings, without environment-forced live overrides. No existing claim may be erased to make it pass.
4. Only after the new live authorization, arm that scope and the existing live gates, then submit the one authenticated staff action once. The boundary closes file-based live settings before invoking transport. If reset fails, submit nothing and preserve the consumed slot.
5. Independently confirm simulation/live-disabled/auth-disabled state and retained claim; compare the single reference with the provider dashboard and obtain receipt confirmation separately. A timeout is not permission to retry or switch providers.
6. Preserve private evidence and obtain explicit retention/fixture-cleanup approval. Delete only reviewed disposable records, respecting foreign keys, and compare every non-fixture record against the fresh baseline. Never reset or restore the full database to force a test match.

## Deployment limits

This is a single-server guard on durable local storage. PHP request/process restarts are covered; filesystem rollback/deletion, operator directory replacement, compromised code/admin, hardware power loss/directory-entry durability and multi-server copies are outside the guarantee. Multi-server use requires shared atomic durable storage, such as a reviewed database reservation; no migration is needed here.

Mac/Linux MAMP/XAMPP must provide owner-only permissions, reliable exclusive create, fsync, atomic same-directory rename and web-runtime access to private settings. Windows XAMPP needs an equivalent NTFS ACL/storage review and tests; current POSIX checks may fail closed. Reset storage failure is intentionally no-send. Do not loosen ownership, auth, expiry or persistence checks for a demo.

The application limits API submissions, not a provider's internal retries, billing or handset behavior. UniSMS live is intentionally restricted to the account-approval acceptance policy; other shared events remain testable in simulation. Official Semaphore operation is unaffected without a test scope. No status polling, fabricated Delivered status, automatic retry, fallback, broadcast or scheduler is added.

See PHASE7B-HARDENING.md for current results and the final reviewable source manifest.
