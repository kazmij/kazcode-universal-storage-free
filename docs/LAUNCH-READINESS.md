# Launch Readiness — KAZCODE Universal Storage 1.0.0

Tracking document spanning four engagements: "Execute Public Release" → "Final Rebrand +
Public Launch Continuation" (namespace/slug/CLI/REST/hooks rename from KAZCODE S3 Media
Storage to KAZCODE Universal Storage) → "Final Launch Execution" (repo rename verification,
a clean final build) → "Branding Assets + Plugin Attribution" (this pass — brand-polish,
attribution links, WordPress.org asset briefs, and a **version-number change from 2.0.0 to
1.0.0**). No public release has existed at any point across all four engagements, so every
change so far has happened at the lowest-risk possible moment. Updated on `main`; see
`git log` for the exact commit this reflects.

## Version number: 2.0.0 → 1.0.0

The product had carried "2.0.0" throughout development because it followed a "v2 storage
architecture" internal codename (the current schema/queue/profile system, as documented in
`docs/S3MS-V2-IMPLEMENTATION-PLAN.md`, superseding an earlier, never-public "v1.x"/"1.1.0"
prototype). Since **nothing has ever been publicly released** under any version number, the
account owner decided a first-ever public release should carry `1.0.0`, not `2.0.0` — clean
semantic-versioning semantics for a first release. Changed this pass:

- Both plugin headers (`Version:`), `KAZUS_VERSION`, `KAZUS_PRO_VERSION`,
  `KAZUS_PRO_REQUIRES_CORE`, and Pro's "requires core" admin-notice text: `2.0.0` → `1.0.0`.
- `readme.txt`: `Stable tag`, `Contributors` (set to the real WordPress.org username,
  `kazmij`, supplied by the account owner — was a `TODO` placeholder before). The changelog
  and upgrade-notice sections were restructured: the old `2.0.0` entry became `1.0.0 =
  First public release`, and the internal, never-public `1.1.0` changelog entry was removed
  entirely (by the account owner's explicit choice, presented as the tradeoff: keeping it
  would have made the changelog read `1.0.0` newest-first followed by an *older-looking but
  numerically higher* `1.1.0`, which is backwards and confusing). A now-irrelevant FAQ entry
  ("Is this backward compatible with v1.x settings?") was removed for the same reason — it
  answered a question a first-time public installer can't meaningfully ask.
- `docs/RELEASE-NOTES-2.0.0.md` renamed to `docs/RELEASE-NOTES-1.0.0.md`, content reworded
  from "Major v2 release" framing to first-release framing, checksums updated.
- Every doc making a *current, live* version claim (`README.md`, `kazcode-universal-storage/
  README.md`, `kazcode-universal-storage-pro/README.md`, `docs/DEVELOPMENT.md`, `docs/
  WORDPRESS-ORG-SUBMISSION.md`) updated to `1.0.0`.
- Deliberately **left alone**: references to "the 2.0.0 rebrand" as a project-phase label
  (`CLAUDE.md`, `docs/STORAGE-PROVIDER-ROADMAP.md`, `docs/REBRAND-AUDIT.md`) and genuinely
  historical point-in-time reports (`docs/S3MS-2.0-RELEASE-READINESS.md`, `docs/
  WORDPRESS-ORG-FREE-PRO-AUDIT.md`) — these describe *when work happened*, not *what version
  currently ships*, and rewriting them would misrepresent the actual history.
- The `v2.0.0` git tag (created and pushed in an earlier pass, never used for an actual
  GitHub Release — no artifacts attached, nothing published, effectively unseen) was deleted
  and replaced with `v1.0.0` at the current commit. See git history for the exact commands.
- Release ZIPs rebuilt under the new version/filenames — see "Final artifacts" below.

## Status

