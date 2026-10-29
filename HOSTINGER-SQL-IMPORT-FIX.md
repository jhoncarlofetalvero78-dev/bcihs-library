# Hostinger SQL import repair — 29 October 2026

Target supplied by owner: `u473523930_bcihs_library`, MariaDB **11.8.9-MariaDB-log**. This is the newly created hosted database only. No hosted cleanup/import was performed by the agent, and no local source database was changed.

## Confirmed defect and diagnostic findings

The original `bcihs-refined.sql` creates tables alphabetically. Its second table is `announcements`; its final table is `users`. The actual constraint is:

```sql
FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
```

It does **not** reference `admins`. `announcements.posted_by` and `users.id` are both `INT UNSIGNED`; `posted_by` is nullable and indexed, `users.id` is a primary key, both engines are InnoDB and both table collations are utf8mb4_unicode_ci. There is no source-schema signedness, index, engine or collation mismatch to correct. Integer foreign keys do not depend on string collations. All seven foreign-key names are unique within the schema, compatible with MariaDB 11.8's per-schema naming requirement.

Owner's Hostinger diagnostics confirm selected DB `u473523930_bcihs_library`, foreign-key checks currently enabled, and `SHOW CREATE TABLE users` fails because the parent table does not exist. Therefore the export has a confirmed child-before-parent dependency defect in exactly the relationship implicated by the failed import. The old file relied on `SET FOREIGN_KEY_CHECKS=0` persisting through the import. We have not established how phpMyAdmin applied or overrode that setting at the instant of failure; the later diagnostic value alone cannot prove that. The repair removes the dependency on disabled checking entirely.

Locally on MySQL 8.0.44, creating the original child table without users and with checks enabled is rejected (error 1824, the local missing-parent equivalent). The full original file can succeed in a single local session because it disables checking. This explains why the earlier local restore did not catch the portability weakness. MariaDB's errno 150 is consistent with the missing referenced table in the supplied failed-import diagnostics. See the official [MariaDB foreign-key requirements](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/foreign-keys) and [constraint reference](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint).

`#1050 admins already exists` is a separate partial-import/reimport issue. MySQL/MariaDB CREATE TABLE statements are not rolled back as one file transaction. Successfully created tables can remain after a later statement fails. Do not repeatedly import either file over these leftovers.

PROCESS privilege is not needed for the repair. Hostinger's denial of SHOW ENGINE INNODB STATUS is expected on a restricted shared-host account; do not request global privileges or expose full shared-server diagnostics.

## Corrected file

Filename: **`bcihs-refined-v2-fk-ordered-20261029.sql`**

Finder path:

`/Users/macbookprom116512/.codex/bcihs-hostinger/sql-fix-20261029-v2/bcihs-refined-v2-fk-ordered-20261029.sql`

SHA-256: `d7c28dc89af04fa89066bdfceac2257fa4e247ec2a610ca214f064558fdb20ec`.

The v2 file was exported directly from the existing finalized staging database, not recreated/reseeded. Topological ordering ensures all referenced parents exist before child CREATE TABLE statements, and data inserts follow that same order. Foreign-key checks stay **enabled throughout**; no constraints were removed or weakened. All data, schema definitions, default values, indexes and FK/delete actions are otherwise retained. Instance AUTO_INCREMENT counters are omitted as before.

Creation order: admins, attendance_settings, books, reservation_settings, sms_notification_audit, sms_notification_settings, **users**, **announcements**, attendance, borrow_requests, reservations, sms_logs.

No CREATE DATABASE, USE, DROP, DEFINER, disabled-FK statements, local machine paths, provider keys, plaintext passwords, sessions or claims are included. The existing two account password hashes are preserved; treat the SQL as private and rotate hosted account credentials before allowing web access.

## Verification

Imported v2 into a new empty owned disposable MySQL 8.0.44 database. Verified:

- All **12** tables created successfully, with checking enabled before and after import.
- **7** foreign-key constraints, their referenced tables/update/delete rules, and all indexes match the finalized source.
- Every restored table's sorted row hashes match finalized staging, including both unchanged password hashes and every book/announcement metadata field.
- Exactly one admin, one accepted student, ten books, 24 available copies, four announcements and three settings rows.
- Borrow requests, reservations, attendance, SMS logs and SMS audit remain empty.
- Invalid announcements.posted_by is rejected with FK error 1452; deleting a referenced user sets posted_by NULL as declared. These behavioral probes were rolled back in the disposable database, then row hashes were rechecked.
- Both original local databases were rechecked unchanged. Only the owned verification database was dropped afterward.
- Original SQL export and prepared application ZIP checksums are unchanged. No deploy, SMS, credit usage or Git checkpoint changes.

