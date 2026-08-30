# Rebrand Audit — KAZCODE S3 Media Storage → KAZCODE Universal Storage

Full-repo sweep (`grep -rni` for `s3ms`/`S3MS`/`s3-media-storage`/`KazcodeS3MediaStorage`,
non-vendor, non-`.git`) run after the mechanical rename passes, to catch what a
find-and-replace pass structurally cannot: things that were *supposed* to stay the old
name, and things the rename passes missed. Every remaining occurrence in the tree falls
into exactly one of the four categories below — there is no fifth "just hasn't been
looked at yet" bucket.

## 1. MUST CHANGE — found and fixed during this audit

Two real regressions were caught by this sweep, both executable-surface bugs the
mechanical `perl -pe 's/OLD/NEW/g'` passes missed because they lived outside the file
types those passes targeted:

| Location | Bug | Fix |
|---|---|---|
| `.github/workflows/tests.yml` (Configure/Diagnose steps) | Inline `wp eval` PHP still constructed `KazcodeS3MediaStorage\Core\Settings` / `KazcodeS3MediaStorage\Plugin` — a namespace that no longer exists — which would have fataled the `minio-integration` CI job on the next run. | Changed to `Kazcode\WpStorage\*`. |
| `build/build-release.sh` | `VERSION` was derived by grepping the plugin file for the literal string `S3MS_VERSION`, which had been renamed to `KAZUS_VERSION` — the grep matched nothing, `VERSION` would be empty, and every ZIP/checksum filename downstream would have been wrong. | Fixed the grep pattern to `KAZUS_VERSION`; also fixed two stale `S3MS\Vendor` strings in build-time echo/comment text. |
| `kazcode-universal-storage/composer.lock` (both packages) | `composer.json`'s package name changed during the rebrand (`wordpress/s3-media-storage(-pro)` → `kazcode/universal-storage(-pro)`) but the lock files' content-hash was never regenerated, so `composer validate --strict` — which the `phpunit` CI job runs first, before any test executes — failed on `main` immediately after the rebrand PR merged. | `composer update --lock` for both packages (content-hash only, zero dependency version changes — 2-line diff per lock file). |
| `test-product-features-local.php` | Hardcoded a real internal development bucket hostname (`apta-cms-uploads-dev.s3.amazonaws.com`) and an assertion against a specific internal theme path (`/wp-content/themes/cms/`) — both leftover from when this script ran inside the APTA CMS environment this plugin was originally developed in. Neither has anything to do with this product's own features, and the bucket-name assertion would fail outright for anyone running this repo's own `docker-compose.minio.yml` dev topology, since that bucket doesn't exist there. Found during the Phase 5 public-repository cleanup audit (searching for `apta`/internal-project references), not the original s3ms/S3MS sweep. | Rewrote the S3-URL assertion to check against the *currently configured* bucket (`$p->settings()->all()['bucket']`, already read earlier in the same script) instead of a hardcoded name; dropped the unrelated theme-path assertion entirely. |

Both confirmed fixed and verified: `php -l` clean across both packages, PHPUnit 163/163
core + 13/13 pro green, and a full `build/build-release.sh` run in this audit produced
correctly-named `kazcode-universal-storage-2.0.0.zip` / `kazcode-universal-storage-pro-2.0.0.zip`
that pass `verify-scoped-build.sh` and the CI package-content-audit checks. A fresh
install-from-ZIP smoke test (real MariaDB + real MinIO, plugins installed from the built
ZIPs via `wp plugin install`, not symlinked from the repo) confirmed: connection test
green, a real attachment upload auto-offloads to MinIO with the correct delivery URL
rewrite, `wp universal-storage health`/`status` report correctly, the
`kazcode-storage/v1` REST namespace registers 26 routes, and deactivating Pro leaves Free
fully functional with no fatals — while the persisted option is confirmed to still read
as `s3ms_settings`, not `kazus_settings`, exactly as Category 2 below requires.

No other MUST-CHANGE items remain as of this audit.

## 2. LEGACY COMPATIBILITY — deliberately unchanged, persisted or historical

These *must not* change without a real, tested data migration (Strategy B, explicitly
out of scope for 2.0.0 per the brief). Renaming any of these would silently orphan
existing installs' data on upgrade.

