# Phase 9 visual checkpoints and safe restoration

Local development only. Never restore a database, private SMS configuration or consumed claim to change the UI. No live SMS is part of this procedure.

## Checkpoints

- `bcihs-pre-phase9-ui`: annotated tag at aa1dd69ce106f595e1926d58e803e9f78bf9fad8. This is **committed source only**, not the user's complete original working directory.
- `bcihs-phase9-qr-stable`: annotated tag at 82b52538594cb25075ae6dbf9046dcb2e6bd756c. It contains only `.htaccess`, `js/pickup-qr.js`, the QR script-version hunk in `profile.html`, `tests/phase3b-pickup-qr.js`, and `tests/phase9-static.php`. It retains the original committed visual design and the functional QR repair.
- `bcihs-phase9-premium-ui-v1`: annotated tag at the final premium UI commit. Resolve its full hash using `git rev-parse bcihs-phase9-premium-ui-v1^{}`. Do not move or force-replace any of these tags.

The premium commit does not include original photo CSS/cache-version hunks or the four untracked image assets. Those remain in the owner's working tree. Both commits preserve the shared SMS implementation, staff-only authorization, inventory/checkout engine, reports and all database rows.

## Verified exact original customization archive

Outside the application web root:

`/Users/macbookprom116512/.codex/visualizations/2026/10/28/phase9/rollback-source.zip`

Companion files: `baseline-files.json`, `user-files.json`, `baseline.patch`. All 260 archived source/asset paths, including all eight original customizations, were SHA-256 checked against the recorded baseline; ZIP CRC validation also passed. Never overwrite the only archive. It contains no private ignored SMS/database configuration or database dump. Keep it owner-private and copy it to another private backup location for long-term recovery.

The archive includes these original customizations:

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

## A — Keep premium UI

Stay on development at the premium tag or its descendant. The eight original customizations intentionally remain uncommitted. No checkout/reset is necessary. Keep simulation mode enabled and live SMS disabled until a separately approved operational change.

## B — Test the original UI, retaining QR repair

Use a **separate empty private worktree path**. Do not run this in the active owner checkout, especially while it has uncommitted work.

```sh
git worktree add --detach /PRIVATE/EMPTY/PATH bcihs-phase9-premium-ui-v1
cd /PRIVATE/EMPTY/PATH
git switch -c codex/client-original-ui-review
git status --short
git revert --no-edit bcihs-phase9-premium-ui-v1
```

