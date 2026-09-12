# WordPress.org Free/Pro Code Audit

A specific audit of the **actual built release ZIP**, not the source tree, and not a
re-statement of `FREE-PRO-CODE-AUDIT.md`. Re-run after the WordPress.org compliance pass
that fixed the last "Free ships a complete premium implementation, Pro merely flips a
switch" cases: `background_migrate`, `audit_log`, and `private_media`/`signed_urls` were
made genuinely Free (gate removed entirely — no `Features::enabled()` check exists for
any of them anymore), and `multiple_profiles` now delegates all 2nd+ profile create/
update/delete/set-default operations to a real Pro implementation
(`Pro\Services\AdditionalStorageProfileService`) rather than Free code that merely checks
a boolean.

Method: unzip the built core ZIP (`kazcode-universal-storage-1.0.0.zip`,
SHA256 `1332252c4a3f074b7c12fed1005469f7c39e231711b2e07d3ee6d175eb3b89fa`), grep `includes/`
for each capability name, and classify every hit.

## Findings

### `background_migrate`, `audit_log`, `private_media`, `signed_urls`

Zero matches for any of these four strings anywhere in `includes/Core/Features.php`'s
`pro_feature_keys()` or anywhere else in the built ZIP — they no longer exist as gate
keys. `BackgroundMigrator`, `AuditLog`, and the private/signed-media delivery path
(`Settings::is_private_media()`, `S3Storage::presigned_url_for_relative()`,
`AttachmentUrlFilter`) are unconditional Free capabilities.

### `storage_profile_migration`

| File | Classification | Evidence |
|---|---|---|
| `includes/Admin/StoragePage.php` | C — compatibility hook | UI gate deciding whether to show the "Storage change wizard →" link |
| `includes/Core/Features.php` | A — shared abstraction | canonical `pro_feature_keys()` gate-key list |

No `StorageMigrationService`, `MigrateObjectService`, or `MigrateAttachmentService` class
exists anywhere in the ZIP (0 matches, verified by direct grep).

### `orphan_scan` / `advanced_health`

| File | Classification | Evidence |
|---|---|---|
| `includes/Admin/HealthPage.php` | C | gate deciding whether to render the orphan-scan panel |
| `includes/Core/Features.php` | A | gate-key list |
| `includes/CLI/CliCommand.php` | C | `ProServices::require('orphan_scan', ...)` — degrades to a friendly WP-CLI error when Pro absent |
| `includes/Infrastructure/Queue/QueueJobType.php` | A | shared job-type string constant (queue infra is a Core primitive; only Pro registers a handler for it) |
| `includes/Services/HealthCheckService.php` | C | `ProServices::get('orphan_scan', ...)` — omits the payload key when null |
| `includes/Infrastructure/BatchProcessor.php` | C | REST route handler, same degrade-to-403 pattern |

No `OrphanScanService` class exists in the ZIP.

### `multiple_profiles`

| File | Classification | Evidence |
|---|---|---|
| `includes/Admin/StoragePage.php` | C | UI gate for the "Add profile" control |
| `includes/Core/Features.php` | A | gate-key list |
| `includes/Infrastructure/WpdbStorageProfileRepository.php` | A — pure data primitive | `insert()`/`update()`/`delete()` carry **no** entitlement check at all now — enforcement moved entirely to the service layer below |
| `includes/Services/StorageProfileAdminService.php` | C | `create()`/`update()`/`delete()`/`set_default()` each check `count()` and delegate to `ProServices::require('additional_storage_profile', ...)` for any 2nd+ profile operation; the sole-profile path (`count() <= 1`) is the only one Free executes directly |

No `AdditionalStorageProfileService` class exists in the ZIP — the actual 2nd+ profile
create/validate/credential-resolve/insert logic lives only in the Pro plugin. This is the
capability that changed shape this pass: previously Free's repository/service carried a
single boolean guard in front of a complete, working implementation; now Free has no
working code path for a 2nd+ profile at all, and genuinely calls out to Pro.

### `multisite_network`

| File | Classification | Evidence |
|---|---|---|
| `includes/Core/Features.php` | A | gate-key list (network settings admin page itself lives only in the Pro plugin) |

## Conclusion

Every hit across all remaining gate-key names (`storage_profile_migration`,
`orphan_scan`, `advanced_health`, `multiple_profiles`, `multisite_network`) classifies as
**A (shared abstraction), B (informational), or C (compatibility hook / graceful
degrade)**. Zero **D (complete premium implementation)** findings remain in the built Free
ZIP for any currently-Pro capability — confirmed by direct grep for each backing service
class, not by inference from gate names.

## Package content audit

Built and inspected both ZIPs directly (this pass):

- **Core ZIP** (`kazcode-universal-storage-1.0.0.zip`, 8,271,xxx bytes range — see the
  frozen artifact record for the exact final value): no `kazcode-universal-storage-pro/`,
  no `.git/`, no `tests/`, no `docs/`, no `build/`, no `scoper.inc.php`, no
  `phpunit.xml.dist`, no `.gitignore`, no `test-product-features-local.php`. Contains
  `includes/` (scoped), `vendor/` (PHP-Scoper-prefixed AWS SDK), `assets/`,
  `composer.json`, `README.md`, `readme.txt`, `THIRD-PARTY-LICENSES.txt`, `uninstall.php`.
  Shipping `build/`/`scoper.inc.php`/a root `BUILD.md` inside the ZIP was tried and
  reverted — see "Source/build tooling disclosure — UNRESOLVED" in
  `FREE-PRO-CODE-AUDIT.md`; it trips Plugin Check's `application_detected` ERROR and
  several others. `composer.json` alone (a plain manifest, not an executable) ships without
  issue and was already present before this pass.
- **Pro ZIP** (`kazcode-universal-storage-pro-1.0.0.zip`, 25,455 bytes): 14 files —
  `includes/Services/{StorageMigrationService,MigrateObjectService,MigrateAttachmentService,OrphanScanService,AdditionalStorageProfileService}.php`,
  `includes/Infrastructure/Queue/Jobs/{MigrateAttachmentJobHandler,MigrateObjectJobHandler,OrphanScanJobHandler}.php`,
  `includes/Admin/{StorageChangeWizardPage,NetworkSettingsPage}.php`,
  `includes/ProModule.php`, `kazcode-universal-storage-pro.php`, `README.md`, `uninstall.php`.
  No `tests/`, `vendor/`, `composer.json/.lock`, or `phpunit.xml.dist`. A previous
  `StorageProfilesPage.php` (a never-registered, placeholder-copy scaffold — its
  `register()` was never called by `ProModule::boot()`, so its submenu never appeared)
  was removed as dead code; multi-profile management already lives entirely in core's
  Storage admin page (`StoragePage.php`), which this add-on extends past the first
  profile via `Core\ProServices::require('additional_storage_profile', ...)`.

Both ZIPs pass the audit: the Free ZIP is a genuinely complete standalone product with no
premium implementation smuggled in; the Pro ZIP is exactly the premium implementation
(now including additional-profile management) with nothing else.
