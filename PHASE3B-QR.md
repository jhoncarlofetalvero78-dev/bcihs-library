# Phase 3B — Approved QR-Based Borrowing Verification

Implemented locally on `development` after Phase 7A commit `1ac404514f0b9d6be665bf34235eadd9229907b9`. The user authorized the final checkpoint with the database-preserving acceptance conditions below. No push, merge, deployment, `main` change, attendance QR, category QR, official report template, or SMS enhancement was performed.

## Request-based pickup flow

1. The existing portal creates a `pending` borrowing request. It has no pickup QR.
2. Existing staff approval holds stock and changes the request to `awaiting_pickup`. The same transaction creates a random, 256-bit pickup reference if it does not already have one.
3. The request owner opens **My Profile → Borrowing History → Approved requests ready for pickup → Show pickup QR**. The owner-only, CSRF-protected API rechecks current approval and request status before returning the URL. Requests approved before installation receive a reference on this first authorized POST.
4. The bundled generator draws the QR locally on a canvas, including a four-module white border. No QR payload is sent to an online QR provider or CDN. The link contains only an opaque reference, not student names, IDs, book titles, or a predictable request ID.
5. A librarian scans it with an ordinary phone camera and opens the web address. No scanner application or browser camera library is required. Signed-out staff are sent to staff login; successful login returns to the exact pickup page. Only a validated token and a fixed internal destination are supported, preventing arbitrary return URLs.
6. The staff-only API displays the borrower, available identifier, year/section, book, author, call number, request/approval times, account approval, and current loan status. Opening or refreshing this page never checks out a book.
7. Staff click **Confirm Checkout**, verify physical handover in the in-page confirmation, and explicitly confirm. The page POSTs the request ID, matching token, and session CSRF token to the existing `backend/borrow-request-action.php` checkout action.
8. That original operation retains its book-first transaction locks, identity/status checks, stock constraints, actual checkout timestamp, 14-day due date, inventory update, and reporting integration. No second loan, checkout endpoint, stock formula, or transaction record is created.
9. Checked-out, returned, rejected, or otherwise ineligible requests cannot be checked out by the QR. Used codes stop being offered in the owner list. A stale displayed code is cleared when rechecked, and server validation rejects it immediately regardless of a browser reload. Repeated/concurrent confirmations cannot allocate another copy.

The token stays consistent across reloads while the application base address stays the same. There is no arbitrary expiry, scheduled job, or token rotation workflow. A changed LAN address requires refreshing the displayed URL, not regenerating the token. Staff may view the current status of a used reference, but cannot check it out again. Possession of a QR never substitutes for staff authentication, CSRF, or physical identity checks.

Existing manual checkout and Confirm Return remain available. Anonymous walk-in loans and reservations are not given this new student-request pickup workflow. The former Manage Books catalog QR button and modal were removed at the user’s request; request-specific pickup verification is the supported QR workflow.

## Implementation notes for student developers

- `bin2hex(random_bytes(32))` produces a hard-to-guess 64-character reference. A nullable unique database index prevents duplicate references. Storing the reference permits showing the same QR later; it is a lookup reference, not a login credential.
- Approval generates it in the existing transaction. The owner issuance endpoint uses a row lock to make lazy generation for older awaiting-pickup requests consistent under repeated clicks. Both paths leave the request's borrowing dates and inventory rules intact.
- Verification is a read-only GET and requires a current accepted librarian or real administrator database session. Owner APIs check the session's user ID in the SQL predicate. Invalid references return an error without falling back to another request.
- The checkout endpoint checks an optional supplied QR token against the locked request before performing its original checkout operation. Omitting the token remains the existing authenticated manual checkout fallback; it does not grant any extra permission.
- New details, messages, labels, and links use `textContent`, `setAttribute`, and DOM node creation. User-controlled values are not concatenated into HTML. A token is exactly 64 lowercase hexadecimal characters on both client and server; trailing newlines and external return URLs are rejected.
- A pending checkout disables repeat clicks. After success, conflict, or an interrupted response, the page reloads current status before offering another action. The student QR button also rechecks eligibility whenever used.
- The pickup page sets a no-referrer policy and the APIs return `Cache-Control: no-store`. Public base URLs come from explicit configuration, not untrusted Host headers.

### Local QR library

