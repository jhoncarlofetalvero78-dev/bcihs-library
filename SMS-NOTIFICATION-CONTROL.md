# Administrator SMS notification permission

Implemented 28 October 2026. Prepared for the Phase 10 local checkpoint; not deployed or activated on the genuine database. No live SMS/provider request or credits used.

## Administrator usage

1. Sign in with an existing administrator account and open **SMS Logs** in the sidebar. The page is titled **SMS Logs & Settings**.
2. Review **Allow SMS Notifications**, **Selected provider**, **Effective delivery mode**, and the configuration explanation. A missing setting means OFF / setup required, never an implicit permission to send.
3. Check or uncheck **Allow SMS Notifications**, then choose **Save permission**. Enabling requires a separate confirmation explaining possible paid delivery. Turning OFF does not need a paid-delivery confirmation.
4. Check the saved status. ON with simulation configuration remains **Simulation**. ON cannot supply credentials, change providers, arm a test, reset a claim, or bypass `live_enabled`.
5. Expand **Recent administrator changes** for administrator IDs, previous/new values, revisions, and Philippine timestamps. **Refresh settings** reloads status; **Refresh local logs** reloads saved logs only. Neither polls a provider or resends messages.
6. A conflict means another administrator changed the revision. Refresh and review before another action. A network failure means the status is unknown; the last saved server permission may still apply. The browser never claims that a failed request turned SMS OFF.

Librarians can view current status but cannot edit permission or read the administrator change history. Borrowers and visitors cannot use the settings API. Existing account, inventory, checkout, attendance, and reservation workflows do not depend on SMS success.

## One-time deployment setup — intentionally NOT applied to bcihs_library

Persistent permission and atomic administrator auditing need two small tables: `sms_notification_settings` (one OFF-by-default row with a revision) and `sms_notification_audit` (append-only application history). Existing SMS log schema, provider configuration, and the ten active library tables are unchanged.

The reviewed SQL is `sql/sms-notification-settings.sql`. A deployment custodian should take a private current backup, inspect the target database, and apply that SQL deliberately during an approved maintenance step. It was applied only to owned schema-only disposable QA databases in this task. It does not run from a web request or on application startup. It deliberately does not overwrite existing tables or settings; investigate a partial/previous installation before rerunning it. Use a separate migration account for DDL; the application needs SELECT/UPDATE on the setting and SELECT/INSERT on audit, not routine DDL or audit deletion rights.

Until setup, the actual application displays OFF / setup required and centrally suppresses notifications. No active database migration or setting change has been performed. This is commit/review-ready source, not an activated setting on the school's database.

Provider switching remains private configuration only. `simulation`, `unisms`, and `semaphore` retain the existing shared engine/events/templates/log projection. Keep `mode=simulate` and `live_enabled=false` during setup and review. Deployment credentials, sender configuration and live authorization remain outside Git and outside this UI. Do not enable live delivery as part of installing this feature.

## Central enforcement and concurrency

`send_sms()` obtains a database-scoped MySQL named lock before reading permission and holds it through duplicate reservation, dispatch and final log update. Settings changes obtain the same lock, then update the locked setting row and insert its audit record in one InnoDB transaction. A submitted revision must match the current revision; stale competing changes get HTTP 409. Invalid values, missing CSRF and unauthorized roles are rejected server-side. An audit insertion failure rolls back the setting update.

This named lock is a database mutex: only one participating submission/settings change proceeds at a time. It is deliberately simple for this school's single-primary MAMP/XAMPP environment. A busy operation returns an error rather than sending without a lock. An OFF operation waits for an already admitted transport; once OFF succeeds, subsequent submissions see OFF. A message already submitted to a provider cannot be recalled. Network/database process loss can leave an uncertain provider outcome; the existing reserved SMS log and consumed UniSMS claim still prevent automatic retry. No fallback or automatic retry is added.

