# KAZCODE Universal Storage — Phase 0 characterization baseline

Locked expected behaviors for v1.1.0 before structural v2 changes.
See also `docs/S3MS-V2-IMPLEMENTATION-PLAN.md`.

Evidence tag: **CONFIRMED** = covered by unit characterization tests or source audit.

## Destructive call sites (P0-T01)

| Site | Behavior |
|------|----------|
| `AttachmentOffloader::delete_local_files` | `wp_delete_file` only if `realpath` under uploads `basedir` |
| `AttachmentRestorer::on_delete_attachment` | Acquires the per-attachment lock, asks `RemoteDeleteSafetyGuard`, then deletes only `ProfileObjectLocation` entries through `ProfileAwareObjectOperations`; ambiguous/shared/missing-profile states skip remote delete and log `remote_delete_skipped` |
| `S3Storage::delete_keys` | Explicit key list; chunks of 1000; **never** recursive prefix delete; falls back to per-key `DeleteObject` when batch fails (MinIO) |
| `ConnectionTestService::run` | Temp file `@unlink` + delete probe object key |

## Offload / local delete (P0-T02, P0-T03)

| Behavior | Expected | Test |
|----------|----------|------|
| Put fails mid-batch | `_s3ms_status=failed`; local files kept; no `wp_delete_file`; uploaded keys **not** rolled back | `OffloadSafetyCharacterizationTest::test_partial_upload_failure_does_not_delete_local_files` |
| `local_storage_policy=keep_originals` | After verify, delete size variants only; original kept locally | `…::test_keep_originals_deletes_size_variants_only`, `CleanupLocalFilesTest::test_keep_originals_deletes_only_sizes` |
| `local_storage_policy=remote_only`, verify Head fails | Stay `offloaded`; set `_s3ms_last_error`; **keep** local | `…::test_verify_before_delete_true_skips_local_delete_when_head_fails`, `CleanupLocalFilesTest::test_verify_failure_skips_delete` |
| Verify before local delete | **Always required** (P5-T03); no opt-out toggle | `CleanupLocalFiles::maybe_cleanup` |
| Verification level before local delete | Local delete requires `SIZE_VERIFIED` when local size is known: HEAD must prove the key exists and `Content-Length == filesize(local)`. `EXISTS_VERIFIED` alone is not sufficient for destructive cleanup. | `CleanupLocalFilesTest::test_remote_only_skips_delete_when_remote_size_mismatches_local_file` |

## Remote observation semantics

| Observation | Meaning | Persistence rule |
|----------|----------|------|
| `REMOTE_PRESENT` | Provider confirmed the key exists. With expected size, this becomes `SIZE_VERIFIED` only when `Content-Length` matches. | May update `verified_at` only when the operation's required verification level is satisfied. |
| `REMOTE_CONFIRMED_MISSING` | Provider returned definitive not-found semantics, such as 404/NoSuchKey/NotFound. | May be reported as missing where the caller is allowed to record absence. |
| `REMOTE_UNKNOWN` / `REMOTE_ERROR` | Auth failure, throttling, timeout, network/TLS/DNS/provider error, or unresolved profile/object location. | Must not be persisted as remote missing or data loss; destructive local delete and remote cleanup fail closed. |

`EXISTS_VERIFIED` and `SIZE_VERIFIED` are not byte-for-byte content verification. `CONTENT_VERIFIED` is reserved for a future checksum/deep verification design. KAZCODE does not treat S3-compatible ETag values as universal MD5 content hashes because multipart uploads, encryption, and provider implementations change ETag semantics.

## Delete attachment keys (P0-T04)

Remote delete inventory = the resolved `s3ms_objects.storage_profile_id + object_key` for every current manifest path.
`_s3ms_original_key` is a presence gate / UI value, **not** the physical delete authority.

Test: `DeleteAttachmentCharacterizationTest`.

## URL retargeting (P0-T05)

`PublicUrlResolver` reads **live** Settings. Changing bucket/CDN changes URLs for the same relative path. No per-attachment storage snapshot.

Test: `UrlSettingsRetargetCharacterizationTest`.

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
| Orphan scan | LIST prefix page vs known `object_key` inventory; **dry-run only** | `OrphanScanServiceTest` |
| CleanupLocalFiles job | Runs only when all object rows `present` + `verified_at` | `CleanupLocalFilesJobHandler` |
| HEAD confirmed-missing vs transient error | `S3Storage`/`ProfileStorageGateway::head_key()` set `confirmed_missing=true` only for 404/NoSuchKey/NotFound; any other HEAD failure (network, throttling, auth) returns `exists=false, confirmed_missing=false, error=...` and must **not** be persisted as `remote_status=missing` by callers that write inventory state (fixed bug — see Adopt below) | `HeadKeyConfirmedMissingTest` |
| Remote-only REST media editor reads | Authenticated `GET /wp-json/wp/v2/media/<id>?context=edit` may trigger WordPress 7.1 `wp_get_missing_image_subsizes()` and `wp_getimagesize()` against `original_image`; KAZCODE permits narrow per-attachment materialization only for authorized media edit REST requests, uses profile-aware reads, and removes files materialized during that REST response again when policy is `REMOTE_ONLY` | `RemoteOnlyRestMaterializationTest`, `tests/eval-file/test-remote-only-rest-context-edit.php` |

## Storage profile migration (Phase 7)