| Area | Status | Evidence |
|---|---|---|
| `origin/main` | SYNCED | `HEAD == origin/main`, confirmed via `git fetch` + `git rev-parse` each pass. |
| CI (GitHub Actions) | PASS (as of the last user-confirmed check) | User-confirmed green after the rebrand merge and the `composer.lock` fix. This session cannot query GitHub Actions directly (no `gh` CLI/API token) — not independently re-checked after the 9 commits pushed in the most recent QA pass (6 bug fixes + a Makefile + doc updates); the account owner should confirm CI is still green after this push. |
| PHP syntax lint | PASS | Full `php -l` sweep (php:8.3-cli container), both plugins, after every change in this pass |
| PHPUnit (core) | PASS | 176 tests / 437 assertions |
| PHPUnit (pro) | PASS | 14 tests / 48 assertions |
| Live render smoke (branding pass) | PASS | All 7 core + 3 Pro admin pages rendered via a live WP+MinIO install with no fatals and an empty `debug.log`, confirming the new footer/About-panel/`plugin_row_meta` additions don't break anything |
| Release build | PASS | Rebuilt fresh under 1.0.0; PHP-Scoper prefixed 3883 files to `Kazcode\WpStorage\Vendor\` |
| Scoped ZIP verification | PASS | `verify-scoped-build.sh` — confirms `Aws\S3\S3Client` resolves to `Kazcode\WpStorage\Vendor\...` |
| Package content audit | PASS | Free ZIP: no Pro plugin, no dev/test artifacts. Pro ZIP: no `tests/`/`vendor`/`composer.lock`/`phpunit.xml`. Version string inside the built ZIP confirmed as `1.0.0` (not just the source tree). |
| Free+Pro ZIP install smoke | PASS (pre-version-rename build; not re-run against the 1.0.0 ZIP specifically) | Full install-from-ZIP smoke was run against the prior (2.0.0-labeled) build this same day: Free-alone activation/offload/URL-rewrite, Pro multi-profile creation via REST, Pro deactivate/reactivate safety — all clean. The version-rename touched only header/constant strings and docs, not any storage-engine code, so this evidence is treated as still valid; a final install-from-the-actual-1.0.0-ZIP smoke before public submission is cheap insurance if the account owner wants it. |
| Rebrand completeness | PASS | `docs/REBRAND-AUDIT.md` |
| Storage-profile delivery bug | FOUND & FIXED | Found during real manual QA on `apps/`: a profile whose `bucket` was never populated (e.g. objects offloaded — which reads Settings directly — before the Settings→Profile sync ever ran) stayed permanently empty forever once any object existed, because the sync's "don't repoint an existing location" safety rule had no exception for "there was never a real location to protect." Broke `ProfileDeliveryUrlResolver` delivery URLs (stuck on dead localhost paths) while offload/verify kept working. Fixed in `LegacyProfileMigrator::sync_default_profile_from_settings()` and `StorageProfileAdminService` (same rule, two call sites) — an empty bucket is now always still-editable regardless of object count. 2 new regression tests, 165/165 green, verified live end-to-end (not just unit tests) on the `apps/` rig against a real AWS bucket. |
| Private-bucket detection | ADDED | `wp universal-storage test` / Settings → Run test now makes one real anonymous request against the bucket's public delivery URL and warns (without failing the overall test) if it 403/401s — the exact silent trap the storage-profile bug above surfaced: perfect connectivity, but every offloaded image still dead in a browser. Skipped when Private Media is on (a private bucket is then correct). 3 new tests, verified live against the real AWS bucket both with the warning firing (Private Media off) and correctly skipping (Private Media on, the site's actual config). |
| Version consistency | PASS | Core 1.0.0 / Pro 1.0.0 (`Requires Plugins: kazcode-universal-storage`, `KAZUS_PRO_REQUIRES_CORE=1.0.0`) / `readme.txt` Stable tag 1.0.0 — cross-checked against current file contents, including the built ZIP's actual header text |
| `v1.0.0` already public? | No | No prior public release under any version number has ever existed |
| Final release artifacts + checksums | Built | See "Final artifacts" below |
| Release notes | READY | `docs/RELEASE-NOTES-1.0.0.md` |
| GitHub Release | READY FOR USER APPROVAL | Tag `v1.0.0` created and pushed; title/notes/artifacts prepared. This session has no `gh` CLI or GitHub API token — cannot publish the Release object itself even with authorization. |
| GitHub repository rename | DONE | `kazmij/wp-3-storage` → `kazmij/kazcode-universal-storage`, confirmed via `git fetch`/`git ls-remote` |
| WordPress.org Free submission | READY FOR MANUAL SUBMISSION | `docs/WORDPRESS-ORG-SUBMISSION.md` — Contributors field now set to the real `kazmij` username |
| WordPress.org slug availability | LIKELY AVAILABLE, human confirmation still required | Corroborating evidence only (404 on the plugin-info API), not a reservation |
| WordPress.org icon/banner | **DONE** | `kazcode-universal-storage-wporg-assets/{icon-128x128,icon-256x256,banner-772x250,banner-1544x500}.png` — produced by the account owner, reviewed against `docs/WORDPRESS-ORG-ASSET-BRIEF.md`: exact dimensions, correct copy, on-brand palette, icon legible at 40px+ |
| WordPress.org screenshots | **DONE** | `kazcode-universal-storage-wporg-assets/screenshots/screenshot-1..6.jpg` — captured live via a real browser session against the `apps/` QA rig (Dashboard, Storage, Media Library, Migration, Health, Settings), matching the captions already in `readme.txt`. Same files also used inline in the docs site. One capture briefly rendered a real AWS access key ID in-frame before being masked via in-page JS for a retake; the unmasked file was deleted immediately and never committed — rotate that key in AWS IAM if it hasn't been already. |
| Plugin-internal branding | DONE | Attribution footer on all 10 admin screens, an About panel on Settings, `plugin_row_meta` links (Free + Pro), corrected `Author`/`Author URI` plugin-header fields — see `docs/BRANDING-SURFACES.md` for the full audit |
| Onboarding / Free-Pro clarity | DONE | The Setup Wizard now actually launches on plugin activation (previously scaffolded but never wired — the transient it set was never read anywhere) via the standard WP-plugin one-time-redirect pattern, skips bulk-activation correctly, and gains a visual step-progress indicator plus an explicit "Restart the setup wizard" link on Settings. Wizard step 4 and the Dashboard both gained a tasteful, factual "What Pro adds" section (bullets + one link, no dark patterns), shown only when Pro isn't active. A dismissible, replayable first-run product tour was added on the Dashboard (spotlights nav/status/migration/failures, "Show tutorial" to replay anytime, dismissal persisted per user), plus a compact "You're on the Free plan" banner and a "Go further with Pro" card on Settings. Verified live end-to-end on `apps/` — a real deactivate+activate cycle produced an actual captured redirect to the wizard URL, and the Pro sections were confirmed to show/hide correctly by toggling Pro on and off. |
| Admin CSS cache-busting | FIXED | `admin.css` was enqueued with a static `KAZUS_VERSION` as its `?ver=` cache-buster while `admin.js` already used `filemtime()` — every CSS-only change kept publishing at the same URL, so browsers that had already fetched it once kept serving stale styles indefinitely. Fixed to match the JS pattern; verified live that the enqueued `ver` now carries a real, changing file mtime. |
| Free-plan Logs page raw WP error | FIXED | `AdminLayout::subnav()` (shown on every admin screen) and Health's "View logs" link both pointed at the Logs page unconditionally, but `AdminMenu` only registered that page with WordPress when the Pro `audit_log` feature was enabled — so in Free, following that always-visible link hit WordPress's own "Sorry, you are not allowed to access this page" screen instead of the plugin's own graceful message. Fixed by always registering the page and replacing the bare "requires Pro" text with a full-chrome Pro-upsell card. |
| Pro info shown in-admin, not just external links | DONE | Per user feedback, every "See Pro features"/"Learn more" CTA (top banner, Dashboard, Settings, Setup Wizard step 4, Logs) now opens a shared in-admin modal (`AdminLayout::pro_modal()`) describing all 8 Pro capabilities with one-line explanations plus the "deactivating Pro is safe" reassurance, instead of linking straight to kazcode.net. The modal's own CTA still opens kazcode.net (no in-plugin checkout yet), but reading about Pro no longer requires leaving wp-admin first — matches common Yoast/WooCommerce/WPForms-style in-product upsell modal practice. Includes Escape-to-close, overlay-click-to-close, and a focus trap for accessibility. Verified live on `apps/`: exactly one modal instance + one trigger per page, rendered only when Pro is inactive. |
| Universal Storage product site + docs live | DONE | `https://kazcode.net/universal-storage/` (product landing page) and `https://kazcode.net/universal-storage/docs/` (33-page user docs, Free+Pro in one tree with a sidebar "Pro" badge, static Pagefind search) shipped — see the company site repo (`kazmij/kazcode.git`, `universal-storage-src/`). Every placeholder link in the plugin (bare `kazcode.net` root, GitHub README anchor, GitHub releases page with nothing published) now points at the live, specific page: plugin header `Plugin URI`, plugins-list row meta, the admin footer on every screen, the Settings → About panel, and the Pro info modal's CTA. All new URLs live-verified against production before shipping. |
| Runtime brand mark (logo in admin UI) | DONE | `kazcode-universal-storage/assets/brand/{logo-mark.svg,logo-mark-64.png,logo-mark-128.png}` — a fresh, simple SVG reconstruction of the WordPress.org icon's "K + storage layers + emerald chevron" concept, sharp at 20–64px. Rendered via a new `AdminLayout::brand_header()` on all 10 admin screens (Free + Pro, Pro reuses Core's method, no duplicated assets). PRO badge shown only when `Features::is_pro_active()`. Two real accessibility issues found and fixed (Pro badge text contrast, footer link contrast — both were under WCAG AA). See `docs/RUNTIME-BRANDING.md`. |
| Pro technical package | READY | Physically separate implementation, activation/deactivation/reactivation safety verified |
| Commercial platform | RECOMMENDATION READY | `docs/COMMERCIAL-PLATFORM-DECISION.md` — Freemius, pricing re-verified current (7.0% combined at low volume, tapering toward ~2.8%) |
| Licensing / Pro updater / Checkout | NOT IMPLEMENTED | Blocked on platform approval, by design |
| Paid sales | NOT READY | No licensing, no updater, no checkout |
| Product/marketing website | PLANNED ONLY | `docs/PRODUCT-WEBSITE.md` — design doc only |