A missing, malformed or unreadable row defaults OFF, including simulation. Normal OFF events receive `skipped / notifications_disabled / request_attempted=false` metadata; missing storage uses `notification_permission_unavailable`. Suppressed log phone values are not retained. Password codes stay redacted. The existing stable event key is retained so the same event cannot be replayed after re-enabling. There is no deferred notification queue or backlog processor. If log storage itself fails, sending fails closed; no attempt is made without a saved reservation.

All application SMS uses the existing `send_sms()` boundary. The pure adapter functions remain testable, but must not be called directly by new business modules. Scripts or external processes that bypass this boundary cannot honor the setting. Multiple application servers are supported only if they use the same primary database/lock namespace and central engine; replica reads, multiple primaries and split routing need a separately reviewed distributed coordination design. Timeouts are bounded, but globally serialized submissions are intended for modest school traffic, not bulk marketing.

## Password recovery

The recovery endpoint checks permission and effective delivery readiness before generating a code, updating account recovery fields or storing a session challenge. OFF, simulation, invalid configuration and the existing approval-only UniSMS guard return an understandable unavailable response. If permission changes during preparation, the central send gate blocks transport; the newly created challenge is invalidated and its session challenge removed. An uncertain/failed submission does not invite automatic retries.

The legitimate existing alternative is to contact library staff / the authorized system custodian for identity-verified assistance; administrator accounts already use that advice. There is no implemented email or staff self-service password override, and this feature adds none. Do not treat knowledge of a phone number, administrator SMS permission, or a failed notification as proof of identity.

The UniSMS adapter still supports the shared templates, but the current live-test guard intentionally permits only its separately authorized one-attempt account approval. It therefore cannot deliver password recovery in the current configuration. The consumed student #364 claim is not reusable. Semaphore recovery requires independently authorized private live configuration. This setting does not relax either provider's restrictions.

## Verification

- **755 isolated workflow checks passed**, using schema-only owned databases and file-only mock transports. Covered every OFF event across all three providers; actual account, borrowing, checkout/rejection and reservation transitions while OFF; ON simulation/mocked live dispatch; role/CSRF denial; missing/corrupt schema/row; failed audit rollback; competing setting revisions; simultaneous duplicate submits; in-flight send versus OFF; no replay; unavailable recovery; real mocked Semaphore recovery; existing QR, users/XSS, reports, attendance, and stock/authorization workflows.
- **315 PHP SMS checks passed**: 93 shared simulated/mock + 105 additional UniSMS + 117 persistent one-attempt guard checks. cURL entry points were disabled in these suites. The isolated connected matrix replaces transport in the disposable source copy and tests the original guard independently; the production guard source is unchanged.
- **40 new SMS settings JavaScript checks** and **45 SMS Logs JavaScript checks passed**, including role presentation, explicit confirmation/cancel, CSRF/revision payload, repeated submits, missing storage, request errors/unknown status, and inert audit rendering.
- Safe JavaScript regressions: borrowing 52, QR 53, reservations 49, attendance 37, reports 52, users/announcements XSS 192, shared UI/security 162; Manage Books removal/search/CSV checks passed.
- Database-free security 28, statistical calculation/Excel 38, local Apache resource/security 9 passed. PHP/JavaScript syntax and Git whitespace checks passed; exact counts and commands/results are recorded with the private QA evidence.
- Chrome isolated browser checks: administrator OFF/save, explicit ON warning/confirmation, ON remains simulation, librarian view-only, no document overflow at 1440/768/390px. Temporary viewport overrides reset and owned browser tabs closed.
- All ten active table fingerprints, student #364, SMS log #265, SMS private configuration, consumed claim, private backup and all pre-existing photo/upload/UI changes remain unchanged. All owned disposable QA databases were cleaned up. No live SMS, no additional credits, no remote operation or main change; local checkpoint only.

Private evidence: `/Users/macbookprom116512/.codex/visualizations/2026/10/28/sms-notification-control/`. It is outside the application web root and is not included in the source manifest.

