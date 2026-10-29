# Final refined local preview — 29 October 2026

Open **http://127.0.0.1:8899/index.html**. This is the separate finalized website connected to `bcihs_demo_20261028_236ee935`, not the original MAMP development database. The original `http://localhost:8888/bcihs-library/` continues to use `bcihs_library` independently.

## Start after restarting the Mac

1. Open MAMP and start MySQL (Start Servers is fine). The preview uses MAMP's MySQL and PHP but runs on its own loopback web-server port.
2. Double-click **Open Final BCIHS Website.command** on the Desktop. It verifies and reuses a running preview, or starts the existing documented staging launcher and opens the finalized homepage.
3. Keep MAMP MySQL running while reviewing. The preview itself is detached from Terminal, so closing the shortcut's Terminal window does not stop it.

Terminal alternative:

```sh
/usr/bin/python3 /Applications/MAMP/htdocs/bcihs-library/scripts/open-final-staging.py
```

Add `--no-open` to check/start without opening another browser tab. The launcher never seeds, resets, imports, edits records or changes the MAMP connection. If port 8899 belongs to another service or readiness/SMS isolation cannot be verified, it stops without killing/replacing that service. A stopped preview's stale PID marker can be cleared only after its recorded process is verified not to be the owned launcher. Preview/reset locks prevent duplicate listeners or a reset during preview.

## What to inspect

- Homepage: separate Our Services section and **two latest announcements**.
- Announcements / View All: all **four** professional notices and complete banners.
- Borrower login at `/login.html`: use School ID `user` and the existing privately retained local password.
- Staff login at `/admin-login.html`: use username `admin` and its existing local password.
- Catalog / Manage Books: **10** refined bibliographic records, **10** covers and **24** available copies.
- Current premium interface, custom due-date/default-period controls, QR workflows, Time In-only attendance and updated reports are present. Initial history remains honestly empty.

The local familiar passwords remain unchanged and are not stored in this guide or shortcut. The local preview uses the normal application login. It does not use the Hostinger package's outside-root deployment configuration/password-rotation gate; those deliberate hosting setup differences remain documented in HOSTINGER-PREPARATION.md. The initial content, business functionality and premium UI match the prepared hosting candidate; they are separate copies and do not auto-sync future edits.

## Checks completed

- Live preview identity endpoint `/__staging-status` returned the exact finalized database, simulation provider, permission OFF, live disabled and no loaded provider credential. This route exists only in the loopback preview router, not in the prepared Hostinger archive.
- 245 read-only application/HTTP checks passed, including both real login endpoints, all ten books/metadata, fourteen image hashes, two latest/all four announcements, reports/Excel, permissions and SMS Disabled state. Only authentication/session/rate-limit files changed; database rows/settings did not.
- The isolated copy's backend/CSS/JavaScript/images match the current source byte-for-byte. Existing staging-only account/data configuration and staff indicator remain separate. No source SMS configuration was copied.
- Actual stop/start recovery and repeat-launch reuse passed using the existing staging launcher. No database reset or business workflow submission was performed.
- Browser access became available: homepage and full Announcements were inspected at 1440px desktop, 768px tablet and 390px mobile. No document-level horizontal overflow; announcement banners loaded and used contain sizing. Exactly two homepage notices and all four full-page cards were confirmed. Temporary viewport overrides were reset and the final homepage was left open.
- This is a focused public-page visual review, not a claim that every authenticated module or native print view has received final visual acceptance. Both account logins were checked through their actual HTTP/application flow; all authenticated layouts and real cellphone/HTTPS behavior still require final review where relevant.
- Original development records, current private UniSMS Live configuration, Semaphore key/pending status, original uploads/backups and consumed claims remain untouched. Persistent refined staging rows remain identical to the finalized seed snapshot. Hostinger ZIP and SQL checksums remain unchanged.

## Files changed for preview convenience

- `scripts/open-final-staging.py` — local-only verify/reuse/start/open wrapper.
- `scripts/demo-router.php` — sanitized loopback-only identity/SMS-isolation status route.
- `LOCAL-STAGING-PREVIEW.md` — this guide.
- Desktop `Open Final BCIHS Website.command` — executable local shortcut.
- Private workspace `router.php` — synchronized router; private launch/lock/server files are runtime evidence only.

No hosting archive, SQL export, database record, provider authorization or original MAMP route was modified. No SMS, credits, commit, push or deployment. Native print/real phone QR verification and actual Hostinger configuration remain outstanding; public deployment still requires strong hosted passwords, HTTPS and independent staging access protection.

## Owner-requested banner removal

The visible staff staging banner was removed on 29 October 2026. All ten local staff pages no longer load its injection script. Future staging creation/refinement and Hostinger package builds no longer inject the banner; the compatibility script only removes a pre-existing banner and makes no network requests. Internal ownership markers, private documentation, database isolation and SMS safeguards remain intact. The previously verified Hostinger ZIP was deliberately not rebuilt for this local presentation change; regenerate/reverify a new candidate before deployment to incorporate it. Ten HTTP page checks, cleanup/no-network JavaScript checks, PHP syntax and whitespace checks passed.