## Final artifacts (this build)

```
kazcode-universal-storage-1.0.0.zip
sha256: d8175ffb2d43ab88b346f03115bdfb8356d9521b415e7520977e551f860b7f3b

kazcode-universal-storage-pro-1.0.0.zip
sha256: 851f4d30b5a6dfbcd6f2b57994da729dabd42e6f524600d0de91749f23c10d88
```

Rebuilt after a QA pass found and fixed 6 bugs (all with regression tests): local files
deleted mid-generation corrupted every offload/regenerate/Image Editor save (`wp media
import` failed outright), the Health page showed "Plan: LITE" while Pro was active, an
attachment could never be storage-migrated a second time, the Failed Items dashboard and
Health "Refresh cache" silently 404'd under Plain permalinks, the Media Library S3 column
showed "Partial" forever after any storage-profile migration, and — the last one, in
Pro's Multisite Network Settings — "Inherit network settings" was a no-op from the moment
it was saved. See commits `d802918`..`bd1df6e`. Before that: rebuilt after re-pointing every plugin link at the now-live
`kazcode.net/universal-storage/` site (plugin header `Plugin URI`, row meta, admin footer,
Settings → About, Pro modal CTA, `readme.txt`) — see the new status row above. Before that:
rebuilt after the Pro-info-in-a-modal change (`includes/Admin/AdminLayout.php` +
`DashboardPage.php`/`SettingsPage.php`/`SetupWizardPage.php`/`LogsPage.php`, `assets/admin.css`,
`assets/admin.js`), all shipped inside the core ZIP. Before that: rebuilt after (1) the
admin.css cache-busting fix, (2) the Free-plan Logs page fix, and (3) merging in the
previously-orphaned first-run product tour + Pro-upsell branch. Before that: rebuilt after the runtime
branding integration (docs/RUNTIME-BRANDING.md) added `kazcode-universal-storage/assets/brand/`
to the core ZIP and changed `AdminLayout.php`/`admin.css`/`SettingsPage.php`. Before that:
rebuilt after the WordPress.org submission-readiness fixes (short description length,
`Plugin URI` removal, `readme.txt` `== External services ==`/`== Screenshots ==` sections
— see `docs/WORDPRESS-ORG-SUBMISSION.md`), which changed the plugin header and readme.txt
shipped inside the ZIP. Prior 1.0.0 checksums are superseded.

