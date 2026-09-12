# KAZCODE Universal Storage 1.0.0 — Release Readiness Report

**Date:** 2026-08-29
**Scope:** `kazcode-universal-storage/` (Free core) — this report covers the Free plugin only; Pro's own readiness is not re-audited here.
**Repo:** `git@github.com:kazmij/kazcode-universal-storage.git`, branch `main`, HEAD at the time of writing: `cac4c0a`
**Supersedes:** `docs/S3MS-2.0-RELEASE-READINESS.md` (deleted) — that report described a `2.0.0` release under the pre-rebrand `S3MS_*` naming that was never actually published; the product was subsequently rebranded and its version reset to **1.0.0** for the real first public release. Nothing in that old report's version numbers, checksums, or `S3MS_PLAN`-default framing is current — see "What changed since the 2.0.0 report" below if you need the history.

Every claim below is backed by a command actually run or a file actually read while preparing this report, in the same style as the report it replaces.

---

## 1. Executive status

**READY FOR WORDPRESS.ORG SUBMISSION** (Free core only — submission itself was explicitly not authorized in any session to date; nothing has been submitted).

Across several sessions this product went from "internal `S3MS`-branded 2.0.0, never released" to a rebranded, version-1.0.0, WordPress.org-compliance-audited Free plugin with:
- Zero Plugin Check findings (errors **and** warnings) across every category (`plugin_repo`, `security`, `performance`, `general`, unfiltered).
- A resolved Free/Pro architectural boundary — no capability ships as a complete implementation in Free gated only by a boolean (the exact issue WordPress.org's trialware guideline targets).
- No production plan-override mechanism (`KAZUS_PLAN`/`kazus_plan` removed entirely) — Pro capabilities can only come from the real, separately-installed Pro add-on.
- A public, unauthenticated-accessible development-source repository satisfying the readable-source/build-tools guideline, with no Pro code in its current tree or its full git history.
- A deliberate, user-controlled uninstall data-retention policy (default: preserve recovery-critical data; opt-in purge), replacing an uninstall.php that previously deleted some data unconditionally and other data never at all.
- 177 core + 20 Pro PHPUnit tests, all green.

---

## 2. Baseline

| | |
|---|---|
| Core version | **1.0.0** (`KAZUS_VERSION`) |
| `readme.txt` | Requires at least 6.7, Tested up to 7.1, Requires PHP 8.3, Stable tag 1.0.0, License GPLv2+, Contributors `kazmij` |
| PHPUnit tests | 177 core (432 assertions) + 20 Pro (66 assertions) = 197 total, 0 failures/errors/skipped |
| Public development source | `https://github.com/kazmij/kazcode-universal-storage-free`, tag `v1.0.0` at commit `10d43cc` |
| GitHub Releases / tags (private repo) | None |
| WordPress.org submission | **Not yet performed** — explicitly out of scope for every session so far; this report does not change that |

---

## 3. Automated test results

```
composer test   # kazcode-universal-storage/  → OK (177 tests, 432 assertions)
composer test   # kazcode-universal-storage-pro/ → OK (20 tests, 66 assertions)
```

`uninstall.php` is not PHPUnit-testable (needs a real `WP_UNINSTALL_PLUGIN` context and database) — verified manually against a live WordPress install instead; see §7.

---

## 4. Free / Pro feature matrix (current, verified against `Features::pro_feature_keys()`)

| Feature | Free | Pro |
|---|---:|---:|
| AWS S3 / Cloudflare R2 / other S3-compatible (single profile) | ✓ | ✓ |
| Auto-offload, migrate, verify, retry, restore | ✓ | ✓ |
| Native Media Library integration (S3 column, row/bulk actions) | ✓ | ✓ |
| Background/resumable migration (WP-Cron) | ✓ | ✓ |
| Private media (signed, time-limited URLs) | ✓ | ✓ |
| Audit log (settings saves, background job history) | ✓ | ✓ |
| Basic health, repair, WP-CLI, REST API | ✓ | ✓ |
| Setup wizard, Failed-items dashboard | ✓ | ✓ |
| Multiple storage profiles (create/manage a 2nd+) | — | ✓ |
| Cross-provider / cross-bucket migration (verify-before-switch) | — | ✓ |
| Per-profile independent credentials (for a 2nd+ profile) | — | ✓ |
| Orphan scan (dry-run, never deletes) | — | ✓ |
| Advanced health / reconcile | — | ✓ |
| Multisite network defaults | — | ✓ |

This is a real change from the `2.0.0` report's matrix, which listed background migration, private/signed media, and audit log as Pro-only. Those were found, during this product's WordPress.org compliance pass, to be **complete implementations already shipping in Free**, gated only by a boolean with no actual Pro-side code behind them — exactly the "restricted or locked, only made available by upgrade" pattern WordPress.org's Guideline 5 prohibits. They were made genuinely Free rather than either (a) left as a disguised paywall or (b) artificially relocated into Pro at real product cost. Multiple storage profiles remains Pro, but its boundary was rebuilt: Free now only ever manages its own single profile; any operation on a 2nd+ profile delegates to a real, physically-separate Pro service (`Pro\Services\AdditionalStorageProfileService`), not a boolean check in front of Free's own working code.

**`KAZUS_PLAN` / `kazus_plan` no longer exist as a plan-override mechanism at all.** `Features::is_pro_active()` has exactly one source of truth: `ModuleRegistry::has_pro_module()`, i.e. the real Pro add-on actually registering. Verified live: defining `KAZUS_PLAN=pro` and forcing the `kazus_plan` filter to `'pro'` simultaneously still leaves `is_pro_active()` `false` on a Free-only install.

---

## 5. WordPress.org Plugin Check compliance

Run against the exact built/scoped release ZIP (not source) via the official `plugin-check` tool, in a disposable container with no dev-tree bind-mounts:

```
wp plugin check kazcode-universal-storage --require=wp-content/plugins/plugin-check/cli.php --format=json
```

| Category | Errors | Warnings |
|---|---:|---:|
| `plugin_repo` | 0 | 0 |
| `security` | 0 | 0 |
| `performance` | 0 | 0 |
| `general` | 0 | 0 |
| unfiltered (all checks) | 0 | 0 |

This took several iterations to reach genuinely, not by suppression:
- ~40 line-level `phpcs:ignore` annotations, each naming exact sniff codes with a factual rationale traced to the real code path (custom-table CRUD against this plugin's own `s3ms_objects`/`s3ms_storage_profiles` tables with no WordPress core API/table involved; read-only `$_GET`/`$_POST` accesses that decide a redirect/UI state rather than mutate anything; callbacks already nonce-verified upstream by `check_admin_referer()`/`check_ajax_referer()`/the Settings API). **No blanket/file-level suppression exists anywhere** — no `phpcs:disable`, no `phpcs.xml` exclusions, no `--ignore-codes` in the verification runs.
- Two genuine fixes, not just documentation: `MigrationService::stats()` (the dashboard aggregate counts) now caches its result in a 30-second transient, since it's read-heavy and tolerant of slight staleness — unlike the cursor-based batch-migration queries in the same file, which must never be cached (a stale read there would literally break resumable pagination). `LocalFileProvider::ensure_before_image_editor()` gained a real `check_ajax_referer()` call matching the exact nonce WordPress core itself verifies later on the same action, because this plugin's hook runs at priority 1 — before core's own check — and does real file I/O (downloading from S3) in that window.
- One notable build-tooling discovery: PHP-Scoper's pretty-printer, used by `build/build-release.sh` to prefix third-party vendor namespaces, doesn't just reposition trailing `// phpcs:ignore` comments during the scoping step — it can **drop a comment placed mid-expression entirely**. The annotation that survives reliably is a standalone comment on its own line, immediately before the statement it protects. This was verified empirically, iteratively, by rebuilding and re-running Plugin Check after each change — never assumed.

---

## 6. Uninstall / data-retention policy

`uninstall.php` was rewritten from an inconsistent script (unconditionally deleted `s3ms_settings`/`s3ms_encrypted_secret` and 5 `_s3ms_*` postmeta keys on every uninstall; never touched the custom tables, per-profile credentials, or audit log at all) to a deliberate, two-mode policy controlled by a new Settings toggle, **"Delete Universal Storage data when uninstalling"** (default **OFF**):

- **Default (preserve):** removes only disposable/ephemeral runtime state (transients, per-attachment locks, queue/batch cursor options, the dashboard-stats cache, scheduled WP-Cron/Action Scheduler jobs, the onboarding-tour dismissal flag). Storage profiles, encrypted credentials, object inventory, attachment remote-status postmeta, schema version, and the audit log all survive, so a reinstall can detect and recover the existing configuration.
- **Purge (opt-in):** additionally removes every durable option, both plugin-owned custom tables (`DROP TABLE IF EXISTS`, explicit hardcoded names only), and the full set of `_s3ms_*` postmeta.

**Neither mode ever contacts a storage provider, deletes a remote object, deletes a WordPress attachment post, or deletes a local media file** — confirmed both by static review (zero `wp_remote_*`/`Aws\`/filesystem-delete constructs anywhere in `uninstall.php`) and live testing: configured a full install (settings, encrypted secret, a profile credential, a storage-profile row, an object-inventory row, attachment + postmeta, an audit-log entry, every ephemeral-state item), uninstalled with the setting OFF — every durable item survived intact, every ephemeral item was gone, zero errors. Repeated with the setting ON — every durable item was gone (both tables dropped), the WordPress attachment post itself was untouched, zero remote calls, zero errors. Re-ran install→uninstall against an already-clean database twice more to confirm idempotence (no fatals).

Multisite: implemented (`get_sites()` + `switch_to_blog()` per-site cleanup, since this plugin's tables/options are created per-site, not once network-wide; the shared network-defaults option uses `delete_site_option()`), but **not exercised against a real multisite network** — disclosed, not claimed as tested. See `tests/CHARACTERIZATION.md`'s "Uninstall" section for the full behavior table.

Free's uninstall never touches Pro's own options (`s3ms_orphan_scan_state`, `s3ms_storage_migration`) — that remains Pro's own responsibility; Pro has no `uninstall.php` of its own yet, which is out of scope for a Free-only WordPress.org submission.

---

## 7. Source/build-tools disclosure

WordPress.org's readable-source guideline requires public access to source code *and* build tools, via either (a) including them in the deployed plugin, or (b) linking to a development location. Bundling `build/*.sh` inside the distributed ZIP was tried and reverted — Plugin Check flags shell scripts as disallowed "application files," and the standalone `build/*.php` scripts get scanned as if they were runtime plugin code. Option (b) is what's actually implemented: `readme.txt`'s `== Development ==` section and `README.md` both point to `https://github.com/kazmij/kazcode-universal-storage-free`, a genuinely public (confirmed via the unauthenticated GitHub API), separately-maintained mirror containing the Free source, `BUILD.md` (exact reproduction commands), `LICENSE` (GPLv2), and a `v1.0.0` tag matching this release.

The private combined-monorepo (`kazmij/kazcode-universal-storage`, containing both Free and Pro) stays private — never made public, per explicit instruction across every session. A sync script (`tools/sync-free-public.sh`) exports only Free's git-tracked files (minus `docs/`, which holds internal-only material, and `tests/eval-file/`, whose manual QA scripts reference Pro class names) into the public mirror, with a built-in sanity check that hard-fails on any actual Pro class code-reference. The public repo's full git history was independently confirmed to contain zero Pro-named files in any commit (it's a single fresh-init commit, never derived via subtree/filter-branch from the private repo's history).

Fresh-clone reproducibility verified: cloned the public repo into a path with no sibling Pro directory and no monorepo ancestry, ran `composer install && bash build/build-release.sh`, produced a valid, `verify-scoped-build.sh`-verified ZIP, installed clean into a disposable WordPress.

---

## 8. Final artifact

```
VERSION=1.0.0
SIZE=8,287,081 bytes
SHA256=ac99bb2271e6137736486c5ce58c9f963cbb778c52cc6548b54cca8c2a7c6ee7
SOURCE_COMMIT=cac4c0a5c58ccf103cfe79ad2307fa8472da41b4
```

Published at `https://kazcode.net/downloads/kazcode-universal-storage-1.0.0.zip` (+ matching `.sha256`) — confirmed byte-identical across the local build, the website's checksum file, and a fresh unauthenticated download, each time this artifact was rebuilt. The publicly-downloaded copy was independently installed into a clean disposable WordPress with a clean debug log.

---

## 9. Storage provider integration — carried over, not re-verified this pass

The real AWS S3, Cloudflare R2, and MinIO integration testing described in the superseded `2.0.0` report (connection test, offload/verify/restore, cross-provider migration, delete-attachment remote cleanup, all independently checked against the raw SDK rather than trusting the plugin's own success messages) exercised `S3Storage`, `ProfileStorageGateway`, and the migration services — none of which were touched by this compliance pass (the changes here were Free/Pro boundary, Plugin Check annotations, and `uninstall.php`; no storage-wire-level code changed). That earlier verification is not re-asserted as current fact here, only noted as the last real evidence for that layer — if a release process wants fresh live-storage confirmation, it should be re-run against this exact 1.0.0 artifact rather than assumed from the 2.0.0-era report.

---

## 10. Remaining items before an unconditional GO

- **WordPress.org submission itself has not been performed** — every session to date was explicitly scoped to stop short of this.
- **Real multisite network exercise** — the uninstall multisite code path is implemented and reviewed but not run against an actual multi-site network.
- **Live storage-provider re-verification against this exact 1.0.0 build** — see §9; not a known defect, just not re-run this pass.
- **Pro's own `uninstall.php`** — does not exist yet; only relevant once Pro itself needs a WordPress.org-adjacent readiness pass (Pro is never distributed via WordPress.org, so this is lower urgency, but worth tracking if Pro ever needs its own uninstall story for direct customers).

---

## Recommendation

```
GO — for WordPress.org submission of the Free core plugin (Plugin Check clean, Free/Pro boundary resolved, source/build-tools disclosed, uninstall policy deliberate and verified).
NOT PERFORMED — the submission itself. No session has been authorized to actually submit.
```
