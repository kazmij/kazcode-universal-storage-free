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
| `local_storage_policy=keep_originals` | After verify, delete size variants only; original kept locally | `…::test_keep_originals_deletes_size_variants_only`, `CleanupLocalFilesTest::test_keep_originals_deletes_only_sizes` |
| `local_storage_policy=remote_only`, verify Head fails | Stay `offloaded`; set `_s3ms_last_error`; **keep** local | `…::test_verify_before_delete_true_skips_local_delete_when_head_fails`, `CleanupLocalFilesTest::test_verify_failure_skips_delete` |
| Verify before local delete | **Always required** (P5-T03); no opt-out toggle | `CleanupLocalFiles::maybe_cleanup` |

## Delete attachment keys (P0-T04)

Remote delete inventory = current metadata paths + **current** `object_prefix` via `S3KeyResolver`.  
`_s3ms_original_key` is a presence gate / UI value, **not** the full key list.

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

## Adopt existing (Phase 8)

| Behavior | Expected | Test |
|----------|----------|------|
| HEAD inventory only | Build `s3ms_objects` rows from manifest + HEAD; **never** Put/upload | `AdoptAttachmentServiceTest` |
| Legacy candidates | Batch targets offloaded attachments without object rows | `AdoptService::query_ids` |
| Transient HEAD error ≠ missing (fixed bug) | A HEAD failure that is not a confirmed 404 is skipped (counted as `errors`, row left unchanged) instead of being written as `remote_status=missing` — a transient outage during Adopt no longer falsely marks present objects as missing | `AdoptAttachmentServiceTest::test_transient_head_error_does_not_record_missing_row` |

## Free / Pro separation (Phase 9)

| Behavior | Expected | Test |
|----------|----------|------|
| Default plan | `Features::plan()` is `lite` (no Pro capabilities) unless `KAZUS_PLAN` is defined as `pro` or a Pro module is registered — a prior default-plan-is-pro bug meant this path was never exercised until the default flipped to lite | `FeaturesProTest::test_default_plan_is_lite_and_blocks_pro_features` |
| Lite plan | Pro-only keys (`multiple_profiles`, `storage_profile_migration`, `orphan_scan`, `advanced_health`, …) return false without Pro module | `FeaturesProTest::test_lite_plan_blocks_pro_features_without_module` |
| Pro module | `ModuleRegistry::has_pro_module()` unlocks Pro tier even on lite plan | `FeaturesProTest::test_pro_module_unlocks_features_on_lite_plan` |
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

## V2 foundation acceptance (post-P11)

| Criterion | Expected | Test |
|-----------|----------|------|
| Profile-scoped URLs | Object row profile drives delivery URL, not live Settings | `ProfileDeliveryUrlResolverTest` |
| Restore → local serve | Clear `_s3ms_*` meta + object rows after successful restore | `AttachmentRestorerTest` |
| Acceptance smoke | §36 infra checks (partial, retry, repair, queue, IA) | `test-v2-acceptance.php` |
| Superseded variants don't flip status to failed (fixed bug) | `AttachmentSyncDeriver::derive_status()` excludes `stale`/`deleted` object rows from the present/total roll-up, so a fully-offloaded attachment stays `offloaded` after Image Editor crop, Regenerate Thumbnails, or a storage-profile migration leaves the old variant row behind as `stale` — previously it incorrectly derived as `failed`, which broke `AttachmentUrlFilter` URL rewriting for that attachment (Scenario F) | `AttachmentSyncDeriverTest::test_stale_variant_is_excluded_from_roll_up` et al. |

## PHP

Requires **PHP 8.3+** (WordPress.org recommended baseline). Local APTA Docker: PHP 8.4.