| Behavior | Expected | Test |
|----------|----------|------|
| Cross-provider (AWS→MinIO) | Stream Get→Put; verify dest before row switch | `MigrateObjectServiceTest::test_dry_run_uses_stream_for_aws_to_minio` |
| Same bucket, different prefix | Server-side CopyObject when provider identity matches | `SameProviderCopyStrategyTest`, `MigrateObjectServiceTest::test_dry_run_uses_copy_for_same_bucket` |
| Retry missing only | HEAD skip for present objects; retry queue includes failed + partial | `ObjectOffloadServiceTest::test_retry_skips_objects_already_present_on_remote`, `MigrationService::STATUS_FILTER_RETRY` |
| Storage migration | Resumable batch + explicit default switch locks location | `StorageMigrationServiceTest` |
| Crash / tick lease | Background tick lease prevents overlapping cron batches | `BackgroundMigrator::TICK_LEASE_KEY`, characterization test |
| Cross-provider credentials | A profile can hold its own encrypted access key/secret (`ProfileCredentialStore`) instead of the site-wide Settings secret — required since e.g. AWS and R2 profiles need different keys; without this, `ProfileS3ClientFactory` always used Settings' single secret for every profile, so a real cross-provider migration authenticated with the wrong provider's credentials | `ProfileCredentialStoreTest`, `StorageProfileAdminServiceTest::test_create_with_custom_credentials_stores_them_off_the_legacy_ref` et al.; live-verified against real AWS S3 + Cloudflare R2 test buckets (dry-run, real stream migration, `--delete-source` via async queue, independent SDK verification on both sides) |
| Delivery URL after migration | Resolver prefers the non-stale row for a relative path, so the destination profile's URL is served immediately after a successful migration, not the pre-migration (now-stale) one | `ProfileDeliveryUrlResolverTest::test_prefers_present_row_over_stale_row_after_migration`; live-verified |
| Existing object profile affinity | Existing remote objects are always addressed through their persisted Storage Profile binding (`s3ms_objects.storage_profile_id` + `object_key`), never through whatever profile happens to be the current upload target. This covers public delivery, signed URLs, restore, local materialization, verify, repair, and remote delete safety. Legacy/global fallback is explicit and only for non-destructive no-inventory compatibility reads. | `ProfileObjectLocatorTest`, `ProfileAwareObjectOperationsTest`, `ProfileAffinityHotspotTest`, `RepairObjectServiceTest::test_repair_uses_inventory_profile_not_current_default_storage`, `DeleteAttachmentCharacterizationTest::test_delete_uses_guard_approved_physical_keys_not_metadata_relatives` |

## Adopt existing (Phase 8)

| Behavior | Expected | Test |
|----------|----------|------|
| HEAD inventory only | Build `s3ms_objects` rows from manifest + HEAD; **never** Put/upload | `AdoptAttachmentServiceTest` |
| Legacy candidates | Batch targets offloaded attachments without object rows | `AdoptService::query_ids` |
| Transient HEAD error ≠ missing (fixed bug) | A HEAD failure that is not a confirmed 404 is skipped (counted as `errors`, row left unchanged) instead of being written as `remote_status=missing` — a transient outage during Adopt no longer falsely marks present objects as missing | `AdoptAttachmentServiceTest::test_transient_head_error_does_not_record_missing_row` |

## Free / Pro separation (Phase 9)

| Behavior | Expected | Test |
|----------|----------|------|
| Default plan | `Features::plan()` is `lite` (no Pro capabilities) unless a Pro module is registered — a prior default-plan-is-pro bug meant this path was never exercised until the default flipped to lite | `FeaturesProTest::test_default_plan_is_lite_and_blocks_pro_features` |
| No production plan override | There is no constant or filter that can make `is_pro_active()` true without a real Pro module registering via `ModuleRegistry` — a leftover `kazus_plan` filter (the old override, now removed) is inert | `FeaturesProTest::test_kazus_plan_filter_no_longer_has_any_effect` |
| Pro module | `ModuleRegistry::has_pro_module()` is the sole source of truth for Pro tier | `FeaturesProTest::test_core_with_active_pro_module_is_pro_active` |
| Service gates | `MigrateObjectService`, `OrphanScanService`, profile `insert` (2nd+ row) reject on lite | `ProFeatureGateTest` |
| Pro add-on | Separate plugin `kazcode-universal-storage-pro` registers `ProModule` on `plugins_loaded` priority 9 | manual / activation dependency |

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
| Profile-scoped object operations | Object row profile drives delivery URL, signed URL, restore, materialization, verification, repair, and guarded remote delete; live Settings only select the destination for new uploads | `ProfileDeliveryUrlResolverTest`, `ProfileAffinityHotspotTest`, `ProfileAwareObjectOperationsTest` |
| Restore → local serve | Clear `_s3ms_*` meta + object rows after successful restore | `AttachmentRestorerTest` |
| Acceptance smoke | §36 infra checks (partial, retry, repair, queue, IA) | `test-v2-acceptance.php` |
| Superseded variants don't flip status to failed (fixed bug) | `AttachmentSyncDeriver::derive_status()` excludes `stale`/`deleted` object rows from the present/total roll-up, so a fully-offloaded attachment stays `offloaded` after Image Editor crop, Regenerate Thumbnails, or a storage-profile migration leaves the old variant row behind as `stale` — previously it incorrectly derived as `failed`, which broke `AttachmentUrlFilter` URL rewriting for that attachment (Scenario F) | `AttachmentSyncDeriverTest::test_stale_variant_is_excluded_from_roll_up` et al. |

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
