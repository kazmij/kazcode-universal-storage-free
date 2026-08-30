# KAZCODE Universal Storage — Architecture v2 Implementation Plan

> **Status:** Planning document only. Do **not** treat this as authorization to implement Phase 0+ until explicitly requested.  
> **Plugin version audited:** 1.1.0 (`kazcode-universal-storage.php`)  
> **Code root:** `app/wp-content/plugins/kazcode-universal-storage/` (PSR-4 `Kazcode\WpStorage\` → `includes/`; there is **no** `src/` directory)  
> **PHP target:** **8.3+** (WordPress.org recommended baseline, 2026). CI: 8.3 / 8.4 / 8.5.  
> **Git:** develop on **local-only** branches (`local/kazcode-universal-storage*`); do **not** push this plugin to `apta` / `origin` (see `docs/LOCAL-ONLY.md`).  
> **Evidence tags:** `CONFIRMED` = verified in code; `INFERENCE` = reasoned from code; `OPEN` = needs decision/spike; `SPEC DEVIATION` = deliberate divergence from the planning prompt.

**Priorities (in order):** 1) No media loss 2) Correctness 3) Recoverability 4) Backward compatibility 5) Observability 6) Performance 7) Maintainability 8) UX 9) New features

---

## 1. Executive summary

KAZCODE Universal Storage today is a capable **attachment-level** offload plugin: WordPress remains source of truth; `_wp_attached_file` stays relative; uploads stream to S3-compatible storage; HeadObject verification gates “offloaded”; optional local delete; URL/srcset rewrite; restore; WP-Cron background migration; encrypted secrets; Lite/Pro feature flags; Media Library + WP-CLI + REST batch UI.

It is **not** yet a commercial multi-object, multi-profile storage product. Critical gaps:

1. Status is attachment-coarse (`_s3ms_status`) with no per-object persistence.
2. A single live `s3ms_settings` blob drives **all** URL generation and key prefixing — changing bucket/CDN/prefix retargets already-offloaded media.
3. Mid-batch Put failures leave **remote orphans** with no rollback inventory.
4. Background work is one option + soft `running` flag (WP-Cron), not a durable queue.
5. AWS SDK is globally namespaced (`Aws\`) — collision risk with other plugins.
6. No Backblaze B2 preset; no WebP/AVIF/optimizer extension points; no provider-to-provider migration; no Storage Profiles.

**v2 strategy:** evolutionary KEEP/EXTEND of `S3Storage`, resolvers, offload/verify safety spine, ML/CLI surfaces — plus **Storage Profiles**, **`s3ms_objects` + MediaManifest**, derived attachment state, durable jobs (Action Scheduler preferred), health/repair, adopt-existing, Free core + Pro add-on, PHP-Scoper release builds.

**First implementation phase after this doc:** Phase 0 characterization tests only. No schema, no AS, no behavior change until requested.

---

## 2. Current architecture / AS-IS

### 2.1 Bootstrap

| Item | CONFIRMED fact |
|------|----------------|
| Entry | `kazcode-universal-storage.php` → `plugins_loaded` → `s3ms_bootstrap()` → `Plugin::boot()` |
| Activate | `Settings::ensure_defaults()` only (`Plugin` / register_activation_hook) |
| Deactivate | Empty (`Plugin::deactivate`) |
| Uninstall | `uninstall.php`: deletes `s3ms_settings`, `s3ms_encrypted_secret`, selected `_s3ms_*` postmeta; **never** deletes S3; does **not** clear network settings, audit log, background job, locks, `_s3ms_ignored` |
| Namespace | `Kazcode\WpStorage\` |
| AWS | `composer.json`: `aws/aws-sdk-php ^3.300`; runtime global `Aws\`; **no PHP-Scoper** |

### 2.2 Upload / offload flow (actual)

```text
WordPress upload
    ↓
WP generates attachment metadata
    ↓
wp_generate_attachment_metadata @20  → AttachmentOffloader::on_generate_metadata
wp_update_attachment_metadata @20    → AttachmentOffloader::on_update_metadata (skip if in_generate; skip if uploading+locked)
    ↓
AttachmentOffloader::maybe_offload
  gates: enabled + aws configured; skip if already offloaded unless force
    ↓
AttachmentLock::acquire(id, 'migrate')  // option s3ms_lock_{id}, TTL 300s
    ↓
status = uploading; clear _s3ms_last_error
    ↓
AttachmentFileResolver::existing_local_files (metadata-scoped paths only)
    ↓
for each file: S3Storage::upload_file → S3KeyResolver::resolve(prefix + relative)
    ↓
for each uploaded relative: S3Storage::head_relative (HeadObject)
  any missing → throw → status=failed (uploaded keys NOT deleted)
    ↓
mark _s3ms_status=offloaded, _s3ms_original_key, _s3ms_offloaded_at, _s3ms_verified_at
    ↓
if delete_local_after_upload:
  if verify_before_delete: second Head pass (optional setting)
  AttachmentOffloader::delete_local_files (wp_delete_file under uploads jail)
    ↓
AttachmentLock::release
```

**CONFIRMED:** Image sizes already exist when offload runs (hooks after WP metadata generation).

### 2.3 URL generation

```text
wp_get_attachment_url / image_src / image_downsize / srcset / prepare_for_js / rest_prepare_attachment
    ↓
AttachmentUrlFilter (serve_from_s3 AND _s3ms_status === offloaded)
    ↓
S3KeyResolver::resolve(relative) using CURRENT Settings.object_prefix
    ↓
if private_media → S3Storage::presigned_url
else PublicUrlResolver::url_for_key (cdn_url → public_base_url → default S3 URL from CURRENT bucket/region/endpoint)
```

**CONFIRMED:** No per-attachment storage location snapshot. Settings changes immediately change all rewritten URLs.

### 2.4 Migration

```text
Admin REST / CLI / BackgroundMigrator
    ↓
MigrationService::query_ids (cursor after_id; pending = NOT EXISTS | pending | uploading)
    ↓
AttachmentOffloader::offload per ID
    ↓
BackgroundMigrator persists s3ms_background_job + cron s3ms_background_tick (schedule s3ms_minute)
```

### 2.5 Verification

```text
VerificationService::verify
  → relative_paths + Head each
  → statuses: bad_metadata | local_only | s3_only | partial_offload | missing_s3_original | missing_s3_thumbnail | OK
  → may set _s3ms_verified_at / force status offloaded
```

**CONFIRMED:** Verify is existence Head, not checksum compare. `S3Storage::head_key` maps **any** Throwable to `exists: false` (403 ≡ missing).

### 2.6 Restore

```text
AttachmentRestorer::restore
  → lock restore
  → for each relative: if local missing and remote exists → GetObject stream to uploads path
  → clear S3 meta (return to local serving)
```

### 2.7 Attachment deletion

```text
delete_attachment @5 → AttachmentRestorer::on_delete_attachment
  if enabled + delete_remote_on_delete + (status OR original_key)
  → AttachmentFileResolver::relative_paths
  → S3Storage::delete_relatives (explicit keys only; never recursive prefix)
```

**CONFIRMED:** `_s3ms_original_key` is presence gate / UI, not the full delete inventory. Keys recomputed with **current** prefix.

### 2.8 Credentials

```text
credential_mode=keys → access_key_id + EncryptionService decrypt(s3ms_encrypted_secret)
credential_mode=iam_role → omit credentials on S3Client (SDK default chain)
```

Envelope: `s3ms:v1:sodium:` or AES-GCM fallback; key = HKDF from `AUTH_KEY|SECURE_AUTH_KEY|AUTH_SALT|SECURE_AUTH_SALT`. Salt change → decrypt throws → empty secret (silent).

### 2.9 Background processing

Option `s3ms_background_job` fields: `running`, `action` (`migrate|verify|retry|restore`), `after_id`, `processed`, `success`, `failed`, `started_at`, `updated_at`, `last_error`, `finished_at`. Soft busy check on start; **no tick lease lock** (`CONFIRMED` gap).

---

## 3. Existing repository map

### 3.1 Classes (`includes/`)

| Path | Class | Responsibility | v2 fate |
|------|-------|----------------|---------|
| `Plugin.php` | `Plugin` | Bootstrap | EXTEND |
| `Admin/AdminMenu.php` | `AdminMenu` | Menus | REFACTOR (IA) |
| `Admin/SettingsPage.php` | `SettingsPage` | Settings + AJAX test | REFACTOR |
| `Admin/MigrationPage.php` | `MigrationPage` | Tools UI | REFACTOR → Media/Migration |
| `Admin/DiagnosticsPage.php` | `DiagnosticsPage` | Health/audit UI | EXTEND → Health |
| `Admin/SetupWizardPage.php` | `SetupWizardPage` | Wizard | KEEP/EXTEND |
| `Admin/NetworkSettingsPage.php` | `NetworkSettingsPage` | Network defaults | EXTEND |
| `Admin/MediaLibraryColumn.php` | `MediaLibraryColumn` | ML column/actions | EXTEND |
| `Admin/AttachmentDetailsPanel.php` | `AttachmentDetailsPanel` | Attachment edit panel | EXTEND |
| `Attachment/AttachmentOffloader.php` | `AttachmentOffloader` | Offload orchestrator | REFACTOR → application service |
| `Attachment/AttachmentFileResolver.php` | `AttachmentFileResolver` | Path discovery from WP meta | EXTEND → ManifestBuilder core |
| `Attachment/AttachmentUrlFilter.php` | `AttachmentUrlFilter` | URL rewrite | REFACTOR (profile-aware) |
| `Attachment/AttachmentRestorer.php` | `AttachmentRestorer` | Restore + delete remote | EXTEND |
| `Attachment/LocalFileProvider.php` | `LocalFileProvider` | On-demand local materialize | KEEP/EXTEND |
| `CLI/CliCommand.php` | `CliCommand` | `wp universal-storage` | EXTEND |
| `Core/Settings.php` | `Settings` | Options | REFACTOR (compat over Profiles) |
| `Core/EncryptionService.php` | `EncryptionService` | Secret encrypt | EXTEND |
| `Core/Features.php` | `Features` | Lite/Pro gates | DEPRECATE → module API |
| `Core/ProviderPresets.php` | `ProviderPresets` | Presets | EXTEND (+ B2) |
| `Infrastructure/AttachmentLock.php` | `AttachmentLock` | Per-attachment lock | KEEP/EXTEND |
| `Infrastructure/BatchProcessor.php` | `BatchProcessor` | REST `kazcode-storage/v1/*` | EXTEND |
| `Services/MigrationService.php` | `MigrationService` | Batch migrate/verify/restore | EXTEND |
| `Services/BackgroundMigrator.php` | `BackgroundMigrator` | WP-Cron jobs | REPLACE (AS adapter) |
| `Services/VerificationService.php` | `VerificationService` | Verify | EXTEND |
| `Services/FailedItemsService.php` | `FailedItemsService` | Failed list/CSV | EXTEND |
| `Services/HealthCheckService.php` | `HealthCheckService` | Diagnostics | EXTEND → Health |
| `Services/AuditLog.php` | `AuditLog` | Ring buffer (max 200) | EXTEND |
| `Services/ConnectionTestService.php` | `ConnectionTestService` | Put/Head/Delete probe | KEEP |
| `Services/AwsAssistant.php` | `AwsAssistant` | IAM policy helper | KEEP |
| `Storage/S3Storage.php` | `S3Storage` | Put/Head/Get/Delete/presign | EXTEND (profile client) |
| `Storage/S3ClientFactory.php` | `S3ClientFactory` | Client build | EXTEND |
| `Storage/S3KeyResolver.php` | `S3KeyResolver` | Prefix + path | REFACTOR → ObjectKeyService |
| `Storage/PublicUrlResolver.php` | `PublicUrlResolver` | Public/CDN URL | REFACTOR → DeliveryResolver |
| `Storage/PathGuard.php` | `PathGuard` | Path jail/normalize | KEEP |

### 3.2 REST (`BatchProcessor`, namespace `kazcode-storage/v1`, cap `manage_options`)

`GET /stats`, `POST /migrate-batch`, `POST /verify-batch`, `POST /restore-batch`, `POST /test-connection`, `GET /failed`, `POST /failed/ignore`, `GET /failed/export`, `GET /background`, `POST /background/start`, `POST /background/stop`, `GET /health`, `GET /audit`.

### 3.3 WP-CLI

`wp universal-storage status|test|migrate|verify|retry_failed|restore`

### 3.4 Cron

- Hook: `s3ms_background_tick`
- Schedule: `s3ms_minute` (60s)

### 3.5 Tests

| File | Coverage |
|------|----------|
| `tests/Unit/EncryptionServiceTest.php` | Encrypt roundtrip |
| `tests/Unit/PathGuardTest.php` | Path safety |
| `tests/Unit/S3KeyResolverTest.php` | Keys |
| `tests/Unit/PublicUrlResolverTest.php` | URLs |
| `tests/Unit/SettingsTest.php` | Settings helpers |
| `tests/Unit/MetadataParserTest.php` | Size path assembly |
| `tests/eval-file/*` | WP smoke / dry compat |
| `test-product-features-local.php` | Local product checks |
| `tests/MANUAL-SCENARIOS.md` | Manual QA |

**CONFIRMED gap:** No integration tests for partial offload, local delete, delete_attachment remotes, background resume, provider switch.

---

## 4. Current data model

### 4.1 Attachment postmeta

| Meta key | Written by | Read by | Notes |
|----------|------------|---------|-------|
| `_s3ms_status` | Offloader (`uploading`/`offloaded`/`failed`); VerificationService may set `offloaded` | UrlFilter, Restorer, Migration, Failed, ML UI | Constants also define `pending` but **never written** by offload (`CONFIRMED`) |
| `_s3ms_original_key` | Offloader (main file key or first uploaded) | Restorer delete gate, Details panel | Not full inventory |
| `_s3ms_offloaded_at` | Offloader | Details panel | ISO8601 gmdate |
| `_s3ms_verified_at` | Offloader; VerificationService | Migration stats | |
| `_s3ms_last_error` | Offloader fail / delete-skip | FailedItems, Details | |
| `_s3ms_ignored` | FailedItemsService | Failed list, ML | Not removed on uninstall |

### 4.2 Options / transients

| Key | Purpose |
|-----|---------|
| `s3ms_settings` | Main settings |
| `s3ms_encrypted_secret` | Encrypted secret |
| `s3ms_network_settings` | Multisite network defaults |
| `s3ms_background_job` | Background cursor |
| `s3ms_audit_log` | Audit ring (≤200) |
| `s3ms_lock_{id}` | Attachment lock |
| transient `s3ms_wizard_nudge` | Wizard redirect throttle |

### 4.3 Custom tables

**None** (`CONFIRMED`).

### 4.4 Settings keys (defaults)

`enabled`, `serve_from_s3`, `access_key_id`, `region`, `bucket`, `endpoint`, `force_path_style`, `object_prefix`, `public_base_url`, `cdn_url`, `cdn_includes_prefix`, `keep_local_files`, `delete_local_after_upload`, `verify_before_delete`, `delete_remote_on_delete`, `cache_control`, `provider_preset`, `credential_mode`, `private_media`, `signed_url_ttl`, `background_batch_size`, `inherit_network_settings`, `setup_wizard_completed`, `compat_elementor`, `compat_acf`, `compat_gutenberg`.

---

## 5. Current media lifecycle

| Event | Behavior |
|-------|----------|
| New upload | Metadata → offload all existing locals → verify Heads → meta → optional local delete |
| Image editor | `LocalFileProvider` may materialize; `wp_update_attachment_metadata` force re-offload |
| Regenerate Thumbnails | No dedicated hook; relies on metadata update + existing locals |
| Attachment delete | Explicit key delete from current metadata+prefix |
| Migrate library | Cursor batches; skips `offloaded`/`failed` in default queue; retry action for failed |
| Serve | Rewrite only when status `offloaded` |

---

## 6. Existing strengths to preserve

1. WordPress remains SoT; relative `_wp_attached_file`.
2. Local delete only after successful offload path (and default `verify_before_delete=true`).
3. Non-recursive remote delete (`S3Storage::delete_keys` documents this).
4. Stream Put/Get (fopen), not full file in PHP memory.
5. `AttachmentFileResolver` does not walk uploads directories.
6. `PathGuard` jail against traversal.
7. Encryption envelope versioning concept.
8. Media Library column/actions, CLI command names, connection test, audit log skeleton.
9. Provider presets UX (aws/r2/spaces/minio/wasabi/custom).
10. Multisite network defaults merge pattern.

---

## 7. Problems / architectural limitations

1. **Coarse status** — cannot represent partial object success.
2. **No object inventory** — delete/verify rediscover paths; orphans invisible.
3. **Single live config** — provider/bucket/prefix/CDN change breaks old media URLs/deletes.
4. **Partial Put orphans** — no compensation delete of `$uploaded_keys`.
5. **Optional verify_before_delete** — can skip second Head (upload Heads still required for offloaded).
6. **Head error taxonomy** — all errors → missing.
7. **Weak queue** — soft `running`; overlapping ticks possible.
8. **Migration not content-idempotent** — re-PUT rather than skip-if-matching Head.
9. **No Storage Profiles / multi-provider runtime**.
10. **No provider↔provider migration**.
11. **No WebP/AVIF/optimizer hooks**.
12. **Unscoped AWS SDK**.
13. **Lite/Pro via scattered `Features::enabled`**; `migrate_existing` / `signed_urls` listed but unused as gates.
14. **Default `S3MS_PLAN=pro`** — Free packaging unclear.
15. **Destructive paths under-tested**.
16. **Uninstall incomplete** (locks, audit, network, ignored meta).

---

## 8. Gap matrix

| Capability | Current implementation | Quality | Target | Work |
|------------|------------------------|---------|--------|------|
| S3 upload | `S3Storage::upload_file` / `AttachmentOffloader` | good | object-aware + idempotent | EXTEND |
| URL rewrite | `AttachmentUrlFilter` + live Settings | risky | profile-scoped `DeliveryResolver` | REFACTOR |
| Manifest | `AttachmentFileResolver` path list | partial | `MediaManifest` + variant providers | NEW/EXTEND |
| Object state | attachment meta only | insufficient | `{$prefix}s3ms_objects` | NEW |
| Queue | `BackgroundMigrator` WP-Cron | partial | Action Scheduler (+ CronAdapter) | REPLACE |
| Restore | `AttachmentRestorer` | good | scopes + detach | EXTEND |
| Verify | `VerificationService` | partial | per-object + error taxonomy | EXTEND |
| Storage profiles | single `Settings` | missing | `s3ms_storage_profiles` | NEW |
| Provider migration | N/A | missing | stream/copy + verify then switch | NEW |
| Credentials | `EncryptionService` + option | good | master key + per-profile refs + salt UX | EXTEND |
| Pro separation | `Features` + `S3MS_PLAN` | partial | core plugin + pro add-on API | REFACTOR |
| B2 preset | absent | missing | named preset | NEW |
| WebP/AVIF | WP metadata only | weak | `s3ms_manifest_files` filter | NEW |
| Health/repair | `HealthCheckService` | partial | object reconcile | EXTEND |
| Dependency isolation | unscoped vendor | risky | PHP-Scoper release | NEW |
| Local policy | keep/delete/verify toggles | confusing | KEEP_ALL / KEEP_ORIGINALS / REMOTE_ONLY | REFACTOR |
| Adopt existing | N/A | missing | HEAD-based adopt | NEW |
| Multisite | network option merge | partial | profiles per-site + network templates | EXTEND |
| Audit | `AuditLog` 200 ring | partial | structured events + retention | EXTEND |
| Object key canonicalization | `S3KeyResolver` + `PathGuard` | good | single ObjectKeyService | REFACTOR |

---

## 9. Target architecture / TO-BE

```text
WordPress Attachment (SoT: posts + _wp_* meta)
        │
        ▼
ManifestBuilder  ← AttachmentFileResolver + variant providers/filters
        │
        ▼
MediaManifest (deterministic object list)
        │
        ▼
ObjectRepository  → {$wpdb->prefix}s3ms_objects
        │
        ├── Offload / Verify / CleanupLocal / Restore / Repair
        ├── MigrateObject (profile A → B)
        └── Reconcile / HealthScan
        │
        ▼
StorageProfile  → StorageProvider (S3Storage evolved) → DeliveryResolver
        │
        ▼
Durable Queue (Action Scheduler preferred)
```

**Invariants (must remain true):**

1. WP owns attachment posts and `_wp_*` metadata.
2. `_wp_attached_file` stays relative (e.g. `2026/08/photo.jpg`).
3. Native Media Library / Gutenberg / srcset must not regress.
4. Never delete local files before full verification of **required** manifest objects.
5. Never recursively delete arbitrary S3 prefixes — only positively identified keys.
6. Scope remains Media Library binaries — **not** theme/plugin assets or full-site CDN (`NON-GOAL`).

---

## 10. Target domain model

| Concept | Responsibility | Persistence | Public ops |
|---------|----------------|-------------|------------|
| `StorageProfile` | Named storage + delivery config | `s3ms_storage_profiles` | CRUD, testConnection, markDefaultUpload, freezeLocation |
| `CredentialRef` | Opaque pointer to encrypted secret bag | options keyed by profile uuid | get/set/rotate |
| `ObjectKey` | Canonical key value object | column `object_key` | normalize, join prefix |
| `LocalRelativePath` | Uploads-relative path | column | normalize via PathGuard |
| `MediaManifest` | Expected objects for attachment | ephemeral / rebuilt | build, diff |
| `MediaObject` / row | One physical file state | `s3ms_objects` | upsert, transition status |
| `AttachmentSyncState` | Derived rollup | cached `_s3ms_status` | derive, refresh |
| `StorageProvider` | put/streamPut/get/head/exists/delete/copy?/listScoped/test/presign | via S3 client | — |
| `DeliveryResolver` | Public or signed URL | profile delivery fields | urlFor(object) |
| `ManifestBuilder` | Discover expected files | — | build(attachmentId) |
| `ObjectKeyService` | Single prefix+path authority | — | keyFor(profile, relative) |
| `QueueGateway` | Enqueue/cancel/status | AS or adapter | — |

Do **not** invent a parallel external media DB. Value objects only where they prevent real bugs (keys, paths, status enums).

---

## 11. Target database schema

### 11.1 `{$wpdb->prefix}s3ms_storage_profiles`

```sql
CREATE TABLE {$wpdb->prefix}s3ms_storage_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  name VARCHAR(191) NOT NULL,
  provider_type VARCHAR(32) NOT NULL,
  bucket VARCHAR(255) NOT NULL DEFAULT '',
  region VARCHAR(64) NOT NULL DEFAULT '',
  endpoint VARCHAR(255) NOT NULL DEFAULT '',
  path_style TINYINT(1) NOT NULL DEFAULT 0,
  prefix VARCHAR(255) NOT NULL DEFAULT '',
  delivery_type VARCHAR(16) NOT NULL DEFAULT 'storage', -- storage|cdn
  delivery_base_url TEXT NULL,
  cdn_includes_prefix TINYINT(1) NOT NULL DEFAULT 0,
  credential_mode VARCHAR(16) NOT NULL DEFAULT 'keys', -- keys|iam|constants
  credentials_ref VARCHAR(64) NOT NULL DEFAULT '',
  is_default_upload_target TINYINT(1) NOT NULL DEFAULT 0,
  is_read_only TINYINT(1) NOT NULL DEFAULT 0,
  location_locked TINYINT(1) NOT NULL DEFAULT 0, -- set when objects exist
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uuid (uuid),
  KEY provider_type (provider_type),
  KEY is_default_upload_target (is_default_upload_target)
);
```

Secrets **not** stored in this table — encrypted option map `s3ms_profile_secrets` (or per-uuid options) via `EncryptionService`.

### 11.2 `{$wpdb->prefix}s3ms_objects`

```sql
CREATE TABLE {$wpdb->prefix}s3ms_objects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id BIGINT UNSIGNED NOT NULL,
  storage_profile_id BIGINT UNSIGNED NOT NULL,
  local_relative_path VARCHAR(512) NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  variant_type VARCHAR(32) NOT NULL DEFAULT 'size', -- original|size|backup|webp|avif|other
  mime_type VARCHAR(100) NOT NULL DEFAULT '',
  size_bytes BIGINT UNSIGNED NULL,
  etag VARCHAR(128) NULL,
  checksum VARCHAR(128) NULL,
  local_status VARCHAR(16) NOT NULL DEFAULT 'unknown', -- present|missing|unknown
  remote_status VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending|uploading|present|missing|failed|stale|deleted
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) NULL,
  last_error_message TEXT NULL,
  offloaded_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY profile_object_key (storage_profile_id, object_key(191)),
  KEY attachment_id (attachment_id),
  KEY remote_status (remote_status),
  KEY updated_at (updated_at),
  KEY attachment_remote (attachment_id, remote_status),
  KEY profile_attachment (storage_profile_id, attachment_id)
);
```

**Uniqueness recommendation:** `UNIQUE(storage_profile_id, object_key)` — one physical key per profile/bucket identity. `attachment_id` is an attribute (handles meta drift / reassignment detection).  
Alternative `UNIQUE(attachment_id, storage_profile_id, object_key)` allows two attachments claiming same key (dangerous). Prefer first.

**Multisite:** per-site tables via `$wpdb->prefix` (`SPEC DEVIATION` from shared network table). Attachment IDs are not network-unique.

### 11.3 Additional tables

**Not required initially.** Prefer Action Scheduler tables for jobs. Optional later: `s3ms_health_scans` if scan progress outgrows options.

### 11.4 Schema version

Option `s3ms_schema_version` + upgrade routine on `plugins_loaded` / activation — **must stay fast** (CREATE TABLE only; data backfill async).

---

## 12. Storage Profile design

### 12.1 Purpose

Bind every remote object to the profile that owns it so administrators can:

- Point **new uploads** at Cloudflare R2 while
- **Old AWS objects** continue resolving via the AWS profile until migrated.

### 12.2 Lifecycle

1. **Upgrade:** create profile “Legacy Default Storage” from current `s3ms_settings` + secret ref.
2. **Backfill:** object rows (and attachment binding) reference that profile.
3. **New profile:** admin creates R2 profile; marks as default upload target.
4. **Config change with existing objects:** UI offers *future uploads only* | *migrate* | *cancel* — never silent mutation of location fields on a locked profile.
5. **Mutability:** once `location_locked` (objects exist), freeze `provider_type`, `bucket`, `endpoint`, `prefix`, `path_style`, `region` (region freeze recommended). `delivery_base_url` / `delivery_type` remain editable.
6. **Read-only profiles:** staging protection — GET/HEAD allowed; PUT/DELETE blocked in provider gateway.

### 12.3 Multisite

- Profiles are **per-site** (site tables).
- Network defaults may seed template field values (today’s `s3ms_network_settings` merge) — not shared object ownership.
- Secrets remain per-site (`CONFIRMED` current behavior).

---

## 13. Media Manifest design

### 13.1 Builder inputs (authoritative)

1. `_wp_attached_file`
2. `_wp_attachment_metadata` (`file`, `sizes[*].file`, `original_image`)
3. `_wp_attachment_backup_sizes` when appropriate (editor history)

### 13.2 Extension inputs

- Filter `s3ms_manifest_files` (array of relative paths + variant_type + mime)
- Optional `MediaVariantProviderInterface` registered by Pro/compat modules (ShortPixel, Imagify, EWWW, Converter for Media — **not** recursive directory scans)

### 13.3 Determinism

Same attachment meta + registered providers ⇒ same ordered manifest. Builder must not invent files from filesystem walks.

### 13.4 Diff

```text
OLD MANIFEST vs NEW MANIFEST
  new      → ensure object row pending → upload
  changed  → replace/upload (size/etag mismatch)
  removed  → mark remote_status=stale (do NOT immediate delete)
```

---

## 14. Object state machine

**Remote statuses (recommended enums in code constants):**

| Status | Meaning |
|--------|---------|
| `pending` | Expected; not yet uploaded |
| `uploading` | Put in flight |
| `present` | Put succeeded; Head not yet OK **or** present unverified — prefer splitting: after verify → still `present` with `verified_at` set |
| `missing` | Expected but Head says absent |
| `failed` | Terminal attempt failure (retry policy) |
| `stale` | No longer in manifest; awaiting controlled cleanup |
| `deleted` | Known remote delete completed |

**Local statuses:** `present` | `missing` | `unknown`

Transitions must be persisted before destructive local ops.

---

## 15. Attachment derived state

| State | Semantics |
|-------|-----------|
| `local` | Not managed / no required remote present |
| `pending` | Manifest exists; no required object present yet |
| `processing` | Jobs in flight for attachment |
| `partial` | ≥1 required remote present AND ≥1 required missing/failed |
| `offloaded` | All required objects remote present + verified |
| `failed` | Retry policy exhausted for required work |
| `restoring` | Restore job in flight |

`_s3ms_status` becomes a **cache** of derived state for ML performance and backward compat.

### Legacy meta mapping (required table)

| Meta key | Current responsibility | v2 responsibility | Action |
|----------|------------------------|-------------------|--------|
| `_s3ms_status` | Attachment lifecycle | Derived cache | KEEP as cache; backfill writer |
| `_s3ms_original_key` | Main object key display/gate | Cache of original row key on active profile | KEEP during transition; prefer objects table |
| `_s3ms_offloaded_at` | When marked offloaded | Cache (e.g. max object offloaded_at) | KEEP cache |
| `_s3ms_verified_at` | Last verify | Cache | KEEP cache |
| `_s3ms_last_error` | Last error string | Rollup of object errors | KEEP cache |
| `_s3ms_ignored` | Skip failed retry | Unchanged skip flag | KEEP |

---

## 16. Queue design

### Recommendation

**Action Scheduler** as primary durable queue (`OPEN` packaging: Composer suggest vs bundle for wp.org). Provide `CronQueueAdapter` wrapping current `BackgroundMigrator` for environments without AS so Free core still works.

### Required properties

Persistent jobs, retry/backoff, idempotency, locking/claims, pause/resume, progress, failure history, crash recovery.

### Job types

| Job | Grain | Notes |
|-----|-------|-------|
| `OffloadAttachment` | attachment | Builds manifest; enqueues/does object puts |
| `VerifyAttachment` | attachment | Head all required objects |
| `ReconcileAttachment` | attachment | Diff manifest vs rows |
| `RestoreAttachment` | attachment | Download missing locals |
| `DeleteRemoteObject` | object | Idempotent delete known key |
| `CleanupLocalFiles` | attachment | Only after full verify + policy |
| `MigrateObject` | object | A→B stream/copy |
| `MigrateAttachment` | attachment | Orchestrates object migrates |
| `ScanStorage` | scan cursor | Async LIST under prefix |
| `RepairObject` | object | Re-upload or restore |
| `BackfillObjects` | batch | Legacy meta → rows |

### Idempotency (summary)

- **Offload:** if remote present + size/etag match → skip Put.
- **Verify:** side-effect free except status timestamps.
- **Restore:** if local present + size match → skip.
- **Delete:** second delete of missing key = success.
- **Migrate:** resume from object row destination status.

### Concurrency

- Keep/extend `AttachmentLock`.
- AS claim tokens for jobs.
- Global migration run group lock.
- Image editor + offload: lock + reconcile after metadata update.
- Delete while uploading: cancel jobs → delete known keys from object rows (not rediscovery alone).

---

## 17. Lifecycle synchronization

Hooks to retain/extend:

| Hook | Today | v2 |
|------|-------|----|
| `wp_generate_attachment_metadata` | offload | enqueue OffloadAttachment |
| `wp_update_attachment_metadata` | force offload | ReconcileAttachment + Offload diff |
| `delete_attachment` | delete_relatives | cancel jobs + DeleteRemoteObject for **known rows** |
| LocalFileProvider hooks | materialize | KEEP; profile-aware Get |

No immediate remote delete of stale objects — queue controlled cleanup after report/dry-run where destructive.

WebP/AVIF: extension filter only in foundation; dedicated Pro integrations later.

---

## 18. Provider migration design

```text
source object (profile A)
  → stream Get (or CopyObject if same-provider compatible — OPEN spike)
  → Put destination (profile B)
  → Head/verify destination
  → persist destination object row + ownership
  → only then switch delivery binding for attachment/objects
  → optional later: DeleteRemoteObject on source (separate deliberate op)
```

Requirements: resumable, partial OK, retries, progress, pause/cancel, never serve B until verified, never load whole file into memory.

Admin must not change default upload profile mid-run without migration lock.

---

## 19. Restore architecture

Preserve `AttachmentRestorer`; expand scopes:

- Single / selected / missing-local-only / all media
- **Restore and detach:** download → verify local complete → stop URL rewrite (status local) → mark remotes detachable (optional later delete)

**Never** switch serving to local before required files restored.

---

## 20. Reconcile / Repair architecture

Dashboard counters from **SQL aggregates** + cached options (refresh on job completion / hourly cron) — **no** full bucket LIST on every admin request.

Health states: healthy, remote_missing, local_missing, metadata_mismatch, failed_upload, unverified, stale, possible_orphan (from scoped LIST jobs only).

Ops: Scan, Verify, Repair selected/all-safe, Re-upload missing, Restore missing local, Rebuild manifest, Retry failed, Find orphans, Dry-run orphan cleanup.

Orphan rules: respect prefix; never delete unknown keys outside known inventory without explicit confirm; distinguish stale-known vs true orphan vs foreign app objects.

---

## 21. Credential architecture

### Precedence (recommended)

1. Runtime instance role (`credential_mode=iam`)
2. wp-config / env constants (names: prefer `S3MS_ACCESS_KEY_ID`, `S3MS_SECRET_ACCESS_KEY`, `S3MS_BUCKET` — align with any existing constants before inventing; currently secrets are option-based — `CONFIRMED`)
3. Encrypted DB credentials per profile

### Master key

Optional `S3MS_MASTER_KEY` (or ENV) preferred over WP salts when defined; salts remain fallback. Version ciphertext `s3ms:v1:sodium:` already exists — extend with key-source id in envelope if needed for rotation.

### Salt-change UX

Distinguish `credentials_decrypt_failed` vs `provider_auth_failed` in connection test and health. Never log secrets or raw signed URLs.

---

## 22. Dependency isolation / build architecture

| Concern | Plan |
|---------|------|
| Dev | Composer normal `Aws\` for PHPUnit |
| Release | PHP-Scoper → `S3MS\Vendor\Aws\...`, `S3MS\Vendor\GuzzleHttp\...` |
| Autoload | Scoped classmap in release ZIP |
| Artifact | `kazcode-universal-storage-{version}.zip` (+ Pro ZIP later) |
| Licenses | Include vendor license notices |
| Tests | CI uses unscoped; release smoke uses scoped build |

**CONFIRMED today:** unscoped; collision possible.

---

## 23. Multisite strategy

| Concern | Decision |
|---------|----------|
| Object/profile tables | Per-site `$wpdb->prefix` |
| Attachment IDs | Site-local only |
| Network defaults | Template settings → seed site profiles; not shared rows |
| CLI | Operate on current blog; `--url` for target site |
| Queue | Per-site AS groups |
| Caps | `manage_options` / network admin for network pages |

---

## 24. Free / Pro architecture

### Packaging

- **Free/core:** `kazcode-universal-storage`
- **Pro add-on:** `kazcode-universal-storage-pro` (separate plugin; depends on core active + version)

### Extension API (core)

- Filters/actions: `s3ms_manifest_files`, `s3ms_register_modules`, feature providers
- Interfaces for variant providers, health reporters
- **Avoid** new `if (is_pro())` scattered in services — migrate off `Features::enabled` gradually

### Free scope (target)

AWS, R2, Spaces, Wasabi, MinIO, Generic, **Backblaze B2**, auto offload, WP sizes, URL rewrite/srcset, basic migrate/restore/verify, connection test, local policy, basic failed handling, WP-CLI basics, ML integration, **single** active upload profile (legacy).

### Pro scope (target)

Multiple profiles, provider↔provider migration, advanced health/orphans, advanced private/signed, optimizer integrations, advanced multisite, staging read-only, advanced audit/CLI, agency tooling.

**SPEC DEVIATION:** Do not move existing core capabilities to Pro merely because they exist; e.g. basic migrate/verify stay Free. Background AS may ship Free with degraded CronAdapter; Pro gets advanced queue UI.

Timing: design API early (P1–P2); physical plugin split in **Phase 9** after domain stable.

---

## 25. WP-CLI strategy

### Keep (compat)

`wp universal-storage status|test|migrate|verify|retry-failed|restore` (note: code method `retry_failed`)

### Add (later)

`health`, `repair`, `profiles list|test`, `storage-migrate`, `adopt`, `objects`, `queue`

Admin + CLI **must** call the same application services — no duplicated business logic.

---

## 26. Admin architecture

Target IA (implement in Phase 11, after domain):

```text
S3 Media
  Dashboard   — connection, counts from cache, queue, recent failures
  Media       — attachment browser / failed / bulk ops
  Storage     — profiles, delivery, credentials test
  Migration   — library offload + storage-to-storage
  Health      — reconcile/repair
  Logs        — audit + job errors
  Settings    — policy, environment, advanced
```

ML column shows Local/Remote/Partial/Failed/Profile/Last verified from **local DB only** (no Head on list render).

Delivery UX: Storage URL vs Custom domain/CDN with live preview URL; provider/prefix under Advanced.

---

## 27. Upgrade and legacy data migration

### Phase pattern

1. **Plugin update request:** create tables + seed Legacy Default Storage profile from `s3ms_settings` + secret ref; set `s3ms_schema_version`; schedule backfill; return fast.
2. **BackfillObjects jobs:** for attachments with `_s3ms_status=offloaded` (and optionally failed): build manifest → insert object rows bound to legacy profile → optional Head verify in batches.
3. **Dual-write period:** new offloads write objects **and** legacy meta cache.
4. **Readers:** URL filter prefers profile from object rows; fallback to Settings compat shim.
5. **Deprecation:** after N releases + CLI `s3-media meta purge-legacy` (future), stop writing redundant meta fields that are pure caches (optional).

Existing remote objects are **not** re-uploaded during backfill unless verify finds missing.

---

## 28. Security review

| Area | Finding / plan |
|------|----------------|
| Caps | Keep `manage_options` for REST/admin; revisit finer caps later |
| Nonces | Existing AJAX/admin_post — retain |
| Credentials | Encrypted; never log; redacted audit |
| Signed URLs | Do not persist in logs |
| Custom endpoint | SSRF risk — allowlist schemes/hosts; block link-local/metadata IPs |
| Prefix/path | PathGuard + ObjectKeyService; reject `..` |
| Local unlink | Only realpath under uploads basedir (`CONFIRMED` pattern) |
| Remote delete | Known keys only |
| CSV export | Escape formulas (`FailedItemsService`) |
| Multisite | Network vs site caps |
| REST | Already capped; add nonce where cookie auth used |

---

## 29. Performance / scaling review

Targets: 10k / 100k attachments; 1M object rows.

Avoid: loading all attachments; unindexed scans; LIST on dashboard; Head on ML list; sync migrate of whole library.

Plan: indexes as schema; keyset pagination (`after_id` already used — KEEP); batch sizes; cached counters option `s3ms_stats_cache`; async scans; object-level migrate jobs.

---

## 30. Compatibility / testing matrix

### PHP (WordPress-aligned)

| Tier | Versions | Policy |
|------|----------|--------|
| **Required (product)** | PHP **8.3+** | Matches wordpress.org *recommended* baseline (2026). Header `Requires PHP: 8.3`, Composer `>=8.3`. |
| **CI required** | 8.3, 8.4, 8.5 | Modern WordPress-supported line used in production hosting. |
| **Not targeted** | PHP 7.4–8.2 | WordPress may still *boot* on some of these, but they are EOL or below the recommended floor. **SPEC DEVIATION:** we do not polyfill down to WP’s absolute minimum. |

Local APTA CMS Docker (PHP 8.4) is a valid primary runtime for development.

### CI required (other)

- WP latest + one previous major (align with plugin `Requires at least`)
- Single-site unit + MinIO integration job
- Multisite smoke (profile table prefix)

### Manual release

Gutenberg, Classic Editor, Image Editor crop/rotate, Regenerate Thumbnails, Enable Media Replace, WooCommerce product images, Elementor (compat flag exists).

### Best-effort / future integration

ShortPixel, Imagify, EWWW, Converter for Media — via variant provider API; not mandatory CI.

### GD / Imagick

Both via WP image editors; tests should not assume one.

---

## 30a. Repository / distribution policy (APTA checkout)

**CONFIRMED decision (2026-08-25):** while embedded under `apta-cms`, KAZCODE Universal Storage stays on **local git branches only**.

- Active branch name: `local/kazcode-universal-storage`
- Do not push plugin-containing branches to `apta` or `origin`
- `apta/main` / `apta/stage` must not gain this plugin tree accidentally
- Optional MinIO Compose overlay (`docker-compose.minio.yml`) lives on the local branch only until product packaging moves elsewhere

Publishing as a standalone plugin repo / ZIP is a later product decision — out of scope for Phase 0–1 inside APTA CMS.

---

## 31. Risk register

| Risk | Severity | Likelihood | Existing affected code | Mitigation |
|------|----------|------------|------------------------|------------|
| Local delete without second Head | high | medium | `AttachmentOffloader` when `verify_before_delete=0` | Make verify invariant; remove toggle |
| Partial Put orphans | high | medium | Offloader catch without rollback | Object rows + repair; optional compensating delete of known uploaded keys on fail |
| Settings change breaks URLs | critical | high | `AttachmentUrlFilter` + live Settings | Storage Profiles + immutable location |
| Prefix change breaks deletes | high | medium | `on_delete_attachment` rediscovery | Delete from `s3ms_objects` keys |
| Coarse status hides missing sizes | high | high | `_s3ms_status=offloaded` | Derived from objects |
| Concurrent cron ticks | medium | medium | `BackgroundMigrator` | AS claims / tick lock |
| Queue duplication | medium | medium | soft `running` | Durable queue |
| Provider switch mid-migrate | high | low | N/A today | Migration lock + profile freeze |
| Multisite ID confusion | high | medium if shared tables | N/A | Per-site tables |
| Composer AWS collision | high | medium | unscoped vendor | PHP-Scoper |
| Salt change silent secret loss | high | medium | `Settings::get_secret_access_key` | Explicit decrypt error |
| Large backfill OOM/timeout | high | high on big sites | N/A | Async batches only |
| Head 403≡missing | medium | medium | `S3Storage::head_key` | Error taxonomy |
| Upgrade compat break | critical | medium | meta readers | Dual-write + fallback |
| Recursive delete introduced by mistake | critical | low | currently safe | Code review invariant + tests |
| Thumbnail regen race | high | medium | metadata force offload | Locks + reconcile |
| Data loss from REMOTE_ONLY bugs | critical | medium | delete_local path | Characterization tests P0 |

---

## 32. Implementation phases

| Phase | Goal | Depends |
|-------|------|---------|
| P0 | Characterization + destructive-path tests | — |
| P1 | Profiles, ObjectKeyService, Manifest, schema, legacy profile migrator | P0 |
| P2 | Object-level offload/verify; meta cache dual-write | P1 |
| P3 | Durable queue (AS) | P1–P2 |
| P4 | Lifecycle sync (regen, editor, variant filter) | P2–P3 |
| P5 | Local storage policy enum | P2 |
| P6 | Health / reconcile / repair | P2–P3 |
| P7 | Provider-to-provider migration | P1+P3+P6 |
| P8 | Adopt existing remote media | P2+P6 |
| P9 | Free/Pro add-on split | after P2 API stable |
| P10 | PHP-Scoper release pipeline | parallel after P0 |
| P11 | Admin IA / dashboard | after P6 |

---

## 33. Detailed tasks per phase

### Phase 0 — Safeguards and characterization

**Goal:** Lock current behavior with tests before structural change.  
**DB/hooks:** none.  
**Rollback:** delete tests only.

| ID | Task |
|----|------|
| P0-T01 | Inventory destructive call sites in PHPUnit data providers |
| P0-T02 | Unit test: offload failure after N-1 Puts does not call local delete |
| P0-T03 | Unit/integration: verify_before_delete false still documents behavior |
| P0-T04 | Test delete_attachment builds keys from resolver+prefix (mock storage) |
| P0-T05 | Test PublicUrlResolver uses current settings (bucket change URL change) |
| P0-T06 | Test BackgroundMigrator job shape / resume after_id |
| P0-T07 | Test EncryptionService salt/key mismatch fails closed |
| P0-T08 | Add MinIO docker-compose service for later phases (dev only; no prod change) |
| P0-T09 | Snapshot MANUAL-SCENARIOS expected results for P0 baseline |
| P0-T10 | Document CONFIRMED behaviors in `tests/CHARACTERIZATION.md` |

**Acceptance:** CI green; destructive paths have failing-safe characterization coverage.  
**Risks:** Flaky MinIO — keep P0 mostly mocked.

### Phase 1 — Domain foundations

**Goal:** Profiles + schema + manifest + key service + legacy seed.  
**Files affected:** `Settings.php`, `Plugin.php`, `S3KeyResolver.php`, `ProviderPresets.php`, new Domain/Infrastructure.  
**Hooks:** activation/upgrade only.

| ID | Task | Deps |
|----|------|------|
| P1-T01 | Add `s3ms_schema_version` option + `SchemaMigrator` | — |
| P1-T02 | Create `s3ms_storage_profiles` table migration | P1-T01 |
| P1-T03 | Create `s3ms_objects` table migration | P1-T01 |
| P1-T04 | `StorageProfile` entity + repository interface | P1-T02 |
| P1-T05 | `StorageProfileRepository` (wpdb) | P1-T04 |
| P1-T06 | Legacy settings → Legacy Default Storage profile migrator | P1-T05 |
| P1-T07 | Tests: legacy AWS settings → profile fields | P1-T06 |
| P1-T08 | `ObjectKeyService` wrapping PathGuard + prefix norms | — |
| P1-T09 | Route `S3KeyResolver` through ObjectKeyService (compat wrapper) | P1-T08 |
| P1-T10 | `MediaManifest` + `ManifestBuilder` from AttachmentFileResolver | — |
| P1-T11 | Tests: manifest from fixture metadata | P1-T10 |
| P1-T12 | `ObjectRepository` CRUD + unique key enforcement | P1-T03 |
| P1-T13 | Settings read fallback: if no profile, shim from options | P1-T06 |
| P1-T14 | Add Backblaze B2 to `ProviderPresets` | — |
| P1-T15 | Credential ref storage skeleton per profile uuid | P1-T06 |

**Acceptance:** Fresh install + upgrade from 1.1.0 settings creates profile; no behavior change to uploads yet (feature flag off).  
**Rollback:** drop new tables if empty; leave settings intact.

### Phase 2 — Object-level offload

**Goal:** New uploads write object rows; derived status; no false offloaded.  
**Hooks:** same metadata hooks; internals change behind flag `s3ms_object_offload` (default on after tests).

| ID | Task | Deps |
|----|------|------|
| P2-T01 | `OffloadAttachmentService` using manifest + object repo | P1 |
| P2-T02 | Per-object Put + status transitions | P2-T01 |
| P2-T03 | Per-object verify before attachment offloaded | P2-T02 |
| P2-T04 | Dual-write legacy `_s3ms_*` cache | P2-T03 |
| P2-T05 | Wire AttachmentOffloader to new service (thin facade) | P2-T04 |
| P2-T06 | Partial failure → attachment `partial`/`failed` + object errors | P2-T03 |
| P2-T07 | URL filter: resolve profile from object rows | P2-T04 |
| P2-T08 | Tests: partial thumbnail failure | P2-T06 |
| P2-T09 | Tests: retry only missing objects | P2-T06 |
| P2-T10 | Backfill job for existing offloaded attachments | P1-T12 |
| P2-T11 | CLI `wp universal-storage objects backfill` (or migrate flag) | P2-T10 |

### Phase 3 — Durable queue

| ID | Task | Deps |
|----|------|------|
| P3-T01 | Spike AS packaging decision; document in OPEN | — |
| P3-T02 | `QueueGateway` interface | — |
| P3-T03 | ActionSchedulerGateway implementation | P3-T01–T02 |
| P3-T04 | CronQueueAdapter wrapping BackgroundMigrator | P3-T02 |
| P3-T05 | Register job handlers | P3-T03 |
| P3-T06 | Migrate MigrationPage/REST to enqueue jobs | P3-T05 |
| P3-T07 | CLI queue status/run | P3-T05 |
| P3-T08 | Tests: idempotent re-run offload job | P3-T05 |
| P3-T09 | Deprecate direct tick processing path | P3-T06 |

### Phase 4 — Lifecycle synchronization

| ID | Task |
|----|------|
| P4-T01 | Reconcile on `wp_update_attachment_metadata` |
| P4-T02 | Stale object marking (no immediate delete) |
| P4-T03 | Filter `s3ms_manifest_files` |
| P4-T04 | Interface `MediaVariantProviderInterface` |
| P4-T05 | Tests: regenerate size added/removed |
| P4-T06 | Image editor crop produces new uploads |

### Phase 5 — Local storage policies

| ID | Task |
|----|------|
| P5-T01 | Enum KEEP_ALL / KEEP_ORIGINALS / REMOTE_ONLY |
| P5-T02 | Migrate settings from delete_local/keep_local/verify flags |
| P5-T03 | Remove optional verify_before_delete (always verify) |
| P5-T04 | CleanupLocalFiles job only after full verify |
| P5-T05 | Tests for each policy |

### Phase 6 — Reconcile / Repair

| ID | Task |
|----|------|
| P6-T01 | Stats cache aggregator from objects table |
| P6-T02 | HealthScan service (DB-first) |
| P6-T03 | RepairObject / RepairAttachment |
| P6-T04 | Orphan scan job (prefix LIST) dry-run |
| P6-T05 | REST/CLI health endpoints |
| P6-T06 | Tests for remote_missing repair when local exists |

### Phase 7 — Storage Profile migration

| ID | Task |
|----|------|
| P7-T01 | MigrateObject stream path |
| P7-T02 | Same-provider CopyObject spike | 
| P7-T03 | MigrateAttachment orchestrator |
| P7-T04 | Switch delivery only after verify |
| P7-T05 | Optional source delete job |
| P7-T06 | Admin/CLI storage-migrate |
| P7-T07 | Tests AWS→MinIO style migration |

### Phase 8 — Adopt existing

| ID | Task |
|----|------|
| P8-T01 | AdoptAttachment: HEAD expected keys → rows |
| P8-T02 | CLI `wp universal-storage adopt` |
| P8-T03 | Admin flow |
| P8-T04 | Tests adopt without Put |
| P8-T05 | (Later) vendor import adapters — deferred |

### Phase 9 — Free / Pro separation

| ID | Task |
|----|------|
| P9-T01 | Module registration API in core |
| P9-T02 | Move multi-profile UI to Pro module |
| P9-T03 | Scaffold `kazcode-universal-storage-pro` plugin |
| P9-T04 | Replace Features::enabled call sites gradually |
| P9-T05 | Activation dependency checks |
| P9-T06 | Packaging two ZIPs |

### Phase 10 — PHP-Scoper / build

| ID | Task |
|----|------|
| P10-T01 | scoper.inc.php |
| P10-T02 | build script composer --no-dev + scope |
| P10-T03 | Scoped autoload bootstrap |
| P10-T04 | CI job build artifact |
| P10-T05 | License file aggregation |
| P10-T06 | readme.txt version sync |

### Phase 11 — UX / dashboard

| ID | Task |
|----|------|
| P11-T01 | Menu IA restructuring |
| P11-T02 | Dashboard cached widgets |
| P11-T03 | Storage profiles UI |
| P11-T04 | Health UI |
| P11-T05 | Delivery simplified controls |
| P11-T06 | Safe storage change wizard |
| P11-T07 | ML column enhancements from local state |

---

## 34. Task dependency graph

```text
P0-T01…T10
    ↓
P1-T01 → T02/T03 → T04 → T05 → T06 → T07
P1-T08 → T09
P1-T10 → T11
P1-T12
P1-T14 (parallel)
    ↓
P2-* (needs P1 complete)
    ↓
P3-* (needs P2-T01+)     P10-* (parallel from P0)
    ↓
P4 / P5 (parallel after P2)
    ↓
P6
    ↓
P7 / P8 (parallel after P6)
    ↓
P9 (API can start earlier; package split after P7 optional)
    ↓
P11 (after P6; can stub earlier)
```

---

## 35. Parallelizable workstreams

After P1 interfaces freeze:

1. **DB/domain** — repositories, manifest, backfill  
2. **Queue** — AS gateway + jobs  
3. **Build pipeline** — PHP-Scoper (P10)  
4. **Tests/MinIO** — integration harness  
5. **Admin UX** — after read APIs stable  
6. **Pro module shells** — registration API  

---

## 36. Acceptance criteria (v2 foundation)

Foundation complete only when:

1. **Partial upload** — exact failed variant known; status not falsely `offloaded`.
2. **Retry** — only missing/failed objects reworked.
3. **Remote-only** — local delete only after complete verification.
4. **Restore** — full attachment returns locally before local serve.
5. **Regeneration** — manifest diff reconciles remotes.
6. **Provider switch** — old attachments keep old profile URLs.
7. **Provider migration** — destination active only after verify.
8. **Repair** — remote missing detectable and repairable.
9. **Large site** — backfill/migrate resumable.
10. **Crash** — no ambiguous ownership; state in DB/queue.

---

## 37. Rollback strategy

| Layer | Rollback |
|-------|----------|
| Code | Revert plugin version; dual-read ensures old code still understands `_s3ms_*` |
| Schema | New tables unused if feature flags off; dropping only if empty/unneeded |
| Profiles | Settings shim remains until profiles deleted |
| Queue | Fall back to CronQueueAdapter |
| Scoper build | Ship unscoped emergency ZIP (documented risk) |

Never “rollback” by bulk-deleting remote objects.

---

## 38. Deferred / future features

- Theme/plugin static asset CDN  
- HTML page caching / full-site CDN  
- Image transformation SaaS / on-the-fly resize  
- Video transcoding  
- Full DAM / external WP replacement  
- Generic full-bucket file manager  
- Vendor-specific imports (WP Offload Media, etc.) beyond generic adopt  
- Shared network-wide object tables  

---

## 39. Open technical decisions

### OTD-1: Action Scheduler vs custom queue

- **A:** Bundle/require Action Scheduler  
- **B:** Custom `s3ms_jobs` table  
- **C:** AS if present else CronAdapter (recommended)  
- **Advantages C:** durability when available; no hard dependency for Free.  
- **Disadvantages:** two paths to test.  
- **Recommendation:** C — **Confidence: high**

### OTD-2: Shared vs per-site multisite tables

- **A:** Shared with `blog_id`  
- **B:** Per-site prefix tables (recommended)  
- **Recommendation:** B — matches WP norms and current settings — **Confidence: high**

### OTD-3: Object checksum strategy

- **A:** Head exists only (today)  
- **B:** size + ETag (recommended)  
- **C:** full content hash always  
- **Recommendation:** B; C optional Pro/slow verify — **Confidence: high**

### OTD-4: Storage Profile mutability

- **A:** Always mutable  
- **B:** Location immutable after objects; delivery mutable (recommended)  
- **Recommendation:** B — **Confidence: high**

### OTD-5: Job granularity

- **A:** Attachment-only jobs  
- **B:** Object-only jobs  
- **C:** Hybrid (recommended)  
- **Recommendation:** C — **Confidence: high**

### OTD-6: Legacy postmeta lifetime

- **A:** Remove immediately after backfill  
- **B:** Keep as cache ≥1 major version (recommended)  
- **C:** Forever  
- **Recommendation:** B — **Confidence: medium**

### OTD-7: PHP-Scoper strategy

- **A:** Scope in-repo always  
- **B:** Release-only scope (recommended)  
- **Recommendation:** B — **Confidence: high**

### OTD-8: Credential storage location

- **A:** Columns on profiles (bad)  
- **B:** Encrypted option map by uuid (recommended)  
- **C:** Constants only  
- **Recommendation:** B + precedence with IAM/constants — **Confidence: high**

### OTD-9: WebP/AVIF registration

- **A:** Filesystem scan  
- **B:** Filter + provider interface (recommended)  
- **Recommendation:** B — **Confidence: high**

### OTD-10: AS packaging for wp.org

- **Decision (P3-T01, 2026-08):** ship **CronQueueAdapter** as the default driver (no new dependency). When Action Scheduler is already loaded (`as_enqueue_async_action`), `ActionSchedulerGateway` handles single-attachment jobs; bulk migrate/verify/retry/restore stay on the cron adapter + `BackgroundMigrator` state. Future wp.org packaging: optional `composer suggest` for `woocommerce/action-scheduler` — not bundled in v2.0.
- **OPEN** — subtree bundle for offline sites remains a distribution follow-up.

---

## Appendix A — Answers to repository audit questions (1–30)

1. **Upload class:** `Kazcode\WpStorage\Storage\S3Storage::upload_file` via `AttachmentOffloader::offload`.  
2. **Auto upload hooks:** `wp_generate_attachment_metadata`, `wp_update_attachment_metadata` @ priority 20.  
3. **Sizes ready:** Yes — hooks run after WP generates metadata.  
4. **Object keys:** `S3KeyResolver::resolve` / `from_relative_path` patterns via `resolve($relative)`.  
5. **Public URL:** `PublicUrlResolver::url_for_key`.  
6. **srcset:** `AttachmentUrlFilter::filter_srcset`.  
7. **Verify checks:** HeadObject existence per metadata path; VerificationService statuses as above; not checksum.  
8. **Restore restores:** Each metadata-relative file missing locally but present remotely via GetObject; clears S3 meta.  
9. **One size fails:** Exception → `_s3ms_status=failed`; locals kept; prior Puts **not** rolled back.  
10. **Local delete when:** After successful offload+verify when `delete_local_after_upload` true (and optional second Head if `verify_before_delete`).  
11. **Partial success local delete?** No on failed offload. Yes risk of skipping **second** Head if `verify_before_delete=false` after already offloaded.  
12. **Delete key discovery:** `AttachmentFileResolver::relative_paths` → current prefix; not `_s3ms_original_key` inventory; not recursive.  
13. **WP-Cron persistence:** option `s3ms_background_job` + `s3ms_background_tick`.  
14. **Migration idempotent?** Queue skips offloaded; `offload()` re-PUTs; not skip-if-remote-matches.  
15. **Failed items:** postmeta `_s3ms_last_error` + status `failed` (not durable object table).  
16. **Presets:** `ProviderPresets` — aws, r2, spaces, minio, wasabi, custom.  
17. **Config centralized?** Yes — `Settings` / `s3ms_settings`.  
18. **Multi-provider runtime?** No.  
19. **Encryption:** `EncryptionService` sodium/AES from WP salts HKDF; option `s3ms_encrypted_secret`.  
20. **Salt change:** decrypt fails → empty secret; looks unconfigured; no distinct admin diagnosis.  
21. **IAM:** `credential_mode=iam_role` omits explicit credentials on client.  
22. **AWS SDK namespaced?** No — global `Aws\`.  
23. **Guzzle/AWS collision?** Yes, possible.  
24. **Multisite:** `s3ms_network_settings` merge when inherit; secrets per-site.  
25. **`S3MS_PLAN`:** lite|pro via `Features`; default **pro**.  
26. **Pro-in-core:** private media, background migrate UI, failed dashboard, ML actions, diagnostics, audit, network menu, wizard — gated but shipped together; `migrate_existing`/`signed_urls` unused as call-site gates.  
27. **Destructive tests:** weak — unit helpers mostly; no full offload/delete/migrate integration suite.  
28. **Untested critical paths:** partial offload orphans, local delete policies, delete remotes, background overlap, provider switch URLs.  
29. **Bucket/provider change invalidates old URLs?** Yes — live settings.  
30. **Keep unchanged:** stream I/O in `S3Storage`, PathGuard, relative attached file invariant, non-recursive delete principle, ML/CLI surfaces, encryption envelope idea, connection test.

---

## Appendix B — Failure scenario matrix (v2 expected)

| Scenario | Expected behavior |
|----------|-------------------|
| Original OK, thumb fails | Objects: present/failed; attachment `partial`/`failed`; no local wipe; retry failed only |
| Put OK, Head outage | Do not local-delete; retry verify |
| PHP killed mid-batch | Resume from object/queue state |
| WP-Cron dead | State consistent; CLI/AS runner resumes |
| Admin changes storage mid-migrate | Blocked / locked; no silent retarget |
| Regen during offload | Lock + reconcile manifest |
| Attachment deleted while uploading | Cancel jobs; delete **known** object keys; remove rows |
| Manual remote delete | Health `remote_missing`; repair re-uploads if local exists |
| Local manually deleted | Valid under REMOTE_ONLY if remotes verified |
| Salts changed | `credentials_decrypt_failed` diagnosis |
| CDN down, storage OK | Delivery failure ≠ storage failure (Head OK) |
| Dest migration partial | Keep serving source profile until dest verified |

---

## Appendix C — SPEC DEVIATIONS

1. **Shared network object table** — prompt considered; **recommend per-site tables**.  
2. **Immediate Free/Pro repository split** — keep monorepo modules until API exists; split packaging in P9.  
3. **Custom jobs table** — only if AS cannot ship; prefer AS + CronAdapter.  
4. **Checksum-required verify** — size+ETag; full hash optional.  
5. **Document path** — written under plugin `docs/` (not repo-root `docs/`) because this is plugin-owned architecture.  
6. **`pending` status** — constant exists but unset today; v2 derived states supersede.  
7. **PHP floor** — WordPress still documents older PHP as runnable; this product requires **PHP 8.3+** (wordpress.org recommended baseline, 2026), not PHP 7.4 EOL.  
8. **Git remotes** — plugin work is **local-branch-only** inside APTA CMS; not pushed to `apta` until an explicit publish decision.

---

## Appendix D — Component fate summary (KEEP / EXTEND / …)

See §3.1 table. Headline: **no rewrite**; evolve offload/URL/storage; replace only BackgroundMigrator and coarse status as sources of truth.

---

## Appendix E — Non-goals

Theme/plugin CDN, HTML rewriting, image SaaS transforms, video transcoding, DAM, replacing WP media DB, full-bucket file manager.

---

*End of planning document. Do not begin Phase 0 implementation until explicitly requested.*