## Implementation manifest

```text
backend/forgot-password-request.php
backend/sms-logs-data.php
backend/sms-settings.php
css/sms-logs.css
forgot-password.html
includes/sms.php
includes/sms-permission.php
js/sms-logs.js
js/sms-settings.js
scripts/phase8-isolated-qa.php
sms-logs.html
sql/sms-notification-settings.sql
tests/phase7b-sms.php
tests/phase8-workflows.php
tests/sms-acceptance-install.php
tests/sms-acceptance-workflows.php
tests/sms-permission-workflows.php
tests/sms-settings.js
SMS-NOTIFICATION-CONTROL.md
```

The existing uncommitted `css/phase9.css` visual refinements and eight original homepage/login/photo/upload customizations are excluded from this feature and were preserved byte-for-byte from this task's starting snapshot.

## Remaining operational limits

No provider-status polling or delivery receipts were added. Refresh remains local-only; API acceptance, provider Sent, and actual handset delivery remain distinct. Quotas, sender approval, number verification/ported-network checks, billing/segment costs, school credentials and production acceptance remain provider/deployment responsibilities. No scheduled reminder events or bulk sending were introduced. The local checkpoint does not authorize active-database setup; authorize that separately.

## Phase 10 final review and later activation

The reviewed checkpoint contains exactly the 19 files above. Separate uncommitted Phase 9 photo, tint, hero, sidebar and dashboard-spacing refinements remain excluded. The SMS page now inherits its account text color so names remain readable with the committed white Phase 9 header independently of those refinements.

Final safety review added an audit-table readability check to the central permission read: an incomplete/missing audit dependency blocks submission and setting changes. The isolated suite now also forces an actual audit INSERT failure with a disposable-only trigger, proving the preceding permission UPDATE and revision are rolled back. The schema SQL creates only the two intended InnoDB tables, starts OFF, and contains no changes to existing tables. Partial installation never defaults to ON. Supported deployment targets are PHP 8.3 with MySQL 8 / current compatible MariaDB, PDO MySQL, InnoDB and database-scoped GET_LOCK support. CHECK constraints are supplementary; server-side boolean/revision validation remains required.

The final staged application source was exported to a private review copy outside the web root and tested independently from the owner's uncommitted changes. It passed 755 isolated workflow checks, 315 SMS PHP mock/guard checks, 40 Settings JS checks, 45 SMS Logs JS checks, all listed safe regressions, and syntax checks for 107 PHP / 45 JavaScript files. Existing responsive browser evidence was supplemented with the exact staged source. No genuine records or schema were changed.

Later activation requires a separate explicit authorization:

1. Stop local editing/workflow activity briefly, verify the target is the local bcihs_library database, and create a fresh private current backup plus ten-table row/schema fingerprints.
2. Confirm private SMS mode remains simulate and live_enabled remains false. Keep the consumed student #364 claim unchanged; do not arm any live test.
3. With the authorized migration account, inspect and run sql/sms-notification-settings.sql once. If a table/row already exists or installation is partial, stop and inspect rather than overwrite or rerun blindly.
4. Verify only the two new tables were added, the singleton is OFF at revision zero, audit is empty, and all original table rows/settings still match. Confirm SMS Logs & Settings reports Disabled with setup complete.
5. Review administrator/librarian access and CSRF handling. If separately approved, enable notification permission through the administrator confirmation while retaining simulation; confirm effective mode remains Simulation and its audit entry is saved. Do not replay old logs or create unapproved active fixtures.
6. Keep production live activation separate. Semaphore requires private credentials, the school-approved sender/account, funded credits, verified recipients/billing and separately authorized acceptance. Provider selection remains private configuration only. UniSMS remains subject to its existing account-approval-only one-attempt guard; the consumed claim cannot be reset for activation. Check provider outcomes before any separately authorized manual investigation; never fall back automatically.
