# Staging content refinement — 29 October 2026

The existing `bcihs_demo_20261028_236ee935` database was refined **in place**. No new staging database, reset, truncation or migration was used. Preview remains `http://127.0.0.1:8899/index.html`. The real `bcihs_library` database, active Live UniSMS configuration, pending Semaphore sender configuration and original uploads were not changed.

## Catalog editions and sources

All ten records now have a verified publisher/imprint, modern edition description, publication year and checksum-valid ISBN-13. These describe specific reference editions for staging; they are not evidence of school ownership. The original work's nineteenth-century date is never substituted for a modern edition's publication year. Original cover artwork is identified as library artwork, without commercial logos or a claim that it is the publisher's edition jacket.

| Title | Author | Publisher/imprint | Edition description | Year | ISBN-13 |
| --- | --- | --- | --- | ---: | --- |
| Noli Me Tangere | José Rizal | [Penguin Classics](https://www.penguinrandomhouse.com/books/291131/noli-me-tangere-touch-me-not-by-jose-rizal/9780143039693/) | Harold Augenbraum translation | 2006 | 9780143039693 |
| El Filibusterismo | José Rizal | [Penguin Classics](https://www.penguinrandomhouse.com/books/302595/el-filibusterismo-by-jose-rizal-translated-with-an-introduction-and-notes-by-harold-augenbraum/9780143106395/) | Harold Augenbraum translation | 2011 | 9780143106395 |
| Florante at Laura | Francisco Balagtas | [Adarna House](https://adarna.com.ph/products/florante-at-laura-classic-novel) | Annotated by Virgilio S. Almario | 2003 | 9789715084772 |
| Ibong Adarna | Traditional / Anonymous | [Adarna House](https://adarna.com.ph/products/ibong-adarna-classic-novel) | Annotated by Virgilio S. Almario | 2016 | 9789715086066 |
| Pride and Prejudice | Jane Austen | [Penguin Classics](https://www.penguinrandomhouseretail.com/book/?isbn=9780141439518) | Penguin Classics; Vivien Jones edition | 2002 | 9780141439518 |
| Alice's Adventures in Wonderland | Lewis Carroll | [Puffin Books](https://www.penguinrandomhouseretail.com/book/?isbn=9780147510983) | Puffin Chalk paperback | 2014 | 9780147510983 |
| A Christmas Carol | Charles Dickens | [Puffin Books](https://www.penguinrandomhouseretail.com/book/?isbn=9780141324524) | Puffin Classics paperback | 2008 | 9780141324524 |
| The Adventures of Sherlock Holmes | Arthur Conan Doyle | [Penguin Classics](https://www.penguinrandomhouse.com/books/333478/the-adventures-of-sherlock-holmes-by-arthur-conan-doyle-introduction-by-iain-pears-notes-by-ed-glinert-cover-illustrated-by-coralie-bickford-smith/) | Penguin Classics hardcover | 2018 | 9780241347782 |
| Little Women | Louisa May Alcott | [Puffin Books](https://www.penguinrandomhouse.com/books/316851/little-women-by-louisa-may-alcott-illustrated-by-anna-bond/) | Deluxe hardcover | 2014 | 9780147514011 |
| The Wonderful Wizard of Oz | L. Frank Baum | [Modern Library](https://www.penguinrandomhouse.com/books/9763/the-wonderful-wizard-of-oz-by-l-frank-baum/) | Modern Library Paperback Classics | 2003 | 9780812970111 |

These are descriptive edition/format labels, not invented numbered edition statements. US publisher product records were used consistently for the imported English-language editions. Regional UK release dates can differ for the same ISBN; they were not mixed into these records. Florante uses the publisher's 2003 annotated edition rather than an unrelated 2021 printing. Ibong Adarna retains its anonymous/traditional authorship, with Almario's editorial credit in the edition description. No external commercial cover was copied.

| Title | Category / genre | Local shelfmark | Synthetic accession | Copies |
| --- | --- | --- | --- | ---: |
| Noli Me Tangere | Filipino literature / Fiction | FIC RIZ NOL | STG-0001 | 3 |
| El Filibusterismo | Filipino literature / Fiction | FIC RIZ FIL | STG-0002 | 3 |
| Florante at Laura | Filipino poetry / Poetry | 899.21 BAL | STG-0003 | 2 |
| Ibong Adarna | Folklore / Folklore | FOL IBO | STG-0004 | 2 |
| Pride and Prejudice | English literature / Classic fiction | FIC AUS PRI | STG-0005 | 2 |
| Alice's Adventures in Wonderland | English literature / Fantasy | FIC CAR ALI | STG-0006 | 3 |
| A Christmas Carol | English literature / Classic fiction | FIC DIC CHR | STG-0007 | 2 |
| The Adventures of Sherlock Holmes | English literature / Mystery | FIC DOY ADV | STG-0008 | 2 |
| Little Women | American literature / Classic fiction | FIC ALC LIT | STG-0009 | 3 |
| The Wonderful Wizard of Oz | American literature / Fantasy | FIC BAU WON | STG-0010 | 2 |

The `STG-` identifiers and local alphabetic shelfmarks are synthetic staging conventions, never verified BCIHS accessions or authoritative Cutter assignments. The only DDC populated is Florante's `899.21`, with publication place Quezon City, supported by the same ISBN/2003 edition in the [Don Bosco library catalog](https://library.donboscomakati.edu.ph/cgi-bin/koha/opac-detail.pl?biblionumber=28550). The other nine publication places and DDC values remain NULL because the selected publisher records do not establish them. All Cutter fields remain NULL. A librarian should review classifications before real inventory use; reports must retain their honest unmapped-classification behavior when appropriate.

Language and synopsis are documented in the manifests because the schema has no language/description/shelf-location fields. Filipino/Tagalog applies to the two Adarna editions; the selected other editions are English-language editions, including the Augenbraum translations. No schema was expanded to manufacture optional catalog fields.

Private verification manifest: `/Users/macbookprom116512/.codex/bcihs-demo/demo-20261029-01/bibliographic-verification.json`, mode 0600. It records each exact reference edition/source, verification date, language, unresolved fields, staging-only identifier policy and original-artwork policy. It is outside the repository/web root and was not added to Git.

## Announcements and imagery

The four final titles are:

1. **Welcome to the BCIHS Library Portal** — introduces browsing, requests, borrowing history and access to library guidance.
2. **How to Borrow Books** — explains an approved account, available-book request, staff approval, pickup, physical handover and the saved checkout due date. It distinguishes approval from checkout.
3. **QR Code Book Pickup Guide** — explains Show Pickup QR under Borrowing History, staff sign-in/verification, physical Confirm Checkout and single-use behavior. Scanning alone changes no inventory.
4. **Library Reminders and Proper Book Care** — gives general handling and return guidance, with no fabricated hours, fees, penalties or policies.

Full polished bodies and concise leading previews are in `demo-assets/manifest.json` and in the same four existing staging rows. IDs and publication timestamps were preserved: 29, 28, 27 and 26 October 2026 at 09:00 Asia/Manila. The homepage requests the **two newest** publications, Welcome followed by How to Borrow Books. View All returns all four. No one-announcement regression was introduced.

All ten original PNG covers were regenerated from their existing source with `LIBRARY COLLECTION` and `LIBRARY COVER ARTWORK`, retaining their title/author, palette, proportions, paths and original CC0 artwork. All four 16:9 banners now use `BCIHS / LIBRARY PORTAL` and natural reading/library guidance, with no DEMO/SAMPLE/TEST wording. Covers remain 600×900; banners remain 1280×720. Fourteen image files decoded and were served with hashes matching the refined local assets. The refined contact sheet was visually inspected as an image artifact.

No CSS/layout change was needed: the existing homepage and full-announcement image selectors already use natural height with `object-fit:contain`. The 16:9 banners fit their current responsive card widths below the desktop height limits. Cropping and stretching were not introduced. No original school branding, uploaded content or unrelated image component was changed.

The neutral fictional borrower display is now `Library Reader`, email `reader@example.invalid`, year/section `Grade 10`. Login School ID remains `user`, phone remains NULL, role/status remain student/accepted and its existing password hash is unchanged. The administrator remains `admin`. No real identity/contact details were introduced.

## Staging identity and safeguards

An indicator is injected **only into the private copy's ten staff pages**: admin-dashboard.html, attendance.html, borrow-requests.html, manage-announcements.html, manage-books.html, manage-users.html, reports.html, reservations.html, sms-logs.html and transactions.html. Original project HTML files were not altered.

`demo-assets/staging-indicator.js` consults the existing same-origin, uncached authenticated session endpoint and renders the note only for signed-in administrators/librarians. It says `Private staging environment · Illustrative catalog holdings · SMS disabled`. Borrowers/guests do not receive the visible note. It is hidden during printing. The indicator is informational: server authentication, OFF database permission, credential-free simulation configuration and disabled provider transport remain the actual safeguards. It cannot activate SMS or authorize actions.

Private ownership markers, database name, source backups, documentation and consumed original live claims retain their staging/safety labels. Those were not removed to improve the public presentation.

## Exact database scope and preservation

Updates affected only ten existing `books` rows, four existing `announcements` rows and the neutral display fields of the one existing staging `users` row. No row was inserted/deleted; no schema, password, role, inventory count, loan, reservation, attendance, SMS permission or SMS history was changed.

| Table | Before refinement | After refinement |
| --- | ---: | ---: |
| admins | 1 | 1 |
| users | 1 | 1 |
| books | 10 | 10 |
| announcements | 4 | 4 |
| attendance_settings | 1 | 1 |
| reservation_settings | 1 | 1 |
| sms_notification_settings | 1 | 1 |
| borrow_requests | 0 | 0 |
| reservations | 0 | 0 |
| attendance | 0 | 0 |
| sms_logs | 0 | 0 |
| sms_notification_audit | 0 | 0 |

There are still exactly 24 physical copies, all unborrowed and available. Every table outside books/announcements/users matched its pre-refinement row hash. Book IDs, copy counts and creation times, announcement IDs/publication dates and account authentication were preserved. The existing `demo-owner.json` is byte-identical. The pre-refinement staging SQL, seed snapshot and artwork were retained privately before the update; the clean-seed snapshot and staging SQL export now reflect the refined content.

All twelve original development tables matched the original preparation baseline by row hashes, not merely counts. Original database backup checksum and all fourteen original development image/upload files matched their preserved backups. Private SMS configuration and consumed claim hashes remained unchanged. The source is still Live UniSMS; staging is still OFF, credential-free simulation with live sending disabled. No provider request, SMS or credit consumption occurred. Existing Semaphore configuration, BCIHSLIB pending status, checkpoints and Phase 11 deferral were untouched.

## Verification

- **245 read-only staging content/HTTP checks passed**: both original login credentials, neutral fictional account, ten edition records with field-by-field metadata checks, valid ISBN-13 checksums, unchanged inventory/paths, all fourteen images, four complete announcements/dates, two-newest ordering, View All, absence of temporary visible text labels, staff-only indicator inclusion, SMS Disabled, honest empty circulation, Excel comparison chart and internal-path denials.
- **896 connected disposable workflow checks passed**: account/permissions, catalog management/search-related data, borrowing approval/rejection, QR verification/checkout, duplicate/inventory safeguards, reservations, default fourteen-day/custom three-day/specific-date loans, Manila time, Time In only attendance, reports/exports and all seven SMS mock workflows. Only a separately owned temporary QA schema/copy was used and removed; the persistent staging/development databases were not populated with transactions.
- **464 numbered JavaScript checks passed**, including 16 new staging-indicator role/error/duplicate tests, plus the existing Manage Books search/filter/export/catalog-QR-removal checks.
- PHP/JS syntax, Git whitespace, PNG dimensions/decoding and image HTTP/hash checks passed.
- Browser automation remains disabled in Work settings. No browser layout, real-phone or native print acceptance is claimed. Image-artwork inspection is not a substitute for browser responsive QA.

Manual visual QA checklist: open port 8899 at desktop, tablet and mobile widths; confirm the three service cards and two homepage announcements; inspect all four complete banners in View All; search each catalog title and check metadata wrapping; sign in as staff to verify the staging note and as the borrower to verify it is absent; confirm note-free native report printing. Real cellphone QR testing still needs an approved reachable LAN URL or eventual staging HTTPS URL instead of loopback.

## Changed files

Repository files refined/added for this request (22 files):

1. demo-assets/manifest.json
2. demo-assets/staging-indicator.js
3. scripts/build-demo-artwork.py
4. scripts/demo-environment.php
5. tests/demo-content.php
6. tests/staging-indicator.js
7. DEMO-STAGING.md
8. STAGING-CONTENT-REFINEMENT.md
9. demo-assets/books/noli-me-tangere.png
10. demo-assets/books/el-filibusterismo.png
11. demo-assets/books/florante-at-laura.png
12. demo-assets/books/ibong-adarna.png
13. demo-assets/books/pride-and-prejudice.png
14. demo-assets/books/alice-in-wonderland.png
15. demo-assets/books/a-christmas-carol.png
16. demo-assets/books/sherlock-holmes.png
17. demo-assets/books/little-women.png
18. demo-assets/books/wizard-of-oz.png
19. demo-assets/announcements/welcome.png
20. demo-assets/announcements/borrow.png
21. demo-assets/announcements/qr.png
22. demo-assets/announcements/care.png

In the private application copy, those fourteen images, the content manifest and indicator JS were synchronized, and the ten staff HTML pages received only the indicator script reference. Private bibliography, before/after evidence, pre-content SQL/assets and the updated fictional-only SQL export remain outside Git. No private source configuration was copied or edited. Existing unrelated working-tree changes remain uncommitted.

The local-only `refine` helper requires the exact existing random database name and explicit `--confirm`, private ownership marker, matching application connection, unchanged clean-seed baseline and exclusive preview/update lock. It backs up staging before its narrow in-place update. It does not reset/truncate, call providers, or change the source database. Creation/reset helpers were updated to preserve this polished content in future **explicitly authorized** uses; neither creation nor reset was executed for this refinement.

## Hostinger readiness verdict

Content is ready for restricted staging review, with consistent edition references and original local artwork. It is **not yet cleared for unrestricted public hosting**. Prior blockers still apply: replace familiar local passwords securely; add independent staging access protection; configure/verify actual Hostinger PHP/extensions, least-privilege DB credentials, HTTPS, private files/permissions and server deny rules; retain SMS OFF without live credentials; configure a reachable HTTPS pickup base URL; complete responsive, native print and phone QR acceptance. Librarians must verify real holdings/classifications/policies before replacing illustrative catalog records with official school data.

No deployment/public exposure, provider activation, commit, push, merge or main-branch change was performed.