The revert applies only the second, visual commit. Verify `git diff bcihs-phase9-qr-stable --` contains no application changes (a later revert commit's history can differ). The QR-only commit remains an ancestor; `.htaccess`, QR generation/fallback and QR script URLs must match its versions. Do not revert Phase 9A.

A Git worktree excludes the user's original uncommitted assets. For exact original visuals, extract the archive into a **second private staging folder**, verify `user-files.json`, compare each of the eight paths, then copy only those eight original files into the clean review worktree. This is safe only for a newly created worktree with no newer edits. Do not extract the entire archive over the worktree: its old `.htaccess` and profile scripts predate the QR repair. The five QR-fix paths must remain from the QR-stable checkpoint.

If this is repeated after later application changes, use a branch from the desired later commit and selectively revert/merge the premium hunks. Review conflicts; do not automatically choose whole old files. Save all later user work first. A visual revert is not authorization to discard functional changes.

Run isolated workflow tests rather than pointing a test server at the genuine bcihs_library database. Copy schema only using the existing `scripts/phase8-isolated-qa.php` procedure. Keep cURL transport disabled and simulation configured. Verify borrower/staff login, routes, QR rendering/independent decoding, staff verification and used-token rejection. Test Apache resource rules as well: the PHP CLI server ignores .htaccess.

## C — Return to premium

For a clean review branch with a recorded UI-revert commit, reverting that revert reapplies premium source:

```sh
git revert --no-edit REVERT_COMMIT_HASH
```

Do not run it with uncommitted original overlays/new user edits. First save those edits privately and inspect them. In a newly created review worktree, after validating their fingerprints, the eight original overlays can be removed from the *review tree only* by restoring their tracked equivalents and saving/moving untracked assets privately. Then revert the visual revert and reapply the eight original customizations **without replacing premium index.html/login.html wholesale**.

For the two overlapping HTML files, merge only the original stylesheet-cache customization into the premium versions:

```text
index.html: css/index.css?v=20261026-call-number -> css/index.css?v=20261027-homepage-photo
login.html: css/login.css -> css/login.css?v=20261027-clearer-photo
```

Keep premium markup and `css/phase9.css`. Restore the two original CSS files and four image assets from the verified snapshot only when their destination has no newer work. For arbitrary later changes, perform a reviewed three-way merge; do not blindly apply these substitutions to unrelated source.

A simpler safe alternative is a **second fresh worktree at the premium tag**, followed by the same reviewed original-asset overlays and selective HTML cache-link merge. Compare resulting application hashes against the owner's saved premium working-state fingerprints. This keeps the original-layout worktree intact for side-by-side review. Neither option touches databases, private credentials, uploads in the owner's checkout, or SMS claims.

The owner can return to their existing development checkout at any time; this procedure never switches its branch. The same application backend is used by both designs. Do not copy private config into Git, expose backups through Apache, replay SMS logs or enable live providers.

## Validation record

The implementation checkpoint is validated using a separate private worktree: revert only the premium commit, overlay the eight verified original customizations, run isolated workflows and inspect original UI/QR. Then reapply premium source in a clean review tree and selectively reapply original customizations; compare source fingerprints and rerun checks. Full hashes, source manifests, screenshots and test results are saved in:

`/Users/macbookprom116512/.codex/visualizations/2026/10/28/phase9-checkpoint/`

See the final validation entry below for actual results. The committed archive reference is not a promise that uncommitted files are stored in Git. Keep the archive and uploaded assets backed up separately. Native phone/network, physical printer, school signatories/policies and deployment acceptance remain separate.

### Completed validation — 28 October 2026

The restoration was actually exercised in the private `phase9-checkpoint/rollback-worktree` on `codex/phase9-ui-rollback-qa`, never in the owner checkout. Reverting the initial premium source commit produced `a759c6bfb1900f84b9124ccda6da5ad7a7159df4`; its source matched the QR-only tag exactly before the original eight overlays were applied. The original homepage, borrower login, history/QR and staff verification were inspected in Chrome. The QR was independently decoded with macOS Vision and matched its disposable verification URL. Borrowers were refused staff access; staff login and explicit Confirm Checkout remained required. The isolated old-layout workflow run passed 194 checks.

Reverting that revert produced `ccea15842578d157bec929056e9dc5f135d0e7d6`. After selectively reapplying the original customizations, application files matched the owner's premium tree byte-for-byte. A second isolated workflow run passed 194 checks. Chrome confirmed premium homepage, borrower login/history/QR, staff login and reports again. Temporary viewport overrides were reset and owned browser tabs closed. Both disposable databases were removed by their QA cleanup; no active database was restored or modified. The final premium commit differs from that tested application tree only in these validation documents.

Independent QR-only source also passed 592 isolated workflow checks. Final database-free checks passed: 310 SMS mock/guard, 28 security, 38 statistical calculation/Excel, 639 counted JavaScript checks plus the Manage Books removal suite; 107 PHP and 43 JavaScript syntax checks; nine local Apache resource/security checks. Earlier Phase 9 responsive and two-page native print evidence remains recorded in PHASE9.md.

All 260 archive fingerprints and its ZIP integrity were verified, including the eight original customizations. All ten active database table fingerprints equal the fresh pre-checkpoint snapshot, including attendance #120, student #364 and SMS log #265. Private SMS configuration, consumed claim and backup hashes remain unchanged. No live SMS or provider request occurred.

Returning to the original UI also restores its original public copy; old school statistics/service claims still require librarian confirmation. Both designs require the same production/LAN configuration, policy approval and physical-printer acceptance. The private validation worktree and evidence are retained outside the web root for review, not deployed.