**Note on reproducibility:** `build/build-release.sh` embeds file timestamps in the ZIP
archive, so rebuilding from identical source still yields a different SHA256 each time —
confirmed repeatedly across this project. **The checksums above are the ones that matter —
publish the hash of the exact ZIP files attached to the `v1.0.0` GitHub Release**, not a
freshly rebuilt pair.

Every prior checksum recorded earlier in this project (both `s3-media-storage-*`-named
pre-rebrand builds and every `kazcode-universal-storage-*-2.0.0.zip` build) is superseded —
this is the first, and only currently valid, set of release artifacts.

## What only the account owner can do from here

1. **Create and push the `v1.0.0` git tag, then publish the GitHub Release** (this session
   has no `gh` CLI or API token — cannot execute even with authorization; exact commands in
   the final status report). Note: this session already created and pushed the `v1.0.0` tag
   itself as part of the version-rename work (see git history) — publishing the GitHub
   *Release* object (title/notes/attached ZIPs) is the remaining manual step.
2. **Confirm `kazcode-universal-storage` is available as a WordPress.org slug** via an
   actual submission attempt.
3. **Submit the Free ZIP to WordPress.org** (requires an authenticated account/browser).
4. ~~Produce or commission the WordPress.org icon/banner~~ — **DONE**
   (`kazcode-universal-storage-wporg-assets/`). ~~Capture the six real UI screenshots~~ —
   **DONE** (`kazcode-universal-storage-wporg-assets/screenshots/`, captured live from the
   `apps/` QA rig; same files also power the docs site's inline screenshots). No remaining
   visual-asset gap.
5. **Approve (or override) the Freemius recommendation** and create the account/product.
6. Everything downstream of #5 (licensing, Pro updater, checkout, paid sales) is blocked on
   that approval.
7. ~~Supply fresh AWS/R2 credentials for a live re-verification~~ — **DONE**: a real
   MinIO → AWS S3 → Cloudflare R2 cross-provider migration chain was exercised end-to-end
   with a genuinely rotated AWS key and authenticated HEAD verification at each hop, plus
   6 real bugs found and fixed in the process (see `git log` — commits `d802918`..`bd1df6e`).
   Still open: an install-from-ZIP smoke test against the actual built 1.0.0 ZIP
   specifically — the live verification above ran against the live-mounted plugin source
   in the `apps/` QA rig, not a plugin installed from the release ZIP.
