# BCIHS source release readiness

This release submits the completed local source for repository-owner review. It
includes the client UI refinements, Time In attendance, flexible checkout due
dates, reporting improvements, shared SMS provider safeguards, and private
Hostinger preparation tools. Phase 11 collection import remains deferred on its
existing separate branch.

## Security and deployment boundaries

- The retired `sql/admin-account.sql` now contains comments only. The interactive
  `scripts/create-local-admin.php` is unchanged and refuses existing accounts.
- The predefined hash is still present in older Git commits. If any installation
  still uses that historical credential, its owner must rotate it privately.
  This source correction does not establish or change any account's password.
- Database dumps, private activation markers, configuration, provider secrets,
  consumed claims, backup archives and new user uploads are excluded.
- Schema/migration SQL remains source code; it does not contain a school database
  export. Demo artwork and its manifest are illustrative seed content only.
- Simulation and live-disabled remain the example defaults. Production delivery
  needs private authorization and administrator permission; this release neither
  changes those settings nor submits messages.
- Hostinger uses a separately generated hosting layout and private configuration.
  Do not upload this repository wholesale into public_html. Follow the hosting
  guides and preserve the activation/security gates. No live deployment was
  changed by the release preparation.
- Source pages explicitly reference the school favicon. Staff source pages do
  not load the retired staging-notice banner.

## Verification performed for this release

- 896 isolated workflow checks, including due dates, ordinary/reservation/QR
  checkout, access controls, SMS permission, recovery and mock-provider workflows.
  The runner uses a newly owned schema-only disposable database and removes it.
- 94 shared SMS, 105 UniSMS adapter, 120 one-attempt guard, 60 production-policy,
  and 16 Semaphore mock checks; safe-diagnostic checks also passed. No live SMS.
- 35 due-date, 38 statistical calculation/Excel, 28 security, and 9 read-only
  local Apache checks passed.
- All 13 JavaScript suites passed, including announcements/XSS, QR, books,
  reservations, attendance, reports, checkout dates, SMS Logs and SMS Settings.
- 42 offline password-helper and 7 retired administrator-seed checks passed.
- PHP/JavaScript/Python syntax and Git whitespace checks are required before push.

No production provider delivery, real handset test or live Hostinger regression
is newly claimed by these checks. Existing client-reported acceptance evidence
remains documented separately. Scheduled reminders and provider status polling
remain outside this release. Librarian policies and school Semaphore sender,
funding, credentials and separately authorized live acceptance still require
client turnover review.
