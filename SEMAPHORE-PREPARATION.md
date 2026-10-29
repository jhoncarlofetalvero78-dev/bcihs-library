# Semaphore preparation — Sender Name pending

Prepared October 29, 2026. Active provider remains UniSMS, Live, production authorized, with administrator notification permission ON. No Semaphore/provider request or SMS was sent and no credits were consumed.

## Private entry locations

- API key: `/Applications/MAMP/htdocs/bcihs-library/includes/semaphore-api-key.php`. The existing loader expects this PHP file to return a string. Its return string is currently blank; paste the school's key inside that string privately in VS Code. It is not an associative setting in sms-local.php. The existing SEMAPHORE_API_KEY environment/server override takes precedence if installed later; none was present during preparation.
- Intended Sender Name: `/Applications/MAMP/htdocs/bcihs-library/includes/sms-local.php`, setting `sender`, now BCIHSLIB.
- Local approval state: the same file, setting `semaphore_sender_status`, now `pending`.

Both files are already Git-ignored, protected by includes/.htaccess, and mode 0600. The key file contains no actual key. A private pre-change SMS configuration copy was saved outside Git/the web root. No private contents were printed or added to documentation. All other private configuration values, including the UniSMS secret, network map, live gates and historical ledger references, were compared and preserved exactly.

## Activation remains separate

Pending approval explicitly blocks Semaphore dispatch even if credentials/live flags are later supplied. Simulation remains usable with a pending sender. Changing the approval state does not change provider selection or activate a transport. No polling or automatic switching was added. Existing Semaphore deployments without the new optional status field retain their existing behavior; BCIHS now has an explicit pending state enforced at the central boundary.

After the school confirms BCIHSLIB approval, funded credits and recipient authorization, update the local status to `approved` only as part of a separately authorized turnover. Keep UniSMS selected until the owner explicitly authorizes changing `provider` to `semaphore` and conducting a controlled school-account acceptance. Do not toggle the provider merely to check whether a pasted key works. Current UniSMS sender Unisoft is selected inside its adapter and is unaffected by the inactive Semaphore sender setting.

The two providers retain the same seven shared notification workflows, templates, recipient lookup, administrator permission, saved due dates, duplicate protection and SMS Logs. No cross-provider retry/fallback or historical replay is added. No live Semaphore delivery, key validity or remote Sender Name approval is claimed by these local checks.

## Verification

- 16 focused Semaphore preparation checks passed: pending/invalid approval blocks transport/readiness; approved mocked Semaphore preserves request fields; pending simulation stays offline; inactive approval metadata cannot switch UniSMS or change its Unisoft payload.
- 94 shared SMS, 105 UniSMS adapter, 120 consumed-guard and 60 production-policy PHP mock checks passed (395 PHP checks including the focused preparation suite). Safe-diagnostic regression also passed.
- 45 SMS Logs and 40 SMS Settings JavaScript checks passed. Changed PHP/JS syntax and Git whitespace checks passed.
- Safe status shows UniSMS / Live / production authorized, both consumed claims intact, intended Semaphore sender configured/pending, blank Semaphore key, no environment credential override, and private key-file permissions safe.
- All 12 active table fingerprints, historical logs, claims, uploads and existing customizations match the starting snapshot. Only the intentional sender/status fields in private SMS configuration changed; all other private values matched exactly. No database mutation was used for testing.

Source files changed for this task: includes/sms.php, includes/sms-core.php, includes/sms-permission.php, includes/sms-local.example.php, js/sms-logs.js, tools/sms-config-status.php, tests/semaphore-preparation.php (new), and this document (new). Private files: sms-local.php had only intended sender/status preparation changes; semaphore-api-key.php was newly created with a blank return string. Private files are excluded from Git.

No business workflow, loan, account, schema, default borrowing policy, active provider, UniSMS live authorization, main/development checkpoint or deferred Phase 11 state was changed. Nothing was staged, committed, pushed, merged or deployed.
