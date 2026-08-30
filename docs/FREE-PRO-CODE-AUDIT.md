# Free / Pro Code Audit

Exact capability inventory built from the actual code, kept as the source of truth for
the Free/Pro boundary. Updated after the WordPress.org compliance pass that resolved the
last "Free ships a complete premium implementation, Pro merely flips a switch" cases.

Method: grep/read every `Features::enabled()`, `ProServices::get()/require()`, admin page,
CLI subcommand, and REST route in `kazcode-universal-storage/includes/` and
`kazcode-universal-storage-pro/includes/`. "Core or Pro today" reflects **physical code
location** (which plugin's `includes/` the implementing class lives in), not the
feature-gate name — that distinction is the whole point of this audit.

| Capability | UI | Service / implementation | Core or Pro today | Notes |
|---|---|---|---|---|
| Basic offload | Media/Dashboard pages | `Attachment/AttachmentOffloader.php` | Core | Free, ungated |
| Basic migration local→remote | MigrationPage.php | `Services/MigrationService.php`; REST `POST /migrate-batch`; CLI `migrate` | Core | Free, ungated |
| Restore | MigrationPage.php | `Attachment/AttachmentRestorer.php`; REST `POST /restore-batch`; CLI `restore` | Core | Free, ungated |
| Verify | MigrationPage.php | `Services/VerificationService.php`; REST `POST /verify-batch`; CLI `verify` | Core | Free, ungated |
| Retry | MigrationPage.php (CLI mainly) | `Services/FailedItemsService.php`; CLI `retry_failed` | Core | Free, ungated |
| Background/resumable migration | MigrationPage.php | `Services/BackgroundMigrator.php`; REST `POST /background/start` | Core | **Free.** Was gated behind `Features::enabled('background_migrate')` even though 100% of the implementation was already in Core — removed; `background_migrate` no longer exists as a feature key. |
| Audit log | LogsPage.php | `Services/AuditLog.php` | Core | **Free.** Was gated (both recording and viewing) even though it's a lightweight, fully-implemented ring buffer — removed; `audit_log` no longer exists as a feature key. |
| Private media / signed URLs | SettingsPage.php | `Core/Settings.php::is_private_media()`, `Storage/S3Storage.php::presigned_url_for_relative()`, `Attachment/AttachmentUrlFilter.php` | Core | **Free.** This is the same serve-time URL-rewriting pipeline every attachment already goes through — not a standalone premium operation that can be excised without forking that core machinery. `private_media`/`signed_urls` no longer exist as feature keys. |
| Single Storage Profile | StoragePage.php | `Domain/StorageProfile.php`, `Infrastructure/WpdbStorageProfileRepository.php`, `s3ms_storage_profiles` table | Core | Free — the repository/table is shared infrastructure Free itself needs for its one profile. |
| Multiple Storage Profiles (create/update/delete/set-default for a 2nd+ profile) | StoragePage.php | `Pro\Services\AdditionalStorageProfileService.php` — registered via `kazus_pro_service_factory` as `additional_storage_profile` | **Pro** | Fixed: `StorageProfileAdminService::create()/update()/delete()/set_default()` in Core now only ever handle the sole profile (`count() <= 1`); any operation touching a 2nd+ profile delegates to `ProServices::require('additional_storage_profile', ...)`, which throws a "requires Pro" error when Pro is absent. Core no longer has a working code path to create/edit/delete an additional profile — Pro's class is a genuinely separate implementation (not a call back into Core's private methods), reusing only shared primitives (repository, `StorageProfile` value object, `ProfileCredentialStore`, `ProviderPresets`). |
| Profile CRUD (single profile edit/delete) | StoragePage.php | `Services/StorageProfileAdminService.php::update()/delete()/list_summaries()` | Core | Free — correct for the one Free profile; reading/listing all profiles stays Free so existing (Pro-created) profiles remain visible/servable after Pro deactivation. |
| Profile custom credentials | (REST/CLI only — no dedicated Free UI yet) | `Services/ProfileCredentialStore.php` (shared encrypted store) | Core (primitive) / Pro (the "use custom creds instead of site-wide" *decision*, for a 2nd+ profile) | Free's sole profile always uses site-wide credentials now (custom credentials are meaningless with only one profile); the input is accepted but ignored, not rejected. Pro's `AdditionalStorageProfileService` owns the real opt-out-of-site-credentials logic. |
| Provider-to-provider migration | StorageChangeWizardPage.php | `Pro\Services\StorageMigrationService.php`, `MigrateObjectService.php`, `MigrateAttachmentService.php` + queue job handlers; CLI `storage_migrate`; REST `/storage-migrate*` | **Pro** | Already fully Pro-implemented (physical absence in Core) — confirmed still correct. |
| Orphan scan | HealthPage.php orphan panel | `Pro\Services\OrphanScanService.php`, `OrphanScanJobHandler.php`; CLI `health orphan`; REST `POST /health/orphan-scan` | **Pro** | Already fully Pro-implemented — confirmed still correct. |
| Basic health | HealthPage.php | `Services/HealthCheckService.php::run()`, `ObjectStatsAggregator.php`, `AwsAssistant.php` | Core | Free, ungated. |
| Advanced health | HealthPage.php orphan panel (gated `advanced_health`) | `HealthCheckService.php` calls `ProServices::get('orphan_scan', ...)` — no implementation in Core | Core (gate only, correctly) | Compliant "physical absence" pattern — Core holds no working orphan-scan code, only a null-safe call to the Pro extension point. |
| Repair | HealthPage.php, CLI `repair`, REST `/repair` | `Services/RepairObjectService.php`, `RepairAttachmentService.php` | Core | Free (mandatory — no gating recovery ops). |
| Failed items dashboard | MediaPage.php, MigrationPage.php | `Services/FailedItemsService.php`; REST `/failed*`; CLI `retry_failed` | Core | Free, ungated. |
| Media Library actions | `Admin/MediaLibraryColumn.php` | S3 status column + row/bulk actions | Core | Free, ungated. |
| Multisite network settings | `Pro\Admin\NetworkSettingsPage.php` | Self-contained Pro page | **Pro** | Already fully Pro-implemented — confirmed still correct. |
| Setup wizard | `SetupWizardPage.php` | Self-contained page | Core | Free, ungated. |
| WP-CLI | — | `CLI/CliCommand.php` — most subcommands ungated; `health orphan` and `storage_migrate` degrade via `ProServices::require()` | Core | Free (base) / Pro (the two gated subcommands, backed by Pro's own services). |
| REST API | — | `Infrastructure/BatchProcessor.php` — infra + most routes ungated; orphan-scan/storage-migrate* gated via graceful 403 JSON | Core | Free (infra) / Pro (gated routes, backed by Pro's own services). |

## SSRF fix: `ConnectionTestService::check_public_access()`

The public-access probe called `wp_remote_get()` on a URL built from the site's own
configured storage/CDN delivery settings — administrator-controlled, not a fixed trusted
endpoint. Switched to `wp_safe_remote_get()`, which applies WordPress core's
loopback/private/reserved-IP rejection (including on redirect). Covered by
`ConnectionTestServicePublicAccessTest::test_public_access_check_uses_safe_remote_get_api`.
This was the only `wp_remote_*` call site in either plugin (all storage traffic goes
through the AWS SDK's own HTTP client, not `wp_remote_*`).

## Source/build tooling disclosure — UNRESOLVED, needs a business decision

WordPress.org's readable-source guideline ("Detailed Plugin Guidelines", #4) requires
public access to source code *and any build tools*, via either (a) including them in the
deployed plugin, or (b) linking to a development location in the readme.

**(a) was tried and empirically fails.** Shipping `build/build-release.sh` and
`build/verify-scoped-build.sh` inside the release ZIP trips Plugin Check's
`application_detected` ERROR ("Application files are not permitted") — WordPress.org
plugins may not bundle shell-script "applications", independent of the readable-source
guideline. Shipping the standalone `build/*.php` scripts (`collect-licenses.php`,
`sync-readme-version.php`) and `scoper.inc.php` also each trip multiple genuine-looking
ERRORs (missing `ABSPATH` guard, direct `fwrite()`/`file_put_contents()`,
unescaped output, unprefixed globals) because Plugin Check scans every `.php` file in the
ZIP as if it were runtime plugin code, with no way to declare "this only ever runs via
CLI, never inside WordPress." A new root `BUILD.md` also triggered a
`unexpected_markdown_file` warning. **Reverted** — none of this ships in the release ZIP;
`build/build-release.sh`'s cleanup list is back to stripping `build/`, `scoper.inc.php`,
and the rest of the dev-only material.

**(b) is the only remaining compliant path, and it requires a business decision this
audit cannot make on its own:** a link to a public development location. The plugin
repository (`kazmij/kazcode-universal-storage`) is currently private and holds both Free
and Pro source in one working tree (Pro lives in a sibling directory,
`kazcode-universal-storage-pro/`, not nested under Free, but both are checked into the
same git history) — it cannot be made public without exposing Pro's source. Options, none
executed here:
1. Create a new public, Free-only mirror/export repository (no Pro code, no Pro history)
   and link to *that* from readme.txt/README.md. Needs someone to decide how that mirror
   is kept in sync (manual export per release, a filtered git history, a separate
   maintained checkout, etc.) and to actually stand it up.
2. Split the monorepo so Free genuinely lives in its own repository (with Pro elsewhere),
   removing the "combined" problem at the source. A bigger structural change.
3. Accept the current state as **not yet compliant** on this specific point and treat it
   as the one documented blocker before WordPress.org submission — Free's own code is
   still 100% readable/unobfuscated in the shipped ZIP (the guideline's core concern), just
   without a linked public development location.

No repository visibility change was made. Recommend option 1 as the smallest step, but
this is a product/infrastructure decision, not a code fix, and should not be decided
silently.

## Status

All `pro_feature_keys()` entries now correspond to capabilities that are physically
implemented only in the Pro plugin (`multiple_profiles`, `storage_profile_migration`,
`orphan_scan`, `advanced_health`, `multisite_network`) — there is no remaining case of a
complete premium implementation sitting in Free behind a boolean check.
