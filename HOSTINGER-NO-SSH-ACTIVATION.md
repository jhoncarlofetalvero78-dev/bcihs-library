# Hosted account rotation without SSH — 29 October 2026

This is an approved private database-administrator alternative to the package's CLI rotation. The hosting application/gate is **unchanged**. No public installer/reset endpoint is needed. All password entry/hashing happens interactively on the owner's Mac; only hashes go into Hostinger's authenticated phpMyAdmin. The owner performs the hosted update and read-back verification before creating/uploading the private completion marker.

This guide does not authorize removing the website's default-deny protection or activating SMS. No hosted changes have been performed by the agent.

## Why this fits the existing design

The package stores passwords using PHP `password_hash(PASSWORD_DEFAULT)` and verifies them with `password_verify()`. Both are supported on the confirmed PHP 8.3 runtime; the algorithm/salt are encoded in the hash. See the official [PHP password_hash reference](https://www.php.net/manual/en/function.password-hash.php). Do not use phpMyAdmin's MD5/SHA1 password transforms or MySQL PASSWORD() functions.

`hostingRequireRotation()` reads `bcihs-private/activation.json` outside public_html and requires the JSON boolean `credentials_rotated` to be true. The gate does not independently verify database hashes or cryptographically authenticate the marker. Therefore a marker is legitimate only **after** the authorized administrator has actually rotated and verified both hosted records. Creating it beforehand would bypass the setup requirement. This procedure replaces the unavailable server CLI with offline PHP hashing + a narrowly scoped database update + explicit read-back verification. It does not weaken/modify the gate.

## 1. Preserve protection and verify scope

- Keep root `Require all denied` active. Use only authenticated hPanel File Manager and phpMyAdmin, over HTTPS. Never put an installer or password-input file in public_html.
- Select **u473523930_bcihs_library** in Hostinger phpMyAdmin, not local MAMP phpMyAdmin.
- Privately export a backup of the new hosted database before updates. Do not share that backup or any password hashes in chat.
- In the hosted SQL tab, run this read-only preflight:

```sql
SELECT DATABASE();
SELECT id,username FROM admins;
SELECT id,student_id,role,account_status FROM users;
SELECT COUNT(*) AS administrator_count FROM admins;
SELECT COUNT(*) AS borrower_count FROM users;
SELECT allow_notifications FROM sms_notification_settings WHERE id=1;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA='u473523930_bcihs_library'
  AND TABLE_NAME IN ('admins','users');
```

Expected: exact target database; one admin with id 1/username admin; one user with id 1/School ID user/student role/accepted status; both tables InnoDB; SMS permission 0. If anything differs, **stop** rather than adapting the SQL by guessing or deleting records. The helper intentionally refuses a broader/different account set.

## 2. Prepare hashes privately on your Mac

Use a password manager to create two different random passwords, 20–72 UTF-8 bytes each, containing uppercase/lowercase letters, digits and punctuation. Prefer long random ASCII passwords for straightforward entry. Keep them in the password manager; do not send them here. Do not reuse either familiar local demo password.

Open Terminal **on your Mac** (Hostinger Terminal is not needed) and run:

```sh
/usr/bin/python3 /Applications/MAMP/htdocs/bcihs-library/scripts/prepare-hosted-passwords.py
```

The helper asks for each new password twice using hidden terminal input. It sends the values to local MAMP PHP through a private process stdin pipe, uses `password_hash(PASSWORD_DEFAULT)` and immediately checks the generated hashes with `password_verify()`. Password values are never placed in command arguments, terminal output, files, application logs, Git or web forms. This is not a claim that ordinary process memory is securely erased; use a trusted Mac and password manager.

The prompt helper now reports each exact failed requirement and lets you retry instead of exiting with a combined error. A rule failure is reported before asking for confirmation; a confirmation mismatch is reported separately. Nothing is trimmed silently. The minimum remains **20 UTF-8 bytes**, not a typical 12- or 16-character minimum. If hidden terminal input is unavailable, it stops rather than falling back to visible password entry. The prompt/privacy fix passed 42 offline synthetic-input checks; activation and SQL update logic are unchanged.

It creates the following **private local folder**, mode 0700, with mode-0600 files:

`/Users/macbookprom116512/.codex/bcihs-hostinger/private-account-rotation/`

- `01-update-hosted-passwords.sql` — hashes only, exact target/account guards, one multi-table InnoDB UPDATE.
- `02-verify-hosted-passwords.sql` — independent read-back query returning booleans only.
- `prepared.json` — preparation time/target only, no passwords or hashes.

No database connection, network request or activation marker is made at this step. The helper refuses to overwrite an existing preparation folder. If you need a fresh attempt, first review/preserve the previous folder and whether its SQL was already applied; do not blindly rerun a completed rotation.

## 3. Apply the private database update in Hostinger

1. Reconfirm phpMyAdmin's selected database is **u473523930_bcihs_library** and the website remains denied.
2. Open `01-update-hosted-passwords.sql` locally in your editor. Copy its full contents into **that hosted database's SQL tab** and execute once. Do not paste it into chat, public documents, public hosting files or local MAMP SQL.
3. The statement explicitly qualifies the hosted target, verifies the selected database and exactly one row in each account table, and matches the expected IDs, identifiers and accepted student role. It updates both password hashes in **one joined InnoDB UPDATE**, or neither if the pair/scope does not match. It clears only that student's reset_code/reset_code_expires, matching the existing CLI procedure. No other columns/tables are targeted. MariaDB documents multi-table UPDATE support in its [UPDATE reference](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/changing-deleting-data/update).
4. An initial successful update normally reports **2** changed accounts. Repeating the same prepared UPDATE can report **0** because both saved hashes and cleared recovery fields already match. A zero count alone proves neither success nor failure. Resolve it using the independent read-only verification query below; do not regenerate credentials or repeatedly rerun the update. If SQL errors or verification fails, leave access denied and do not create the marker.
5. Open `02-verify-hosted-passwords.sql` and run it **separately** in the same target's SQL tab. This avoids relying on the earlier connection/session variables.
6. It must return **exactly one row** with all five columns equal to **1**:
   - correct_target_and_account_counts
   - admin_hash_matches
   - student_hash_matches
   - recovery_fields_clear
   - sms_still_off
7. Any zero, missing row or error means verification is incomplete: stop with the gate and default-deny intact. Do not manually mark the operation complete.

The SQL files contain sensitive password hashes, not plaintext. Hostinger/phpMyAdmin may retain query history according to its policies; keep that account secure and do not export/share query logs. Plaintext never needs to be entered into phpMyAdmin. A dropped connection after submission should be resolved with the **read-only** verification SQL first, rather than blindly sending another update.

## 4. Create and place the completion marker only after verification

After you personally observe the independent verification returning exactly one row with all five values equal to 1 from these same prepared SQL files, run on your Mac. This remains valid if a repeated/no-op update reported changed_accounts=0:

```sh
/usr/bin/python3 /Applications/MAMP/htdocs/bcihs-library/scripts/prepare-hosted-passwords.py --finalize
```

It requires you to type:

`VERIFIED u473523930_bcihs_library`

This is your administrative attestation of those completed hosted checks, not automatic remote verification. Only then does it create private `activation.json` in the local folder above. The contents use the exact existing boolean field plus the actual UTC completion timestamp and an audit method label:

```json
{
  "credentials_rotated": true,
  "rotated_at": "actual UTC timestamp generated at finalization",
  "rotation_method": "offline_hashing_verified_private_database_update"
}
```

Do not manually copy the illustrative timestamp above. Use the generated file. Do not create the marker before the database checks or edit the application to skip it.

In **hPanel File Manager**, navigate to the existing `bcihs-private` folder that is a **sibling of public_html**, beside the privately configured config.php. Do not upload it to public_html/includes or anywhere under public_html.

1. Confirm no activation.json already exists. If one exists, stop and review; do not overwrite a prior activation blindly.
2. Upload the generated file as `activation.pending.json` (outside the web root).
3. Set its permissions to **0600**, owned/readable by the PHP account. Keep bcihs-private at **0700** under the supported account ownership. Do not use 0777 or make it public to solve a read error.
4. Rename it to **activation.json** in that same private directory only after successful upload/permission checking.
5. If any old `sess_*` files exist in **the hosted bcihs-private/sessions directory**, clear only those hosted PHP session files while access remains denied. Do not touch local sessions, backups, provider claims or any database rows. The application's password-fingerprint validation also rejects sessions bound to old passwords.
6. Keep the private config.php and SMS setup unchanged. The hosted package remains credential-free simulation/live-disabled, with database SMS permission OFF.

The private marker contains no password, hash, token or API key. It completes the existing requirement after a verified rotation. No new script is uploaded; the original CLI-only rotate-credentials.php can remain private and is not converted into a web endpoint.

## 5. Access remains blocked until a separate restricted-access decision

Completing rotation does **not** override `Require all denied`: continued HTTP 403 at the website is expected while that rule remains. This task keeps it intact.

To perform browser login testing later, authorize a minimal protected-access arrangement (for example a verified allowlist for the reviewer or independent directory authentication). That is a separate access-control step; do not simply delete deny rules or make the website unrestricted. No additional framework/service or hosting upgrade is needed for the rotation method itself. Initial website presentation can remain access-restricted and SMS OFF.

When access is separately approved, verify both new passwords work and old ones fail, correct books/announcements load, HTTPS/cookies are correct and SMS Logs shows Disabled. Do not claim actual hosted login acceptance from this offline preparation.

## Verification and preservation performed here

The SQL generation/password validation and update method were tested using synthetic credentials in an owned disposable local InnoDB database. Tests passed for wrong selected database (no change), pending/unexpected student (no change), extra account (no change), successful paired update, independent five-boolean verification, reset-field clearing, unchanged row counts and SMS OFF. That disposable database was removed. The local test server was MySQL 8.0.44; actual MariaDB 11.8.9 execution must be verified by the owner's read-back query. No MariaDB-specific CTE, RETURNING, stored procedure or privileged statement is used.

No actual new passwords were requested/generated by the agent. No preparation folder/activation marker was created for the actual hosted accounts. No Hostinger database/session/configuration/access rule was changed, and no local database/account/credential was modified. No SMS/provider request, credit use, commit, push or deploy occurred. Existing activation-gate code and prepared application ZIP/SQL remain unchanged.

New local source files: scripts/prepare-hosted-passwords.py, tests/hosted-password-preparation.php and this guide. The helper is CLI/offline-only and must not be included in public_html or a deployment archive.

## Repeated-update clarification

A successful independent verification of the same prepared hashes is the completion criterion; changed_accounts is only the last UPDATE’s mutation count. The owner reported all five checks equal to 1 on Hostinger. A synthetic disposable-database regression reproduced a repeated UPDATE returning 0 while hashes stayed unchanged and all five checks remained 1. The finalize prompt now reflects this, without changing its target check, attestation, private marker format or overwrite protection. No actual hosted hashes, credentials or activation file were changed by the agent.
