# WordPress media lifecycle matrix (Issue #7)

**Purpose:** Lock expected-file / offload / stale / cleanup behavior before Direct Upload (#8/#9).  
**Rule:** Statuses are honest. Never fabricate `AUTOMATED PASS`. Characterization first — do not change product behavior only to turn a row green.

Vocabulary:

| Status | Meaning |
|--------|---------|
| `AUTOMATED PASS` | Covered by CI PHPUnit (or named smoke) with a concrete assertion |
| `MANUAL` | Documented manual scenario; not asserted in CI |
| `NOT_RUN` | Desired coverage missing; reason required |
| `NOT_APPLICABLE` | Out of product scope or impossible in current model |

## Matrix

| Scenario | Status | Evidence / reason |
|----------|--------|-------------------|
| Expected-file model: original + `sizes` | AUTOMATED PASS | `ManifestBuilderTest::test_builds_original_and_sizes_from_metadata` |
| HEIC / source retention via `metadata.original_image` + generated JPEG attached file | AUTOMATED PASS | `ManifestBuilderTest::test_includes_original_image_sibling_for_heic_style_retention` — characterizes Core enumeration; live HEIC decode in CI is env-dependent and not required here |
| Live HEIC upload → WP generates JPEG on a real WP host | MANUAL | `MANUAL-SCENARIOS.md` Scenario K (HEIC retained source). Requires host HEIC support. |
| Image Editor backup sizes (`_wp_attachment_backup_sizes`) enumerated | AUTOMATED PASS | `ManifestBuilderTest::test_includes_image_editor_backup_sizes` |
| Image Editor crop/rotate end-to-end on S3-only attachment | MANUAL | `MANUAL-SCENARIOS.md` Scenario F; LocalFileProvider rematerialize path covered partly by unit restore/local provider tests |
| Regenerate Thumbnails end-to-end on S3-only | MANUAL | Scenario E |
| Superseded/stale variants do not poison attachment **health/status derivation** | AUTOMATED PASS | `AttachmentSyncDeriverTest` (stale/deleted exclusion + regenerate-style case) — status roll-up only |
| Superseded/stale variants do not break **URL rewrite** via AttachmentUrlFilter | NOT_RUN | No unit currently asserts `AttachmentUrlFilter` output when present+stale rows coexist; deriver coverage alone is insufficient for delivery |
| WebP/AVIF optimizer sidecars via `kazus_manifest_files` (**enumeration only**) | AUTOMATED PASS | `ManifestBuilderTest::test_kazus_manifest_files_filter_adds_webp_avif_sidecars` — path appears in manifest |
| WebP/AVIF sidecars via filter actually **offloaded/verified** | NOT_RUN | `ObjectOffloadService` / `VerificationService` use `AttachmentFileResolver::existing_local_files()` which does not apply `kazus_manifest_files`; sidecars can be enumerated yet skipped for upload/verify |
| WebP/AVIF via `MediaVariantProviderInterface` **direct register()** | AUTOMATED PASS | `MediaVariantRegistryTest` — covers register()+contribute merge |
| WebP/AVIF via runtime `kazus_register_variant_providers` action on default ManifestBuilder | NOT_RUN | `contributions()` fires the action after iterating already-registered providers; default `ManifestBuilder` constructs a fresh empty registry, so first-call hook registration contributes nothing today |
| Built-in hard-coded WebP/AVIF discovery without hooks | NOT_APPLICABLE | Core intentionally does not ship a specific optimizer integration; hooks/providers are the supported extension points (Issue #7 non-goal: no new optimizer solely for testing) |
| Animated image / video poster metadata keys auto-enumerated by Core | AUTOMATED PASS (negative) | `ManifestBuilderTest::test_does_not_auto_include_animated_video_or_poster_metadata_keys` — current behavior: Core ignores `animated_video` / `animated_video_poster` unless filter/provider contributes |
| Competitor import sees animated_video / poster keys | NOT_RUN | `WpOffloadMediaImporterTest` metadata includes these keys, but the `as3cf_files` fixture and expected descriptors do not assert MP4/poster import rows — an importer regression would stay green. Add explicit fixture rows + descriptor assertions before claiming PASS. |
| Live animated GIF / video poster offload on real WP | NOT_RUN | Needs WP fixtures + media that produce those metadata keys; no CI fixture yet |
| WooCommerce downloadable / private file attachments | NOT_RUN | No WooCommerce test dependency in Core CI; private **delivery setting** sanitization is unit-covered (`SettingsTest`) but that is not signed-URL delivery coverage |
| WooCommerce product downloadable file offload/rewrite | NOT_RUN | Roadmap-in-scope; no WooCommerce CI/manual scenario yet — keep tracked until MANUAL or integration job exists |
| Private media signed URL delivery (site setting → AttachmentUrlFilter) | NOT_RUN | `SettingsTest::test_private_media_*` only covers setting sanitize/get; no test currently asserts `AttachmentUrlFilter` emits a signed URL when `private_media` is enabled. `ProfileAwareObjectOperationsTest` covers lower-level presign without binding the site setting. |
| Upload → metadata checkpoint (empty sizes) does not offload prematurely | AUTOMATED PASS | `OffloadSafetyCharacterizationTest` |
| Upload → HEAD verify failure skips local cleanup | AUTOMATED PASS | `CleanupLocalFilesTest::test_verify_failure_skips_delete` |
| Upload → size mismatch skips local cleanup | AUTOMATED PASS | `CleanupLocalFilesTest::test_existing_remote_without_matching_size_skips_delete` |
| Upload → HEAD confirmed missing vs transient | AUTOMATED PASS | `HeadKeyConfirmedMissingTest`, `VerificationObservationTest`, `AdoptAttachmentServiceTest` |
| Upload → HEAD OK then DB inventory upsert fails | NOT_RUN | `ObjectOffloadService::offload()` can verify remote before `ObjectRepository::upsert()`; if upsert throws, remote may exist without inventory and cleanup is skipped. `ObjectRepositoryAtomicUpsertTest` only asserts repository throw, not the offload workflow aftermath — needs dedicated characterization |
| Delete attachment remote keys (explicit list) | AUTOMATED PASS + MANUAL | Characterization delete tests + Scenario G |
| Metadata override during regenerate (in-flight metadata wins) | AUTOMATED PASS | `AttachmentFileResolverLifecycleTest::test_metadata_override_is_used_during_generate_attachment_metadata` |

## Failure-point map (upload → HEAD → DB → cleanup)

| Step | Failure | Persisted effect | Locked by |
|------|---------|------------------|-----------|
| Mid-upload PutObject | Exception / partial | `remote_status=failed` / partial; local kept | `ObjectOffloadServiceTest`, CHARACTERIZATION offload section |
| HEAD transient | timeout/5xx/auth | Must not write confirmed missing | Adopt/Verification observation tests |
| HEAD 404 | confirmed missing | May persist missing | `HeadKeyConfirmedMissingTest` |
| Cleanup before verify | HEAD fail / size mismatch | Skip delete; local retained | `CleanupLocalFilesTest` |
| Empty-sizes metadata checkpoint | WP progressive metadata | Offload no-op | `OffloadSafetyCharacterizationTest` |

## How to extend

1. Prefer pure PHPUnit + `WpStubs` for expected-file / state-machine behavior.
2. Mark real WP host scenarios `MANUAL` in `MANUAL-SCENARIOS.md` rather than inventing PASS.
3. If a new characterization test goes RED on **current** main behavior, open a bug issue — do not silently expand #7 into a behavior refactor unless the fix is obviously in scope and minimal.

## Issue #7 evidence template

When closing #7, report:

```text
TESTS=…
ASSERTIONS=…
FAILURES=…
ERRORS=…
SKIPPED=…
```

from the Core PHPUnit run that includes the new lifecycle tests.