**Persisted WordPress data (options, postmeta, custom tables)** — the authoritative list,
confirmed still present verbatim in `includes/`:

- Options: `s3ms_settings`, `s3ms_encrypted_secret`, `s3ms_network_settings`,
  `s3ms_schema_version`, `s3ms_pending_jobs`, `s3ms_background_job`,
  `s3ms_object_stats_cache`, `s3ms_profile_credentials`, `s3ms_legacy_profile_uuid`,
  `s3ms_audit_log`, `s3ms_lock_{id}` (transient prefix).
- Postmeta keys: `_s3ms_status`, `_s3ms_original_key`, `_s3ms_offloaded_at`,
  `_s3ms_verified_at`, `_s3ms_last_error`, `_s3ms_ignored`, `_s3ms_full_form`.
- Custom tables: `{prefix}s3ms_storage_profiles`, `{prefix}s3ms_objects`.

**Genuinely historical admin slugs** (`includes/Admin/AdminMenu.php`'s deprecated
`TOOLS_SLUG`/`DIAGNOSTICS_SLUG` constants, and `AdminLegacyRedirects.php`'s redirect-map
keys): `s3-media-storage-tools`, `s3-media-storage-diagnostics`, `s3-media-storage-profiles`.
These predate even the *prior* "KAZCODE S3 Media Storage" rebrand — they're v1.x bookmark
URLs. Renaming them would break exactly the historical bookmarks
`AdminLegacyRedirects` exists to keep working. Both are explicitly commented in-code as
"never rename these."

**Internal, non-persisted, non-public implementation strings** — found in this sweep,
deliberately left alone as out of scope: form-field names, GET/POST action-param values,
and internal array keys such as `s3ms_do`, `s3ms_action`, `s3ms_key`, `s3ms_offload`,
`s3ms_restore`, `s3ms_verify`, `s3ms_ignore`/`s3ms_unignore`, `s3ms_bulk`,
`s3ms_profile_delivery`. None of these are WordPress hooks, REST routes, CLI commands, or
anything a third party could register against — they're private wiring between one admin
page's form and its own handler, regenerated fresh on every request. Renaming them is
zero-risk but also zero-value; they were excluded from the hook-rename pass's explicit
pattern list on purpose to keep that pass's blast radius auditable, and confirmed by this
sweep to still be internally consistent (every reader of e.g. `s3ms_do` is in the same
file/class as its writer).

## 3. S3-SPECIFIC TECHNICAL TERM — deliberately unchanged, correct as-is

Per the brief's explicit instruction not to genericize legitimate S3-specific
identifiers: `S3ClientFactory`, `ProfileS3ClientFactory`, `S3Storage`, `S3KeyResolver`,
and their AWS-SDK-adjacent internals (`Aws\S3\S3Client` usage, `S3Exception` handling,
SigV4 presigning). These classes talk to the S3 wire protocol specifically — see
`docs/STORAGE-PROVIDER-ROADMAP.md` for why that's still accurate even under the
"Universal Storage" product name (S3-compatible ≠ provider-agnostic; MinIO/R2/Spaces/B2/
Wasabi all ride the same `S3ClientFactory` because they speak the S3 API, not because the
plugin abstracts over storage protocols).

## 4. HISTORICAL DOCUMENTATION — deliberately unchanged filenames, updated content

`docs/S3MS-V2-IMPLEMENTATION-PLAN.md` and `docs/S3MS-2.0-RELEASE-READINESS.md` kept their
`S3MS-*` filenames (a pragmatic scope-limiting decision made mid-rebrand, not yet
separately confirmed with the user — flagged here rather than silently assumed correct).
Their *content* was updated by the blanket product-name/namespace replace passes, so they
now describe `Kazcode\WpStorage\*` and "KAZCODE Universal Storage" accurately; only the
filename itself (and therefore any external link using it) still carries the old product
initialism. Renaming these two files is low-risk (nothing persists a dependency on a docs
filename) and can be done in a follow-up if the user wants filename consistency; it was
left out of this pass to avoid mixing a cosmetic doc-filename change into the same commits
as the substantive namespace/slug work.