The only added runtime library is the self-contained, MIT-licensed [Project Nayuki QR generator](https://www.nayuki.io/page/qr-code-generator-library), bundled under `js/vendor/`. Its license, upstream download address, and exact SHA-256 are preserved in `js/vendor/README.md`. No framework, package installation, online QR service, or CDN is required by the new workflow. Custom code is outside the vendor file.

## Additive migration and database safety

The target was confirmed as local MAMP `bcihs_library` through the existing CLI guard. Before any schema change, a private full backup and row fingerprint manifest were created outside Git and the MAMP web root, mode `0600`:

`/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase3b-qr-20261027-135617-6b721d.sql`

The sole migration in `sql/pickup-qr-migration.sql` adds:

- Nullable `borrow_requests.pickup_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin`.
- Unique index `uq_borrow_pickup_token`.

No existing row is backfilled or reclassified; original requests initially keep `NULL` tokens. Existing dates, statuses, foreign keys, users, books, reservations, attendance, announcements, SMS logs, and policy settings are preserved. The fresh-install schema is updated separately in `sql/schema (2).sql`.

Apply to another configured local MAMP database only after a private backup:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/migrate-pickup-qr.php PRIVATE_BACKUP_DIRECTORY /Applications/MAMP/Library/bin/mysql80/bin/mysqldump
```

The helper backs up before ALTER, checks original values with the new field excluded, and rejects unexpected existing token/index structure. A second run was tested: it reports already applied and changes nothing. Do not run the fresh-install SQL against an existing installation, reset tables, or reimport a backup as a routine installation step.

For application rollback, restore the previous application checkpoint only after review and leave the additive field/index unused. No database restoration or destructive column removal is needed to retain the prior manual workflow. Removing the new field later would invalidate issued references and requires separate review.

Tests used their own prefixed records, blank borrower phone numbers, and private backups. Browser fixtures were removed and their private credential manifest deleted. Final comparison of all 10 tables against the pre-migration fingerprints, excluding only the added column, confirmed every original row and setting remained unchanged; original requests still have NULL tokens. Auto-increment counters may advance during disposable tests. No real SMS was sent. Existing approval/rejection SMS behavior is preserved; QR display, verification, and checkout introduce no SMS calls or credential changes.

## Configurable cellphone URL

Set `BCIHS_PUBLIC_BASE_URL` either in the PHP/Apache environment or in the Git-ignored `includes/pickup-local.php` (environment takes priority). Copy the tracked example and edit the private file:

```php
<?php
return ['BCIHS_PUBLIC_BASE_URL' => 'http://YOUR_MAC_LAN_IP:8888/bcihs-library'];
```

Replace the placeholder with the Mac's active Wi-Fi/LAN IPv4 address. Do not type a filesystem path, query string, credentials, or a verification-page filename. Use the application base path without the final slash. A school HTTPS base URL is supported later, for example `https://library.example.edu/library`; no deployment was performed.

For this local test, the detected LAN address was configured only in the ignored private file, mode `0600`. It is not hardcoded in source. A missing/invalid configuration gives a clear setup error and preserves manual checkout. A configured localhost URL displays a warning because a separate phone cannot reach the Mac through localhost.

### Physical phone test procedure (subsequently confirmed passed by user)

1. Start MAMP Apache/MySQL and verify the current LAN address in macOS network settings. Update the private base URL if DHCP changed it.
2. Connect the Mac and librarian phone to the same trusted Wi-Fi/LAN. Guest-network client isolation, a firewall, or a network policy may block access.
3. First type `http://YOUR_MAC_LAN_IP:8888/bcihs-library/admin-login.html` in the phone browser. It must open from the phone. Do not use `localhost` or `127.0.0.1` there. If it does not open, resolve Apache listening, permitted inbound connections, or Wi-Fi isolation before testing QR scanning.
4. Use disposable accounts for local HTTP testing. Use the proper school HTTPS address for real deployment credentials; that deployment is outside this phase.
5. Approve a disposable student's request, show its QR in the student's portal, and scan it with the phone's normal Camera application. Open the detected link. Use a clear, bright display and keep the entire white QR border visible.
6. Sign in as approved library staff on the phone. Confirm that it returns to the matching request, then verify identity and hand over the test book before confirming checkout.
7. Rescan or refresh. The page must show checked out, its actual handover/due times, and no active checkout action. Return the book through the existing module.

Actual testing reached the configured LAN URL from this computer via HTTP and an in-app browser. This confirms local address routing and page access, not access from a second physical device. **At the initial automated review, no physical cellphone camera scan or second-device LAN test was claimed. The user subsequently explicitly confirmed the complete physical cellphone workflow passed; see final acceptance below.**

## Automated verification

The executed automated suites pass. The final Reservations API rerun is intentionally blocked by legitimate future reservations; historical successful results are recorded separately below.

| Suite | PHP/API workflow checks | JavaScript checks |
| --- | ---: | ---: |
| Phase 3B Pickup QR | 101 | 50 |
| Phase 2A Books | 64 | 4 test groups |
| Phase 2B Borrowing | 164 | 50 |
| Phase 4 Reservations | Not rerun — safety guard (170 previously recorded) | 48 |
| Phase 5 Attendance | 153 historically; latest historical-date assertion did not pass (see limitation) | 37 |
| Phase 6A Reports/Dashboard | 154 | 52 |
| Phase 7A Users/Announcements | 151 | 187 |

Counts include fixture/state assertions; the 101 Phase 3B checks include seven base-URL configuration cases. Reservations counts can vary with per-row concurrency-history assertions; the latest regression run passed 170.

Phase 3B covers pending/ineligible requests, owner-only visibility, stable and unique references, anonymous and nonstaff rejection, CSRF, wrong request/token pairs, current account approval, invalid QR values, read-only scans, actual handover/14-day due dates, inventory changing once, repeated/concurrent checkout, student history, Transactions, Dashboard/Reports/catalog agreement, rejected/returned/used references, older approvals, manual checkout/return, safe staff-login return, stale librarian roles, no sent fixture SMS, and final original-row verification.

The 50 JavaScript assertions are a **DOM harness, not browser automation**. They check safe text rendering, read-only initial fetch, confirmation/cancel, CSRF and token payloads, busy guards, used-status refresh, conflict/network/authorization states, exact token length, owner issuance, local canvas rendering, no online QR requests, student empty state, safe login destinations, and referenced HTML controls.

Commands:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase3b-pickup-qr.php
node tests/phase3b-pickup-qr.js
```

All 10 changed/new PHP files and all 6 changed/new JavaScript files passed syntax checking. Git working-tree and cached whitespace checks passed; the index remains empty. The vendor library was checked for runtime network/eval/DOM dependencies; none were found.

## Actual browser and QR-decoding evidence

Disposable local student and staff sessions exercised the actual MAMP endpoints and pages, not mocked responses:

- Student opened Profile → Borrowing History and displayed the correct request QR through the owner-only API.
- Signed-out staff opened the pickup URL, reached staff login, and returned to the exact request after successful sign-in.
- Correct student, book, identifiers, status, and timestamps appeared. Confirm Checkout opened the handover confirmation; cancelling left the loan unchanged. Explicit handover confirmation checked out one copy and displayed the actual checkout and 14-day due date.
- Rechecking the used QR did not offer another checkout. The student's previous QR was cleared on recheck; reloading the portal showed one checked-out history row and no eligible pickup request.
- An invalid token showed a clear error without borrower details.
- Both student QR and staff verification pages were checked at actual **1440px, 768px, and 390px CSS widths**, with document width matching each viewport. The QR remained 285px wide in these fixtures. An initial nonstandard zoom/tab-sizing observation was discarded; final captures use the active page at the verified CSS width.
- macOS Vision independently decoded the QR from the actual full browser screenshot to the exact configured LAN verification URL. The full 390px mobile-layout screenshot decoded to the same URL. This is automated image decoding, not proof of physical cellphone scanning.
- Temporary viewport overrides were reset, agent-created tabs closed, and all browser fixtures removed. The user's original Reports tab/session was preserved.

Screenshots are outside Git:

`/Users/macbookprom116512/.codex/visualizations/2026/10/27/01a17b79-01f6-7ae3-94c2-cbc0da50c2d8/phase3b/`

Files: `staff-1440.png`, `staff-768.png`, `staff-390.png`, `student-1440.png`, `student-768.png`, `student-390.png`, `student-qr-full.png`, `staff-confirmation-390.png`, `staff-checked-out-390.png`, `student-after-checkout.png`, and `invalid-qr-390.png`. They show disposable records that were subsequently removed.

## Manual capstone demonstration

1. Explain that the pickup QR identifies one approved borrowing request. Manage Books no longer offers a separate catalog QR button.
2. Register/approve a disposable student account, sign in, and request an available book. Confirm the request is Pending and has no pickup QR.
3. Approve it through Borrow Requests. Explain that this holds a copy but does not start the loan.
4. Open that student's Profile → Borrowing History and show the pickup QR. Reload/reopen it to demonstrate stability. Another student must not obtain it.
5. Scan from a librarian phone using the LAN instructions above. Show the login return and matching borrower/book details. Explain that scanning alone changes nothing.
6. Click Confirm Checkout, show Cancel once, then physically hand over the demonstration book and explicitly confirm.
7. Show Checked Out and the due date 14 days after the actual handover. Verify inventory, Transactions, student history, Dashboard, and Reports agree.
8. Refresh/rescan and show that the used QR cannot issue another copy. Demonstrate an invalid QR error without disclosing another student.
9. Use the original Confirm Return operation. If the phone/network is unavailable, demonstrate the original manual checkout fallback; no QR-only dependency was added.
10. Remove only your own disposable demonstration records under the project's fixture workflow and verify original records afterward. Do not delete real library history.

## Remaining limitations

- Physical scanning, staff authentication, and verification are user-confirmed. The user also reported the complete checkout/repeat-scan workflow passed; independent evidence cannot attribute an existing checkout to a phone. Request 223 remained awaiting pickup at cleanup. Existing genuine requests with checkout timestamps corroborate completed checkout operations but do not identify the device. See the final evidence scope below.
- The private LAN address must be updated if it changes; QR URLs are not automatic public hosting or a network tunnel. MAMP/network configuration and future HTTPS hosting remain environment responsibilities.
- Existing profile pages update on reload; no live cross-device push notification was added. A stale image can still be photographed, but server status immediately invalidates checkout and the image clears on recheck.
- QR verification is for borrowing requests only, not reservations, attendance, catalog categories, or anonymous walk-ins. Manual checkout remains available.
- Existing client decisions on pickup expiry, quotas, holidays, record retention, staff identity verification, SMS delivery reconciliation, and official report templates remain outside this phase. No new expiry or policy was invented.

## Exact Phase 3B file manifest

12 modified files:

```text
.gitignore
admin-login.html
backend/borrow-request-action.php
backend/login-process.php
css/manage-books.css
js/admin-login.js
js/manage-books.js
js/profile.js
manage-books.html
profile.html
sql/schema (2).sql
tests/phase2a-books.js
```

17 new files:

```text
PHASE3B-QR.md
backend/my-pickup-requests-data.php
backend/pickup-qr-data.php
backend/pickup-verification-data.php
css/pickup-qr.css
includes/pickup-local.example.php
includes/pickup-qr.php
js/pickup-qr.js
js/pickup-verification.js
js/vendor/README.md
js/vendor/qrcodegen.js
pickup-verification.html
scripts/migrate-pickup-qr.php
sql/pickup-qr-migration.sql
tests/phase3b-browser-fixtures.php
tests/phase3b-pickup-qr.js
tests/phase3b-pickup-qr.php
```

## Phone-test preparation snapshot (before subsequent acceptance and cleanup)

A final readiness review was performed on 2026-10-27 after the implementation tests above. The existing 24-file manifest is unchanged; no working behavior was rewritten. MAMP is listening on port 8888 on a wildcard interface. The active LAN address matches the ignored private QR base configuration, and the staff login URL returned HTTP 200 from the Mac. The actual address and test credentials are provided only in the private access guide and the user-facing test instructions, not hardcoded into application source.

The 101 Phase 3B API/configuration checks, 41 QR JavaScript checks, and 187 Phase 7A announcement/XSS checks passed again. The Phase 7A escaping fix and negative-control regression test are unchanged from the committed checkpoint. PHP/JS syntax, working-tree/cached whitespace, new-file whitespace, photo hashes, and the exact 24-file scope were checked again. Previously recorded full regression and responsive-browser results remain valid; no functional source changed during this readiness review. The migration helper again reported already applied with no changes. Before creating the fresh manual fixtures, all 10 original table fingerprints matched the pre-migration baseline after excluding only the additive column.

A **new disposable manual-test fixture remains active intentionally** so the user can perform the outstanding physical-phone test. It consists of one approved student, one authorized staff administrator, one single-copy test book, and one approved request awaiting pickup. The fixture student's phone is blank. Creating the fixture did not send SMS. A read-only comparison excluding exactly those four IDs verified all original records/settings and SMS logs still match the saved baseline. Opening and displaying its QR left it awaiting pickup with zero borrowed copies. The live on-screen QR was independently decoded from its screenshot to the configured LAN URL; this still does not constitute a physical-phone pass.

The live QR is open in a dedicated student browser tab. Its screenshot is `phone-test-ready.png` in the evidence directory above. It is valid only while the disposable request remains awaiting pickup and the Mac keeps the configured LAN address.

Private, mode-0600 access guide (outside Git/web root):

`/Users/macbookprom116512/.codex/backups/bcihs-library/phase3b-phone-test-access.txt`

Private fixture identity/baseline manifest (also contains disposable credentials; never commit or paste it into chat):

`/Users/macbookprom116512/.codex/backups/bcihs-library/phase3b-browser-fixtures.json`

Backup taken before the manual fixtures:

`/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase3b-ui-20261027-222820-29f33c.sql`

The user should scan the displayed approved-request QR while signed out on the phone, log in with the dedicated staff credentials, verify the exact fixture details in the access guide, confirm the simulated handover once, then rescan/refresh to verify that checkout cannot repeat. Expected stock for this single-copy book changes from borrowed 0/held 1 to borrowed 1/held 0; the due date must be exactly 14 days after handover. Reloading the student's history, Transactions, Dashboard, and Reports should agree. Test only the prefixed fixture, not real library records. The existing staff administrator is a supported authorized role; an approved librarian uses the same protected verification/checkout path.

Keep the fixture until the user reports the test result. Do not run competing fixture suites, delete it, or reset its status while the manual test is in progress. After acceptance, first inspect the recorded outcome and repeat-checkout protection, then clean **only** these declared fixtures using:

```sh
/Applications/MAMP/bin/php/php8.3.30/bin/php tests/phase3b-browser-fixtures.php cleanup /Users/macbookprom116512/.codex/backups/bcihs-library
```

Cleanup checks the fixture identities and all original fingerprints before committing its deletion transaction; on an unexpected original-data change it rolls back and requires review. It removes the fixture manifest. After successful cleanup, remove the private `phase3b-phone-test-access.txt` file and close the test tab. Do not delete history through the production-facing UI or restore a database backup to clean test data.

**Physical cellphone scanning and second-device LAN acceptance remain pending.** No staging or Git checkpoint is authorized until the user explicitly confirms physical-phone success or explicitly accepts committing with this test documented as pending. A screenshot decode or local HTTP success is not that authorization.

## Pickup/history discrepancy review and fix

Read-only inspection of Juan De La Cruz (user 38) found two records for Phase 2B Test Book: request 57 is returned, and request 224 is genuinely `awaiting_pickup`, with NULL checkout/due/return timestamps and an existing pickup token. Request 224 is not a legacy `approved` loan. The history API returned its correct raw status, and the owner-only pickup-list API returned request 224 in Juan's actual authenticated Chrome session. Ownership, server filtering, token generation, and shared status mapping were correct.

The actual browser reproduced the inconsistency: history displayed Approved / Awaiting Pickup while the pickup section retained its previous empty state. `js/profile.js` refreshed borrowing history on visibility return, whereas `js/pickup-qr.js` fetched the pickup list only at initial page load. Approval after initial loading therefore updated the table without updating the QR controls.

Narrow fix: `loadBorrowingHistory()` also refreshes the pickup list, and selecting Borrowing History refreshes history. Pickup-list responses carry a client-side sequence counter so an older empty response cannot overwrite a newer response. An unchanged request-list/CSRF snapshot preserves the existing QR canvas during focus changes, allowing phone scanning without destroying the displayed image. Failed refreshes clear stale controls; retries restore them. Script cache versions were updated. No status mapping, SQL eligibility, approval/checkout rules, dates, stock logic, or legitimate database record was changed.

Browser verification after the fix successfully displayed Juan's Show pickup QR control and generated his request's verification link. Subsequent browser access was disabled in Work settings; that setting was respected. A second browser re-render check of the prepared student was therefore not completed. Read-only inspection confirms prepared request 223 still belongs to its original test student, remains awaiting pickup, retains the original token, and has zero borrowed copies out of one. It and its private manifest/access guide were not cleaned up. Its earlier successful QR display remains available for the manual phone test; no phone success is claimed.

Nine added JavaScript checks reproduce pending-to-approved transitions on visibility return, Borrowing History tab refresh, unchanged displayed-QR preservation, ineligible removal, stale-response ordering, and failure/retry recovery. Latest results: 101 QR API/configuration checks and 50 JavaScript checks pass. Borrowing (164 API/50 JS), Reservations (170 API/48 JS), Attendance (153 API/37 JS), Reports (154 API/52 JS), and Users/Announcements including stored-XSS (151 API/187 JS) regressions all pass.

A private full-row fingerprint taken before this investigation confirmed that users, requests, books/inventory, reservations, attendance, policy settings, administrators, and SMS logs remain unchanged after browser verification and regression cleanup. Announcements changed during the session, alongside a newly referenced untracked image `uploads/announcements/cab2b00b4032d7442f091c16d66e5ce7.jpg`. This is outside the QR fix and was preserved without alteration or deletion. Do not include that image in a Phase 3B checkpoint; review any live user activity separately. The Phase 3B manifest now contains 25 files because `js/profile.js` is an additional verified integration fix. No staging/commit is authorized by this investigation.

## Manage Books catalog QR removal

At the user's explicit request, the redundant per-book catalog QR button and modal were removed. Only `manage-books.html`, `js/manage-books.js`, `css/manage-books.css`, and `tests/phase2a-books.js` changed for this UI removal, plus this documentation. The modal's online QR-service request/download handlers, blob cleanup, and dedicated styles were also removed. The book-editor keyboard handling now targets its single remaining modal; Escape and forward/backward Tab trapping are regression-tested. Script/style cache versions were updated.

The request-specific QR source, student profile/pickup UI, local QR vendor library, staff verification API/page, existing checkout endpoint, and private fixture manifest are byte-for-byte unchanged by the catalog removal. No backend or database migration was needed. Search, filters, pagination, CSV export, Add/Edit/Delete, inventory fields, and book photos are retained. The Phase 2A JavaScript suite now asserts there are no catalog QR controls, modal, or online QR-service calls, while Edit/Delete and editor keyboard controls remain.

Verification passed: Books 64 API checks and 4 JavaScript test groups; request QR 101 API/50 JavaScript checks; Borrowing 164 API/50 JavaScript; Reservations 170 API/48 JavaScript; Reports 154 API/52 JavaScript. JavaScript syntax and Git whitespace checks passed. A full 10-table fingerprint comparison verified no records/settings changed, and hashes verified the request-QR/checkout sources, fixture manifest, and all five photo files were untouched. Actual visual browser reinspection was unavailable because browser use remained disabled; no new visual-pass claim is made.

The prepared request 223 and its existing phone-test access files remain intact; no phone-fixture cleanup or checkout was performed. The unrelated announcement upload and all five homepage/login photo changes are preserved. This removal expands the legitimate Phase 3B manifest by four files, to 29 total. Nothing is staged or committed.

## Final acceptance, current-data comparison, and safe cleanup

On 2026-10-28 (Philippine time), the user explicitly confirmed that the physical cellphone workflow passed. The user also confirmed that intervening book, borrowing, and other database changes were legitimate manual testing and authorized preservation of every non-fixture record. This supersedes the earlier pending-device and keep-fixtures instructions above. Prepared request 223 itself was still awaiting pickup, not checked out, at final inspection; it therefore required no artificial checkout or return. Juan's genuine request 224 is checked out and is preserved.

The original private SQL backup was parsed read-only and compared by primary key with the current database. TIMESTAMP values were compared in UTC to match mysqldump, avoiding false eight-hour differences. No old-baseline rows were deleted. Exact differences from the pre-fixture baseline were:

| Table | Added IDs | Modified existing IDs/fields | Classification/action |
| --- | --- | --- | --- |
| admins | 72 | None | 72 is declared disposable admin; removed |
| users | 288, 340 | None | 288 is declared disposable student; 340 preserved |
| books | 257, 299, 300, 301 | 79: borrowed_copies | 257 is declared disposable book; all others preserved |
| borrow_requests | 223, 224, 266, 267, 268 | None | 223 is declared disposable request; all others preserved |
| announcements | 23 | None | Preserved, including its image |
| attendance | 110 | None | Preserved |
| reservations | 290 | None | Preserved |
| reservation_settings | None | 1: daily_limit | Preserved |
| sms_logs | 194, 238, 239, 240 | None | Preserved as legitimate existing activity; no SMS sent by cleanup |
| attendance_settings | None | None | Preserved |

A complete row snapshot and record-diff report are private, outside the repository/web root, mode 0600. They are not committed and no sensitive field values are reproduced here. The baseline was not overwritten, reimported, or restored.

Fresh full backups of the CURRENT database, including all legitimate changes, were made before deleting anything:

- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase3b-final-current-20261027-170521-8074a3.sql`
- `/Users/macbookprom116512/.codex/backups/bcihs-library/before-phase3b-cleanup-current-20261028-010612-db3c2b.sql`

Backup filenames reflect the process clock; the acceptance/review date here is Philippine local time. Both SQL files and their manifests are private.

The documented fixture cleanup helper was tightened to support legitimate manual activity safely. It takes a fresh current backup, verifies exact student/admin/book/request identities, token correspondence, request state, and zero borrowed copies, then checks every linked foreign-key table. Unexpected dependent records cause a stop rather than cascading deletion. It calculates expected hashes for the CURRENT rows excluding exactly the four declared fixture IDs. Inside one transaction it deletes the request first, then its book and accounts, and compares every remaining table against those expected hashes before commit. It does not attempt to match an outdated setup baseline. Checked-out fixtures require return through the existing workflow first; this fixture needed no return.

Cleanup removed exactly:

- Borrowing request **223**, owned by user 288 for book 257; status awaiting_pickup.
- Book **257**, `PHASE3B-UI-5a7f6f7e Test Book`; zero borrowed copies.
- Student **288**, `PHASE3B-UI-5a7f6f7e Student`.
- Administrator **72**, `PHASE3B-UI-5a7f6f7e-Admin`.

No linked reservations, attendance, SMS logs, announcement authorship, or additional borrow requests existed for those fixture identities. Every non-fixture row and setting matched its current pre-cleanup fingerprint afterward. Juan user 38 and requests 57/224, the existing request 58, all manual additions, reservations, attendance, SMS logs, and both settings rows were preserved exactly. The private fixture manifest and phone-test credential guide were removed; deleted disposable accounts can no longer authenticate, and existing sessions fail the existing database-account check.

A private audit record remains at `/Users/macbookprom116512/.codex/backups/bcihs-library/phase3b-cleanup-verification.json`, containing the removed IDs, fresh backup path, and preserved table counts/hashes, without test passwords or tokens. Retain backups for recovery; they were not used to restore the database.

## Final acceptance review — Reservations guard and device evidence

The final review preserves the Reservations safety refusal. The existing guard in `tests/phase4-reservations.php` checks for waiting/ready reservations with `pickup_at >= CURDATE()` before running quota mutations. Genuine future reservation 290 satisfies the guard. No reservation was deleted, cancelled, rescheduled, or modified. Available database names were inspected read-only; no dedicated isolated BCIHS test database is configured. Unrelated local databases were not accessed or repurposed, and no database was created or replaced. The CLI test guard also requires the configured local `bcihs_library`, so isolation is not readily available without additional setup outside this acceptance task.

**Reservations API: Not rerun — blocked by legitimate future reservations; previous regression results remain recorded.** The last successful historical run passed 170 checks (counts varied with per-row concurrency assertions). The latest refused run completed only the cleanup/preservation assertion and is not called a suite pass. The Reservations JavaScript suite can run without changing the database and passed 48 checks. The user explicitly authorized proceeding with the safe refusal documented.

### Physical cellphone evidence scope

The user explicitly confirmed: “Yes, the physical cellphone workflow passed,” in response to a question naming scanning, staff login return, checkout, and repeat-scan prevention. This is recorded as user-reported acceptance. The agent did not observe the physical device and does not upgrade it to independent camera/phone verification.

Read-only review of current genuine requests shows:

| Request | Owner | Outcome | Actual checkout (Philippine time) | Due | Return |
| --- | --- | --- | --- | --- | --- |
| 224 | Juan, user 38 | checked_out | 2026-10-27 22:57:11 | 2026-11-10 22:57:11 | — |
| 267 | Juan, user 38 | returned | 2026-10-27 23:22:12 | 2026-11-10 23:22:12 | 2026-10-27 23:23:09 |
| 268 | user 340 | checked_out | 2026-10-27 23:41:24 | 2026-11-10 23:41:24 | — |

These requests have request-specific pickup references and recorded checkout timestamps. They establish that checkout was completed on other genuine requests, including Juan's 224. Database rows do not record the initiating browser/device or whether manual or QR checkout was used. Consequently they cannot independently prove physical-phone Confirm Checkout. Disposable 223 remained awaiting pickup with zero borrowed copies and was removed only by the declared fixture cleanup; no checkout on that fixture is claimed.

Final scope: physical scanning, staff authentication, and verification are user-confirmed; full phone checkout and repeat-scan protection are also user-reported, but are not independently established by fixture 223's final state or another device-specific audit trail. Separately, actual MAMP browser checkout on an earlier disposable fixture and automated checkout, due-date, inventory, concurrency, invalid/used QR, and repeat-submission tests passed as documented. All genuine records above were preserved unchanged.

No new browser visual pass is claimed during final acceptance because browser access was disabled at the last attempted verification. Earlier screenshots and the user's acceptance remain clearly distinguished from automated API/DOM tests.

Exact preserved records after cleanup: admins [1]; users [38,340]; books [79,299,300,301]; borrow_requests [57,58,224,266,267,268]; reservations [290]; attendance [110]; announcements [23]; sms_logs [34,194,238,239,240]; attendance_settings [1]; reservation_settings [1]. No non-fixture record was deleted or reset.

## Attendance assertion limitation (recorded; accepted for this checkpoint)

The 2026-10-28 final acceptance rerun passed Books (64 API checks/4 JS groups), QR (101 API/configuration/50 JS), and Borrowing (164 API/50 JS). It then stopped at an actual assertion in the Attendance API suite after 139 completed assertions including its cleanup verification: `historical Philippine date filter accurate`. This is separate from the safely refused Reservations suite.

The test creates one overnight fixture for yesterday and asserts the unfiltered historical date returns exactly one row and one visit. Legitimate attendance 110 has `time_in=2026-10-27 23:29:03` (Philippine time), now yesterday. The endpoint correctly includes legitimate records for that date, so the fixture-only expected total is invalid for the current database. This is a pre-existing test-isolation assumption, not evidence of an incorrect QR implementation; the affected latest regression assertion is recorded as not passed, not as a confirmed application defect. No existing attendance record, application date, policy, or test assertion was changed to force success. Later suites in this sequential run were not run.

The complete current-preserving database fingerprint was verified again after the test's own fixture cleanup, and all non-fixture rows/settings remain identical. Private phone fixtures and access credentials remain removed. No files were staged or committed. The user subsequently explicitly accepted documenting this isolation limitation while retaining the current database and authorized the checkpoint. No database-writing suite was rerun to force success. This is distinct from the Reservations safety guard.

## Final database-preserving acceptance — 2026-10-28

The user explicitly instructed that the current `bcihs_library` database and all legitimate manually added/modified records must remain in use. They authorized the Phase 3B checkpoint with both regression limitations accurately documented. No separate database was created, and no database-writing test, migration, reset, restore, truncate, or additional cleanup was run during this final acceptance turn.

Read-only verification, inside a read-only transaction, compared all 10 current table fingerprints with the current-data preservation report from the completed safe cleanup. Every record and setting matched, including Juan's account/history, manually added books, loans, reservations, attendance, announcements, SMS logs, and policy settings. All four declared disposable fixtures remain removed, and the private fixture manifest and phone-test credential guide are absent. The preserved legitimate SMS logs are not evidence of messages sent during this finalization; no SMS calls were made.

Source review reconfirmed request-specific owner-only QR issuance, configurable non-personal verification URLs, staff-only verification, exact token/CSRF validation in the existing transactional checkout endpoint, unchanged inventory/14-day rules, stale-list refresh protection, and complete removal of Manage Books catalog QR controls. Phase 7A announcement escaping remains unchanged. No actual application defect or unexplained database change remains identified by this review.

Final checks are limited to read-only database verification, PHP/JavaScript syntax, database-free JavaScript harnesses, exact manifest checks, protected-file hashes, secret/artifact exclusion, and Git whitespace checks. Earlier API results are retained as earlier results, not described as newly rerun. The user-reported physical cellphone workflow passed; the independent device-evidence limitation above remains explicit. Reservations API is **not rerun — blocked by legitimate future reservations; previous regression results remain recorded**. Attendance's latest historical-date assertion remains **not passed due to a legitimate-data/test-isolation assumption**, with no confirmed application defect claimed. Neither is falsely marked passed.

Final read-only/syntax verification passed: 10 PHP files and 8 JavaScript files. Newly executed database-free suites passed: Books 4 groups, QR 50 checks, Borrowing 50, Reservations 48, Attendance 37, Reports 52, and Users/Announcements 187 (including the stored-XSS negative control). No PHP/API fixture suite was rerun in this final acceptance turn. Working-tree and staged Git whitespace checks and exact exclusion hashes passed.

The exact source manifest remains 29 files (12 modified and 17 new). Unrelated photo files and three existing runtime uploads are excluded and preserved. Only the approved Phase 3B sources, tests, and documentation may be committed. Stop after the checkpoint; no subsequent phase is authorized.

## Final Git checkpoint safety

Before the authorized checkpoint, branch is `development`, parent HEAD is `1ac404514f0b9d6be665bf34235eadd9229907b9`, and `main` is `1465cf93735b3e5bff7c1b80e3eec004e16b249c`. Only the exact **29-file manifest** above is authorized for staging and commit, including the QR refresh integration, cleanup safeguards, catalog QR removal, tests, and this documentation. No push, merge, deployment, or further phase is authorized.

Preserve and exclude these unrelated files:

```text
css/index.css
css/login.css
index.html
login.html
images/school-entrance.png
uploads/announcements/cab2b00b4032d7442f091c16d66e5ce7.jpg
uploads/books/0b868fc0ef0b5b1e2dba5255a94be14b.webp
uploads/books/1e9ecf96dab133c920fd6cbeefb497a6.webp
```

Private database/QR/Semaphore configuration, database backups, credentials, screenshot/decoder outputs, exports, and runtime uploads are excluded. After the checkpoint, stop for the user's next instruction.
