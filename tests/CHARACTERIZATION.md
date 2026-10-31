# KAZCODE Universal Storage — Phase 0 characterization baseline

Locked expected behaviors for v1.1.0 before structural v2 changes.
See also `docs/S3MS-V2-IMPLEMENTATION-PLAN.md`.

Evidence tag: **CONFIRMED** = covered by unit characterization tests or source audit.

## Destructive call sites (P0-T01)

| Site | Behavior |
|------|----------|
| `AttachmentOffloader::delete_local_files` | `wp_delete_file` only if `realpath` under uploads `basedir` |
| `AttachmentRestorer::on_delete_attachment` | `S3Storage::delete_relatives` from `AttachmentFileResolver::relative_paths` when status or `_s3ms_original_key` set |
| `S3Storage::delete_keys` | Explicit key list; chunks of 1000; **never** recursive prefix delete; falls back to per-key `DeleteObject` when batch fails (MinIO) |
| `ConnectionTestService::run` | Temp file `@unlink` + delete probe object key |

## Offload / local delete (P0-T02, P0-T03)

| Behavior | Expected | Test |
|----------|----------|------|
| Put fails mid-batch | `_s3ms_status=failed`; local files kept; no `wp_delete_file`; uploaded keys **not** rolled back | `OffloadSafetyCharacterizationTest::test_partial_upload_failure_does_not_delete_local_files` |
| `local_storage_policy=keep_originals` | After the gate below passes, delete size variants only; original kept locally | `CleanupLocalFilesTest::test_profile_and_full_manifest_are_passed_to_eligibility` |
| Legacy (non-inventory) offload path | No `s3ms_objects` rows → cleanup blocked, all locals kept | `OffloadSafetyCharacterizationTest::test_legacy_path_without_inventory_keeps_size_variants` |
| `remote_only`, verify HEAD fails / transient / auth / size mismatch | Stay `offloaded`; set sanitized `_s3ms_last_error`; **keep** local | `…::test_verify_before_delete_true_skips_local_delete_when_head_fails`, `CleanupEligibilityTest::test_transient_auth_or_unknown_head_never_authorizes` |
| Cleanup eligibility (#53) | **Every current manifest artifact** (not just the files being deleted) must have one authoritative `present` inventory row re-read from the DB, an exact-key size-verified HEAD on its **recorded** profile (no legacy fallback), and remote delivery (`serve_from_s3` + public URL config + `_s3ms_status=offloaded`, URL outside uploads). Unknown/error fails closed. | `CleanupEligibilityTest`, `CleanupDeliveryProbeTest` |
| Metadata changes between verify and delete | Manifest re-read from persisted metadata right before delete; any difference blocks (`manifest_changed`) | `CleanupLocalFilesTest::test_metadata_changed_after_verification_blocks_stale_deletion` |
| Offload from a generate/update metadata filter | Cleanup deferred to `cleanup_local_files` job (metadata not persisted yet); job re-derives manifest and re-runs the gate | `CleanupLocalFilesJobHandler` |
| Verify before local delete | **Always required** (P5-T03); no opt-out toggle | `CleanupLocalFiles::maybe_cleanup` |

## Delete attachment keys (P0-T04)

Remote delete inventory = current metadata paths + **current** `object_prefix` via `S3KeyResolver`.  
`_s3ms_original_key` is a presence gate / UI value, **not** the full key list.

Test: `DeleteAttachmentCharacterizationTest`.

## URL retargeting (P0-T05)

`PublicUrlResolver` reads **live** Settings. Changing bucket/CDN changes URLs for the same relative path. No per-attachment storage snapshot.

Test: `UrlSettingsRetargetCharacterizationTest`.

### Canonical content and delivery identity (#65)

| Behavior | Expected | Evidence |
|----------|----------|----------|
| Stored references | Post content and Gutenberg block attributes retain canonical site-owned uploads URLs; delivery rewriting occurs only while rendering/API output, so a restore or delivery-domain rotation never writes user content. | `AttachmentUrlFilterRestResponseTest`; `content-canonical-src-real.spec.js` |
| Reverse lookup | `attachment_url_to_postid()` retains a non-zero WordPress result. When core has no match, KAZCODE returns an attachment only for exactly one `present` profile-bound CDN object whose current computed delivery URL matches; a query/fragment or http/https variation is normalized, while foreign, stale and ambiguous owners return `0`. | `ProfileDeliveryUrlResolverTest`; `AttachmentUrlFilterRestResponseTest` |
| `image_downsize` interoperability (#164 first slice) | A valid earlier callback tuple is left byte-for-byte intact through both `image_downsize` and the later `wp_get_attachment_image_src` step. With no earlier result, KAZCODE serves only the requested metadata-recorded image size; an unknown named size and non-image full-object fallback stay `false` for WordPress native handling. `partial` attachments retain exact-variant safety and may still replace a stale KAZCODE/signed URL with their canonical local fallback. This is a WordPress filter-contract result, not a named optimizer/CDN/page-builder integration claim. | `AttachmentUrlFilterRestResponseTest`; `image-downsize-interoperability-isolated.spec.js` |
| WooCommerce standard product/gallery image (#164 Woo slice) | An ordinary Core Media Library JPEG can be assigned as both a simple product's featured and gallery image, migrated with `delete_local: true`, and delivered by the storefront through the configured MinIO URL including responsive `srcset`. The isolated setup is Core-only; it makes no claim about protected downloads, Direct Upload, product binaries, themes, or extensions. | `woocommerce-cloud-only-isolated.spec.js`; exact versions and command: `docs/testing/WOOCOMMERCE-COMPATIBILITY-MATRIX.md` |

### Partial attachment delivery (#152)

| Behavior | Expected | Evidence |
|----------|----------|----------|
| Exact variant eligibility | An attachment with `_s3ms_status=partial` is rewritten only when that exact uploads-relative original, generated size, or backup has a `s3ms_objects.remote_status=present` row. A present sibling never authorizes a missing variant. | `ProfileDeliveryUrlResolverTest::test_present_row_eligibility_is_exact_to_the_attachment_relative_path`; `AttachmentUrlFilterRestResponseTest` partial cases |
| Cloud-only original missing | After Verify records the original's confirmed 404, present generated sizes retain their profile delivery URL; the missing original keeps its canonical/local URL for repair and diagnostics. | `variant-delivery-isolated.spec.js` |
| Generated size missing | After Verify records a generated size's confirmed 404, the present original retains its profile delivery URL; the missing size is not rewritten. | `variant-delivery-isolated.spec.js` |
| Private media | The exact-row eligibility check runs before presigning, so only a present variant receives a signed URL. | `AttachmentUrlFilterRestResponseTest::test_partial_attachment_uses_signed_url_only_for_its_present_variant` |

## Background job (P0-T06)

Option `s3ms_background_job`; cron `s3ms_background_tick`.  
Fields: `running`, `action`, `after_id`, `processed`, `success`, `failed`, timestamps, `last_error`.  
Resume cursor = `after_id`. Soft busy flag only (no tick lease) — documented risk for v2.

Test: `BackgroundMigratorCharacterizationTest`.

## Credentials / salts (P0-T07)

`EncryptionService` derives key from WP salts. Decrypt failure throws; `Settings::get_secret_access_key` catches and returns `''` (silent empty secret).

Test: `EncryptionSaltMismatchCharacterizationTest`.

## MinIO (P0-T08)

Optional Compose overlay (not part of default `make start`):

```bash
# Host :9000 busy? remap ports — PHP still uses http://minio:9000 on the Docker network.
KAZUS_MINIO_API_PORT=19000 KAZUS_MINIO_CONSOLE_PORT=19001 \
  docker compose -f docker-compose.yml -f docker-compose.minio.yml --profile minio up -d minio
docker compose -f docker-compose.yml -f docker-compose.minio.yml --profile minio run --rm minio-mc
docker compose exec -T php wp eval-file wp-content/plugins/kazcode-universal-storage/tests/smoke-phase2-object-offload.php
```

Smoke covers: bucket reachability, object-level offload + inventory rows, restore clearing meta/inventory, remote key cleanup.

## Manual scenarios baseline (P0-T09)

`tests/MANUAL-SCENARIOS.md` Scenarios A–G remain the release smoke list. Phase 0 does not change product behavior; any future change that breaks A–G must update this file and the v2 plan risk register.

## Running tests

```bash
cd app/wp-content/plugins/kazcode-universal-storage
composer install          # installs phpunit + dg/bypass-finals (require-dev)
composer test
# or: ./vendor/bin/phpunit --configuration phpunit.xml.dist
```

No local PHP/Composer? Use the repo-root `Makefile` (`make test-all`) — runs inside the
`apps/` docker QA rig instead. See `docs/DEVELOPMENT.md`.

## Health / repair (Phase 6)

| Behavior | Expected | Test |
|----------|----------|------|
| DB-first health scan | Classify rows by `remote_status` + local file presence; no S3 LIST on admin load | `HealthScanServiceTest` |
| Repair remote_missing | Re-upload when local exists; update row to `present` | `RepairObjectServiceTest::test_reuploads_when_remote_missing_and_local_exists` |
| Repair is profile-aware and size-verified | Upload + HEAD go through `ProfileAwareObjectOperations::upload_file_for_object_row()` on the row's own profile; `present` only after a size-verified HEAD; unresolved profile never falls back to default storage; `deleted` rows are `stale` (not repairable) | `RepairObjectServiceTest` |
| Integrity Verify dry run | Observation only (no `upsert`); expected size: local file → inventory `size_bytes` (>0) → existence-only; mismatch = `size_conflict`; transient/auth HEAD errors = `unknown`; stale/deleted/pending/uploading rows skipped | `IntegrityVerifyServiceTest` |
| Integrity Verify apply | Re-observes under the attachment lease; writes only `present` (size-verified) or `missing` (confirmed 404); never writes `size_bytes`; locked attachments skipped; audit entry has counts only | `IntegrityVerifyServiceTest`, `integrity-verify-repair-isolated.spec.js` |
| Integrity apply needs a dry run | Apply acts only on the current user's stored dry-run rows (≤1 h old) and consumes them; browser-posted ids are never used | `IntegrityActionsTest` |
| Integrity Delivery drift | Report only (no HEAD, no write, no content rewrite); remote state from inventory only, so `artifact_unavailable` says "confirmed missing" only for a `missing` row (written on a confirmed 404); stale/deleted rows are superseded, a migrated attachment with stale source rows is not drift; content lookup is one bounded query per attachment | `DeliveryDriftServiceTest`, `integrity-drift-isolated.spec.js`, `integrity-real.spec.js` |
| Integrity Policy reconciliation | Dry run reads DB + local files only; `local_missing` repairable only with a verified PRESENT row (stale rows never count) and downloads that exact profile/key with the recorded size; `local_retained` apply calls `CleanupLocalFiles` (eligibility gate unchanged); apply re-analyzes under the lease and skips anything no longer applying; non-repairable finding types are ignored even if posted | `PolicyReconciliationServiceTest`, `IntegrityActionsTest`, `integrity-real.spec.js` |
| Upload filename reservation | A path owned by any inventory row (any status), another attachment's `_wp_attached_file`, a Direct Upload reservation or a local file is not reused by `wp_unique_filename` | `UploadPathCollisionGuardTest` |
| Orphan scan | LIST prefix page vs known `object_key` inventory; **dry-run only** | `OrphanScanServiceTest` |
| CleanupLocalFiles job | Runs only when all object rows `present` + `verified_at` | `CleanupLocalFilesJobHandler` |
| HEAD confirmed-missing vs transient error | `S3Storage`/`ProfileStorageGateway::head_key()` set `confirmed_missing=true` only for 404/NoSuchKey/NotFound; any other HEAD failure (network, throttling, auth) returns `exists=false, confirmed_missing=false, error=...` and must **not** be persisted as `remote_status=missing` by callers that write inventory state (fixed bug — see Adopt below) | `HeadKeyConfirmedMissingTest` |

## Storage profile migration (Phase 7)

| Behavior | Expected | Test |
|----------|----------|------|
| Cross-provider (AWS→MinIO) | Stream Get→Put; verify dest before row switch | `MigrateObjectServiceTest::test_dry_run_uses_stream_for_aws_to_minio` |
| Same bucket, different prefix | Server-side CopyObject when provider identity matches | `SameProviderCopyStrategyTest`, `MigrateObjectServiceTest::test_dry_run_uses_copy_for_same_bucket` |
| Retry missing only | HEAD skip for present objects; retry queue includes failed + partial | `ObjectOffloadServiceTest::test_retry_skips_objects_already_present_on_remote`, `MigrationService::STATUS_FILTER_RETRY` |
| Storage migration | Resumable batch + explicit default switch locks location | `StorageMigrationServiceTest` |
| Verify / Restore cursors | Batch selectors advance by `after_id` against fresh database state and set `no_found_rows=true`, because they do not consume `found_posts`; total-row work must not reappear on each page. | `MigrationServiceCursorQueryContractTest`; 10k/50k/100k evidence in `docs/testing/LARGE-LIBRARY-CHARACTERIZATION.md` |
| Crash / tick lease | Background tick lease prevents overlapping cron batches | `BackgroundMigrator::TICK_LEASE_KEY`, characterization test |
| Cross-provider credentials | A profile can hold its own encrypted access key/secret (`ProfileCredentialStore`) instead of the site-wide Settings secret — required since e.g. AWS and R2 profiles need different keys; without this, `ProfileS3ClientFactory` always used Settings' single secret for every profile, so a real cross-provider migration authenticated with the wrong provider's credentials | `ProfileCredentialStoreTest`, `StorageProfileAdminServiceTest::test_create_with_custom_credentials_stores_them_off_the_legacy_ref` et al.; live-verified against real AWS S3 + Cloudflare R2 test buckets (dry-run, real stream migration, `--delete-source` via async queue, independent SDK verification on both sides) |
| Delivery URL after migration | Resolver prefers the non-stale row for a relative path, so the destination profile's URL is served immediately after a successful migration, not the pre-migration (now-stale) one | `ProfileDeliveryUrlResolverTest::test_prefers_present_row_over_stale_row_after_migration`; live-verified |

## Adopt existing (Phase 8)

| Behavior | Expected | Test |
|----------|----------|------|
| HEAD inventory only | Build `s3ms_objects` rows from manifest + HEAD; **never** Put/upload | `AdoptAttachmentServiceTest` |
| Legacy candidates | Batch targets offloaded attachments without object rows. The cursor excludes any matching inventory row through the indexed `s3ms_objects.attachment_id` join; it must not materialize a grouped inventory table for each page. | `AdoptServiceQueryPlanTest::test_legacy_cursor_uses_the_attachment_id_index_without_grouping_inventory_rows`; 10k/50k/100k evidence in `docs/testing/LARGE-LIBRARY-CHARACTERIZATION.md` |
| Transient HEAD error ≠ missing (fixed bug) | A HEAD failure that is not a confirmed 404 is skipped (counted as `errors`, row left unchanged) instead of being written as `remote_status=missing` — a transient outage during Adopt no longer falsely marks present objects as missing | `AdoptAttachmentServiceTest::test_transient_head_error_does_not_record_missing_row` |

## Free / Pro separation (Phase 9)

| Behavior | Expected | Test |
|----------|----------|------|
| Default plan | `Features::plan()` is `lite` (no Pro capabilities) unless a Pro module is registered — a prior default-plan-is-pro bug meant this path was never exercised until the default flipped to lite | `FeaturesProTest::test_default_plan_is_lite_and_blocks_pro_features` |
| No production plan override | There is no constant or filter that can make `is_pro_active()` true without a real Pro module registering via `ModuleRegistry` — a leftover `kazus_plan` filter (the old override, now removed) is inert | `FeaturesProTest::test_kazus_plan_filter_no_longer_has_any_effect` |
| Feature filter cannot unlock Pro | `kazus_feature_enabled` may narrow Pro capabilities, but forcing it to `true` without a registered Pro module leaves every `pro_feature_keys()` entry disabled (and `ProFeatureGate::require()` still throws) | `FeaturesProTest::test_feature_enabled_filter_cannot_unlock_pro_without_module`, `FeaturesProTest::test_feature_enabled_filter_can_narrow_when_pro_active`, `ProFeatureGateTest::test_require_still_throws_when_feature_filter_forced_true` |
| Pro module | `ModuleRegistry::has_pro_module()` is the sole source of truth for Pro tier | `FeaturesProTest::test_core_with_active_pro_module_is_pro_active` |
| Service gates | `MigrateObjectService`, `OrphanScanService`, profile `insert` (2nd+ row) reject on lite | `ProFeatureGateTest` |
| Pro add-on | Separate plugin `kazcode-universal-storage-pro` registers `ProModule` on `plugins_loaded` priority 9 | manual / activation dependency |

## Safe import and resumable multipart (1.1.0)

| Behavior | Expected | Test |
|----------|----------|------|
| Supported Free import sources | WP Offload Media, Advanced Media Offloader, and Media Cloud (S3-compatible drivers with retained `s3.key` only) are registered; no WP-Stateless or Next3 importer is implied | `ImportRegistryTest`, `WpOffloadMediaImporterTest`, `AdvancedMediaOffloaderImporterTest`, `MediaCloudImporterTest` |
| Import safety | Detection/scanning are read-only, dry-run writes no normal object inventory, and commit requires a completed matching dry-run fingerprint | `CompetitorImportServiceTest` |
| Credential boundary | Import DTOs, REST/CLI responses, reports, and audit context contain no copied competitor credential material | `ImportRegistryTest`, `CompetitorImportRestTest`, `CompetitorImportCliTest` |
| Exact source key and transient HEAD handling | Verified descriptors are adopted by their exact source key; whitespace-only keys are rejected; only definitive not-found may become `missing` | `AdoptExactKeyTest`, `HeadKeyConfirmedMissingTest` |
| Local multipart resume | Sessions persist multipart IDs/parts, reconcile provider `ListParts`, and verify destination HEAD before completion or local cleanup | `ResumableTransferServiceTest`, `ObjectOffloadResumableMultipartTest`, `smoke-1.1-multipart-resume.php` |
| Pro cross-provider resume | Source profile/URL stays authoritative until destination verification and commit; then source can become stale | `MigrateObjectServiceTest`, `ProfileDeliveryUrlResolverTest`, `smoke-1.1-multipart-resume.php` |
| Stale cleanup and uninstall | Only incomplete multipart uploads may be aborted after safe checks; preserve keeps v2 recovery state, opt-in purge removes it; no remote object deletion occurs | `StaleTransferCleanupServiceTest`, `UninstallSemanticsTest` |

## PHP-Scoper / release build (Phase 10)

| Behavior | Expected | Test |
|----------|----------|------|
| Dev tree | Unscoped `Aws\` + PHPUnit against `includes/` | `composer test` |
| Release ZIP | AWS SDK prefixed `S3MS\Vendor\`; includes patched; `vendor/s3ms-scoped.php` marker | `build/verify-scoped-build.sh` |
| Licenses | `THIRD-PARTY-LICENSES.txt` generated in release staging | `build/collect-licenses.php` |
| readme.txt | `Stable tag` / `Version` match `S3MS_VERSION` | `ScoperConfigTest`, `build/sync-readme-version.php` |

Production / committed `vendor/` may be `--no-dev` (AWS SDK only). Always `composer install` before PHPUnit.

## Admin IA / dashboard (Phase 11)

| Behavior | Expected | Test |
|----------|----------|------|
| Menu IA | Dashboard landing; Media, Storage, Migration, Health, Logs, Settings subnav | `test-product-features-local.php` render checks |
| Legacy slugs | `tools` → Migration, `diagnostics` → Health, `profiles` → Storage | `AdminLegacyRedirects` |
| ML column | Local/Remote/Partial/Failed + profile + last verified from DB only | `AttachmentObjectSummaryTest` |
| Failed panel | Lives on Media page, not Migration | `MediaPage` + `test-product-features-local.php` |
| Health UI | Cached object stats refresh + DB scan via REST | manual / `HealthPage` |
| Storage wizard | Pro-gated profile migration via REST | `StorageChangeWizardPage` |

## Onboarding tour (admin UX polish)

| Behavior | Expected | Test |
|----------|----------|------|
| Tour end state | `tour_replay_button()`'s step is reliably the tour's true last step everywhere — `pro_upsell_banner()` no longer carries a competing `data-s3ms-tour-step`, which previously outranked it on pages showing both (e.g. Dashboard), ending the tour on the marketing banner instead of closing cleanly | Manual: fresh Dashboard load with Pro inactive, walk the tour to the end |
| Tour close control | A persistent close ("×") on the tooltip, independent of `isLast`/Skip/Finish button wiring | Manual: click × mid-tour, confirm it ends and persists dismissal same as Skip/Finish |
| Replay-button icon alignment | `.s3ms-tour-launch .button .dashicons` has explicit `width`/`height`/`line-height` so the icon doesn't render visually low next to the label | Manual visual check |

## Failed items clearing

| Behavior | Expected | Test |
|----------|----------|------|
| `FailedItemsService::clear()` | Deletes only this plugin's own bookkeeping postmeta (`_s3ms_status`, `_s3ms_last_error`, `_s3ms_offloaded_at`, `_s3ms_verified_at`, `_s3ms_original_key`, `_s3ms_ignored`) for the given attachment IDs — never the attachment post, `_wp_attached_file`, the local file, or the remote object; a subsequent offload/retry starts fresh | `FailedItemsServiceTest` |
| "Clear selected" bulk action | `POST /kazcode-storage/v1/failed/clear`, same shape/permission as the existing ignore/unignore actions; confirmed via `window.confirm()` before firing; logs a `failed_items_cleared` audit entry | Manual: Media → Failed items → select rows → Clear selected |

## Audit log detail (Free + Pro)

Several call sites previously recorded an event with **empty** context, making the log unable to say what actually changed. Now:

| Action | Context | Test |
|--------|---------|------|
| `settings_saved` (Free) | `changed_fields`: list of top-level `s3ms_settings` keys that actually changed, via `Domain\ArrayDiff::changed_keys()` — key names only, never old/new values | `ArrayDiffTest` (the diffing logic; the hook itself isn't separately unit-tested — see `Plugin::boot()`) |
| `network_settings_saved` (Pro) | Same `changed_fields` shape, same `ArrayDiff` helper | `ArrayDiffTest` (shared logic) |
| `object_migrated` (Pro) | `object_id`, `source_profile_id`, `dest_profile_id`, `method`, `success`, `message` — real (non-dry-run) migrations only | `MigrateObjectServiceTest::test_stream_migrate_persists_verified_destination_row` |
| `attachment_migrated` (Pro) | `attachment_id`, `source_profile_id`, `dest_profile_id`, `migrated`, `skipped`, `failed`, `success` — real migrations only | `MigrateAttachmentServiceTest::test_real_migration_records_an_attachment_migrated_audit_log_entry` |
| `storage_migration_batch` (Pro) | `source_profile_id`, `dest_profile_id`, `processed`, `success`, `failed` — real batches only | `StorageMigrationServiceTest::test_migrate_batch_updates_state_and_cursor` |
| `default_profile_switched` (Pro) | `dest_profile_id` | `StorageMigrationServiceTest::test_switch_default_profile_sets_default_and_locks_location` |
| `orphan_scan_page` (Pro) | `profile_id`, `keys_scanned`, `orphans_found`, `complete` — per page, matching the existing non-cumulative `s3ms_orphan_scan_state` semantics | `OrphanScanServiceTest::test_detects_keys_not_in_inventory` |
| `failed_items_cleared` (Free) | `count`, `ids` (first 20) | manual — see Failed items clearing above |

## V2 foundation acceptance (post-P11)

| Criterion | Expected | Test |
|-----------|----------|------|
| Profile-scoped URLs | Object row profile drives delivery URL, not live Settings | `ProfileDeliveryUrlResolverTest` |
| Restore → local serve | Clear `_s3ms_*` meta + object rows after successful restore | `AttachmentRestorerTest` |
| Acceptance smoke | §36 infra checks (partial, retry, repair, queue, IA) | `test-v2-acceptance.php` |
| Superseded variants don't flip status to failed (fixed bug) | `AttachmentSyncDeriver::derive_status()` excludes `stale`/`deleted` object rows from the present/total roll-up, so a fully-offloaded attachment stays `offloaded` after Image Editor crop, Regenerate Thumbnails, or a storage-profile migration leaves the old variant row behind as `stale` — previously it incorrectly derived as `failed`, which broke `AttachmentUrlFilter` URL rewriting for that attachment (Scenario F) | `AttachmentSyncDeriverTest::test_stale_variant_is_excluded_from_roll_up` et al. |

## WordPress media lifecycle matrix (Issue #7)

Authoritative scenario → evidence table (HEIC retention, Image Editor backups, regenerate/stale roll-up, WebP/AVIF hooks, animated poster negative characterization, WooCommerce status, upload→HEAD→cleanup failure points):

See `tests/LIFECYCLE-MATRIX.md`.

Key automated additions:

| Behavior | Expected | Test |
|----------|----------|------|
| HEIC-style `original_image` sibling | Enumerated beside generated JPEG attached file | `ManifestBuilderTest::test_includes_original_image_sibling_for_heic_style_retention` |
| Image Editor `_wp_attachment_backup_sizes` | Enumerated in expected-file model | `ManifestBuilderTest::test_includes_image_editor_backup_sizes` |
| Hook-contributed optimizer sidecars | Existing safe WebP/AVIF paths supplied by `kazus_manifest_files` are part of the same current manifest for object and legacy offload, verification/inventory, policy cleanup and queued cleanup. A missing or unverified contributed path blocks cloud-only deletion; Core neither discovers files recursively nor claims a named optimizer integration. | `AttachmentFileResolverLifecycleTest`, `ObjectOffloadServiceTest::test_offload_uploads_existing_hook_contributed_sidecar`, `OffloadSafetyCharacterizationTest::test_legacy_path_uploads_a_hook_contributed_sidecar`, `VerificationObservationTest::test_missing_hook_contributed_sidecar_blocks_a_verified_result`, `PolicyReconciliationServiceTest::test_policy_cleanup_includes_a_physical_hook_contributed_sidecar`, `CleanupLocalFilesTest::test_unverified_hook_contributed_sidecar_blocks_remote_only_cleanup`, `hook-sidecar-cloud-only-isolated.spec.js` |
| Animated video/poster meta keys | Not auto-included by Core manifest | `ManifestBuilderTest::test_does_not_auto_include_animated_video_or_poster_metadata_keys` |
| Regenerate-style stale+new sizes | Attachment stays `offloaded` | `AttachmentSyncDeriverTest::test_regenerate_thumbnails_style_stale_plus_new_size_stays_offloaded` |

## Uninstall (`uninstall.php`)

Not PHPUnit-testable (needs a real DB + `WP_UNINSTALL_PLUGIN` context) — verified manually against a real WordPress install each time this file changes. Never touches remote storage, WordPress attachment posts, or local media files, in either mode below.

| Behavior | Expected | Verification |
|----------|----------|------|
| Default (`delete_data_on_uninstall` off) | Removes only disposable state: transients, `s3ms_lock_*` per-attachment locks, queue/batch cursor options, dashboard stats cache, `kazus_background_tick` cron, Action Scheduler's `kazus_queue_job` actions, tour-dismissal user meta. Preserves `s3ms_settings`, `s3ms_encrypted_secret`, `s3ms_profile_credentials`, `s3ms_schema_version`, `s3ms_legacy_profile_uuid`, `s3ms_audit_log`, both custom tables (with their rows), and every `_s3ms_*` attachment postmeta | Manual: configured full install, uninstalled, confirmed all of the above survived/cleared exactly as listed |
| Purge (`delete_data_on_uninstall` on) | Everything above, plus: all listed durable options, `DROP TABLE IF EXISTS` on both `s3ms_storage_profiles`/`s3ms_objects`, and every `_s3ms_*` postmeta removed. WordPress attachment post itself untouched | Manual: same setup with the setting enabled, uninstalled, confirmed every durable item gone and the attachment post still present |
| Idempotence | Second uninstall against an already-clean DB does not fatal | Manual: install → uninstall → reinstall → uninstall again, twice; `wp-content/debug.log` stayed empty both times |
| Multisite | Network-activated installs get per-site cleanup via `get_sites()` + `switch_to_blog()` (this plugin's tables/options are per-site, not created once network-wide); `s3ms_network_settings` uses `delete_site_option()` | Code review only — not exercised against a real multisite network this pass |

## PHP

Requires **PHP 8.3+** (WordPress.org recommended baseline). Local APTA Docker: PHP 8.4.

## Safe Mode (#61)

| Behavior | Expected | Test |
|----------|----------|------|
| Write guard | Every SDK write in `S3Storage`/`ProfileStorageGateway` (put, multipart create/part/complete/abort, presigned part, copy, stream destination, delete) calls `SafeMode::guard_write()` first; reads never do | `SafeModeTest::test_storage_write_is_refused_before_the_sdk_is_called`, `safe-mode-isolated.spec.js` |
| Clone detection | Stored identity home ≠ current home, or production → non-production, blocks before any storage request; with no inventory it rebinds instead | `SafeModeTest` |
| Namespace ownership | First write claims `<prefix>.kazcode-site-owner.json` (plugin, site_id, home, claimed_at only); another site's marker blocks; unreadable marker fails closed; claim cached 10 min per namespace + identity | `SafeModeTest`, `safe-mode-real.spec.js` |
| Operator decisions | rebind keeps the id; independent takes a new id; takeover needs explicit confirmation; each is audited | `SafeModeTest`, `safe-mode-isolated.spec.js` |
| Local cleanup / attachment delete | Cleanup blocked (`safe_mode`) on identity mismatch; attachment delete still succeeds in WordPress, remote objects and inventory rows are kept and `remote_delete_skipped` is audited | `SafeModeTest::test_local_cleanup_is_refused_while_writes_are_paused`, `safe-mode-isolated.spec.js` |