## 5. PUBLIC-REPOSITORY CLEANUP — internal/customer references

Separate sweep (`grep -rni "apta"`, `grep -rln "wp-3-storage"`, `grep -rn "local/s3-media-storage"`)
run specifically for the "this repo is now a public product repository, not internal
engineering history" concern, distinct from the s3ms/S3MS namespace sweep above.

| Finding | Classification | Action |
|---|---|---|
| `test-product-features-local.php`: hardcoded real internal dev bucket hostname `apta-cms-uploads-dev.s3.amazonaws.com` and an assertion against an internal-only theme path `/wp-content/themes/cms/` | **REMOVE** | Fixed — see Category 1 above. This was the one genuine "leaks something a public repo shouldn't" finding in this sweep; everything else below is process history, not a leak. |
| `CLAUDE.md`, `docs/DEVELOPMENT.md`: one sentence each noting the plugin was originally developed inside an APTA CMS working copy on a local-only branch, never pushed to that org's remotes | **HISTORICAL** | Kept — explains *why* this repo's remote setup has no APTA-remote constraint (a question a new contributor would otherwise reasonably ask); contains no credentials, bucket names, or other identifying detail beyond the org name itself. |
| `docs/S3MS-V2-IMPLEMENTATION-PLAN.md`: multiple references to APTA CMS as the original development environment, plus a "do not push to `apta`/`origin`" policy section | **HISTORICAL** | Kept — this document is explicitly a frozen point-in-time plan (see `CLAUDE.md`'s own description: "design rationale, not a live description of the current code"); rewriting its history would misrepresent what was actually decided at the time. |
| `docs/S3MS-2.0-RELEASE-READINESS.md`: a line documenting that an earlier pass already removed a stale APTA-remote banner and a real bucket name from `README.md` | **HISTORICAL** | Kept — this is itself the audit record of a prior, correctly-executed cleanup; removing it would hide evidence the cleanup happened. |
| `tests/CHARACTERIZATION.md`: "Local APTA Docker: PHP 8.4" (a dev-environment aside) | **HISTORICAL / LOW PRIORITY** | Kept — no secret or identifying data beyond the org name, purely a PHP-version note. |
| `README.md`, `CLAUDE.md`, `docs/DEVELOPMENT.md`, `docs/S3MS-2.0-RELEASE-READINESS.md`: references to `wp-3-storage` / clone URL `git@github.com:kazmij/wp-3-storage.git` | **FIXED — repository renamed** | The GitHub repository was renamed to `kazmij/kazcode-universal-storage` by the account owner. Local `origin` confirmed pointing at the new URL (`git fetch`/`git ls-remote` both resolve correctly). All references above updated to the new clone URL; `docs/S3MS-2.0-RELEASE-READINESS.md`'s repo field kept as a dated note ("renamed from `wp-3-storage` after this report was written") rather than silently rewritten, since that document is a point-in-time report. |
| `local/s3-media-storage` (old dev-branch-naming convention referenced in the brief) | Not found | No occurrence anywhere in the tree — the local branch naming already uses `local/kazcode-universal-storage*` per `docs/S3MS-V2-IMPLEMENTATION-PLAN.md` line 7. |

No credentials, API keys, customer PII, or other secrets were found in this sweep — the
`test-product-features-local.php` bucket hostname was the only item that crossed from
"internal engineering context" into "identifies a specific real bucket," which is why it's
the only REMOVE in this category.

## Sweep methodology (for repeatability)

```bash
grep -rni "s3ms" --include="*.php" --include="*.yml" --include="*.md" --include="*.json" \
  --include="*.txt" --include="*.js" --include="*.css" . | grep -v "/vendor/\|/.git/"
grep -rn "KazcodeS3MediaStorage" --include="*.php" --include="*.yml" --include="*.yaml" . \
  | grep -v "/vendor/"
grep -rln "s3-media-storage" --include="*.php" --include="*.yml" --include="*.md" \
  --include="*.json" . | grep -v "/vendor/\|/.git/"
```

Re-run this before any future release to confirm no new Category-1 (MUST CHANGE) items
have crept in — a new file that references the old namespace/slug/constants is a bug the
same way the two fixed in Category 1 were.