Private machine-readable evidence: sibling `database-verification-v2.json` and `hostinger-diagnostics-sanitized.json`. Reproducible source tooling: `scripts/hostinger-export-v2.php`; missing-parent diagnostic: `tests/hostinger-fk-order.php`.

**MariaDB-specific limit:** no local MariaDB server executable was available. Docker CLI exists but its configured daemon is not running, so there was no readily available MariaDB 11.8 verification environment. No VM/runtime was installed or started. The SQL uses MariaDB-supported InnoDB, integer/enum/text/timestamp, utf8mb4_unicode_ci, FK and CHECK syntax, but an actual MariaDB 11.8.9 import has **not** yet been observed. Successful local MySQL verification is not a claim of a completed Hostinger acceptance test.

## Safe cleanup of THIS failed hosted import only

1. In Hostinger phpMyAdmin, select **`u473523930_bcihs_library`**. Confirm the breadcrumb and `SELECT DATABASE();` show that exact name. Do not use local MAMP phpMyAdmin or `bcihs_library`.
2. Export the partial target privately first if you want rollback evidence. Confirm it contains only the failed initial import. If it contains any genuine activity or an unfamiliar table, stop and review before clearing anything.
3. In that hosted database's SQL tab, run the following **only after that confirmation**. Statements are fully qualified to the one named hosted database and drop children before parents; no foreign-key disabling is needed. These are cleanup instructions, not statements embedded in the corrected import file.

```sql
SELECT DATABASE();
SET FOREIGN_KEY_CHECKS=1;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`sms_logs`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`reservations`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`borrow_requests`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`attendance`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`announcements`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`sms_notification_audit`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`sms_notification_settings`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`reservation_settings`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`attendance_settings`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`books`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`users`;
DROP TABLE IF EXISTS `u473523930_bcihs_library`.`admins`;
SHOW TABLES;
```

4. SHOW TABLES must return zero tables. If a DROP reports an unexpected dependency or tables remain, stop; do not turn checking OFF or delete additional databases to force progress.
5. Use Import, select **bcihs-refined-v2-fk-ordered-20261029.sql**, format SQL, character set utf-8. Keep foreign-key checks enabled. Import it **once**. Do not select the original bcihs-refined.sql or a development backup. Do not run a seed afterward.
6. If import succeeds, verify the following read-only checks and the final row counts. Do not enable website/SMS access yet.

```sql
SELECT DATABASE(), VERSION(), @@foreign_key_checks;
SHOW TABLES;
SELECT COUNT(*) AS books, SUM(total_copies) AS copies,
       SUM(total_copies-borrowed_copies) AS available
FROM books;
SELECT COUNT(*) AS announcements FROM announcements;
SELECT COUNT(*) AS admins FROM admins;
SELECT COUNT(*) AS students FROM users WHERE role='student';
SELECT COUNT(*) AS foreign_keys
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA='u473523930_bcihs_library';
SELECT allow_notifications FROM sms_notification_settings WHERE id=1;
```

Expected: selected hosted database, checking 1, twelve tables, books 10/copies 24/available 24, announcements 4, admins 1, students 1, FKs 7, SMS allow_notifications 0. All six operational/history/audit counts remain as below.

| Table | Rows |
| --- | ---: |
| admins | 1 |
| users | 1 |
| books | 10 |
| announcements | 4 |
| attendance_settings | 1 |
| reservation_settings | 1 |
| sms_notification_settings | 1 |
| borrow_requests | 0 |
| reservations | 0 |
| attendance | 0 |
| sms_logs | 0 |
| sms_notification_audit | 0 |

If a new error occurs, stop after that single attempt and retain the exact failed statement and message. No PROCESS privilege or secrets are necessary to report the failure. Do not rerun over another partial import.

**Retry verdict:** ready for one controlled retry into the confirmed empty new Hostinger target using v2. Parent creation order and enabled validation are fixed and locally proven. Actual MariaDB 11.8.9/Hostinger acceptance remains the final verification step. Local source databases, their private SMS configuration, historical data, backups, claims, uploads and original deployment artifacts were not modified.
