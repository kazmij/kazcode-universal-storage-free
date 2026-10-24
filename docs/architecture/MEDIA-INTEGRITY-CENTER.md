# Media Integrity Center — Architecture Spec

**Status:** design for Issue #4 (implementation deferred to leaf issues)  
**Date:** 2026-09-22  
**Product:** KAZCODE Universal Storage + Pro  
**Autonomy of this issue:** REVIEW (spec only — no product behavior change in #4)

## 1. Outcome

Define one coherent **Media Integrity Center** that presents and orchestrates the recovery capabilities KAZCODE already owns at the service layer (verify, repair/retry, adopt/rebuild inventory, restore, health scan, orphan report) without duplicating Put/HEAD/delete paths or weakening Free recovery into an upsell.

This document is the product/architecture contract for:

- Issue **#5** — Rebuild Storage Inventory (dry-run/apply)
- Issue **#6** — Restore Preflight (`SAFE` / `WARNING` / `BLOCKED`)
- Additional leaf issues listed in §14

## 2. Design principles

1. **Reuse, don't fork.** Orchestrate existing Core/Pro services. New code is a thin application facade + UI/CLI/REST presentation, not a second inventory or transfer stack.
2. **Fail closed.** Transient provider failures never become confirmed missing; never infer remote deletion.
3. **Exact keys only** for verify / rebuild / repair. Bucket-prefix LIST stays Pro orphan-report and remains non-destructive.
4. **Attachment vs object clarity.** Inventory and repair are object-grain; user summaries roll up to attachment grain using `AttachmentSyncDeriver` rules (exclude `stale` / `deleted`).
5. **Dry-run means zero persistence mutation** (and zero remote write). Apply is explicit.
6. **Core safety/recovery stays Free.** Pro owns multi-profile / orphan LIST / advanced health presentation depth — not the ability to verify, repair, rebuild, or restore a single-profile library.
7. **Destructive orphan delete is out of scope** for the initial Center. Path is report → quarantine/review → (future HUMAN-GATE) explicit delete.

## 3. What already exists (AS-IS inventory)

### 3.1 Core services (keep)

| Concern | Class | Notes |
|--------|--------|--------|
| Env + connectivity health | `Services/HealthCheckService` | Free |
| DB-first object health buckets | `Services/HealthScanService` + `Domain/ObjectHealthState` | No S3 LIST |
| Exact-key verify | `Services/VerificationService` + `Domain/RemoteObservation` | HEAD taxonomy |
| Repair object/attachment | `RepairObjectService`, `RepairAttachmentService` | Put+HEAD when local present |
| Adopt / inventory seed | `AdoptService`, `AdoptAttachmentService` | HEAD-only; never uploads; `confirmed_missing` gate |
| Local→remote migrate / verify / restore batches | `MigrationService` | Free |
| Failed / retry surface | `FailedItemsService` | Free |
| Offload + cleanup | `ObjectOffloadService`, `CleanupLocalFiles` | Cleanup only after remote verify |
| Restore + delete-attachment | `Attachment/AttachmentRestorer` | Remote delete only via `RemoteDeleteSafetyGuard` |
| Expected files | `ManifestBuilder` / `MediaManifest`, `AttachmentFileResolver` | Authoritative variant list |
| Audit ring buffer | `AuditLog` | Free (viewing + recording) |
| Object aggregates | `ObjectStatsAggregator` | Cached SQL |
| Pre-delete remote safety | `RemoteDeleteSafetyGuard` | **Not** restore preflight |

### 3.2 Pro services (keep physical ownership)

| Concern | Class | Notes |
|--------|--------|--------|
| Orphan LIST vs inventory | `Pro\Services\OrphanScanService` | Always dry-run; never deletes |
| Cross-profile migration | `StorageMigrationService`, `MigrateObjectService`, `MigrateAttachmentService` | Out of Integrity Center MVP except as related link |
| Advanced health UI embed | Health orphan panel via `advanced_health` / `orphan_scan` | |

### 3.3 Domain / storage invariants (do not regress)

**`ObjectRemoteStatus`:** `pending` · `uploading` · `present` · `missing` · `failed` · `stale` · `deleted`

**`AttachmentSyncDeriver`:** excludes `stale` / `deleted` from present/total roll-up (current vs superseded variants).

**HEAD (`S3Storage::head_key` / `ProfileStorageGateway::head_key`):**

| Provider outcome | `exists` | `confirmed_missing` | Inventory write |
|------------------|----------|---------------------|-----------------|
| 200 / object found | true | — | may mark present / adopt |
| Definitive **object** absence (NoSuchKey / NotFound / object-level 404) | false | **true** | may mark `missing` |
| Non-object 404 (e.g. `NoSuchBucket`) / misconfiguration | false | **false** | must **not** mark missing — treat as config/`invalid_request` error |
| Timeout / 5xx / TLS / auth / throttle / DNS / network | false | **false** | must **not** mark missing |

**Normative gate for `confirmed_missing`:** reject known non-object provider codes (at least `NoSuchBucket`) **before** treating a bare HTTP 404 as definitive object absence. Today `S3Storage::is_missing_object_error()` / `ProfileStorageGateway::is_missing_object_error()` return true on any status 404 — that must be tightened before Integrity Verify Apply (or any inventory persist of `missing`) ships, otherwise a missing/wrong bucket falsely marks every key `remote_status=missing`.

**`RemoteObservation`:** `remote_present` · `remote_confirmed_missing` · `remote_unknown` · `remote_error` plus verification levels (`exists_verified`, `size_verified`, `size_mismatch`, …) and error classes (`auth`, `throttled`, `timeout`, `network`, `tls`, `invalid_request`, …).

### 3.4 Current UX fragmentation (problem statement)

| Surface today | What users can do | Gap |
|---------------|-------------------|-----|
| Health admin | Env checks, stats, DB health page, Pro orphan panel | No repair UI; no rebuild; no restore preflight |
| Migration admin | migrate / verify / restore / adopt / import | Recovery mixed with “move library to S3” |
| Media admin | Failed items | Not framed as integrity |
| CLI | Full repair/verify/restore/adopt/orphan | Power-user only |
| REST | Same capabilities | No unified “integrity” namespace story |

**Verdict:** primitives are stronger than the UX. The Center is primarily an **information architecture + orchestration** problem.

## 4. Target information architecture

### 4.1 Admin menu placement

Add a single top-level submenu under **Universal Storage**:

**Integrity** (slug suggestion: `kazcode-storage-integrity`)

Do **not** remove Health / Migration / Media in the first leaf. Prefer:

1. Integrity becomes the primary recovery entry.
2. Health keeps env/connectivity + links into Integrity.
3. Migration keeps local→remote migrate/import; restore/adopt CTAs deep-link into Integrity.
4. Media failed-items either embeds in Integrity “Needs attention” or deep-links.

Legacy redirects (optional follow-up): soft notices on Health/Migration pointing to Integrity for recovery actions.

### 4.2 Integrity Center screens / panels

One page with tabbed or sectioned panels (single composition, one job per section):

| Panel | Job | Primary primitives |
|-------|-----|--------------------|
| **Overview** | Counts + “Needs attention” | `ObjectStatsAggregator`, `FailedItemsService`, last Integrity run summaries |
| **Verify** | Exact-key verify dry-run report → optional apply of inventory refresh fields | Observation-only HEAD path + optional apply (see §6.1) — **not** raw `VerificationService::verify()` for dry-run |
| **Repair / Retry** | Repairable objects/attachments; dry-run then apply | Profile-aware repair (see §6.2), `FailedItemsService` |
| **Rebuild inventory** | Expected variants → HEAD → classify → dry-run/apply adopt | New facade over `Adopt*` + manifest (#5) |
| **Restore preflight** | Capacity + remote state gate before restore | New service (#6) → then `MigrationService::restore_*` |
| **Orphans** (Pro) | Report-only LIST orphan keys; quarantine list | `OrphanScanService` |
| **History** | Auditable Integrity run summaries | `AuditLog` + new run option/table (leaf) |

### 4.3 User workflow (canonical)

```text
Overview
  → pick concern (verify | repair | rebuild | restore | orphans)
  → always start Dry-run
  → review classification counts
  → explicit Apply (capability-checked)
  → summary + audit entry
  → optional resume/retry of failed units
```

Bulk restore path:

```text
Restore panel → Preflight (#6)
  → SAFE: enable Start restore
  → WARNING: require explicit acknowledge
  → BLOCKED: Start disabled; show reasons
```

## 5. Unified presentation taxonomy

Keep persistence models as they are. Add a **presentation/result taxonomy** used by Integrity UI/CLI/REST so operators never see three conflicting vocabularies.

### 5.1 Observation classes (from HEAD / verify)

| Class | Meaning | Persistence rule |
|-------|---------|------------------|
| `PRESENT` | Remote object confirmed by HEAD | May adopt / mark present |
| `CONFIRMED_MISSING` | Definitive **object** NoSuchKey/NotFound/object-404 — **not** `NoSuchBucket` or other non-object 404s | May persist `remote_status=missing` |
| `SIZE_CONFLICT` | Present but HEAD Content-Length ≠ trustworthy expected size (§6.1 precedence) | Do **not** treat as missing; report conflict; Apply must not overwrite `size_bytes` with the remote value |
| `TRANSIENT` | timeout / 5xx / network / TLS / DNS / throttle | Never persist missing; retry later |
| `AUTH` | 401/403/credential/signature failures | Never persist missing; operator must fix credentials |
| `INVALID_REQUEST` | Deterministic bad request / non-retryable client error (HTTP 400, `invalid_request`, wrong bucket/`NoSuchBucket`, malformed key) | Never persist missing; operator must fix config |
| `UNKNOWN` | Unclassified provider error | Fail closed like TRANSIENT (retryable uncertainty only) |

Map 1:1 from `RemoteObservation` (+ size mismatch level). Do not invent a parallel HEAD client.

### 5.2 Inventory / health classes (from DB + local FS)

Reuse `ObjectHealthState` for Overview filters that **`classify_row()` actually produces today**:

- `healthy`, `remote_missing`, `local_missing`, `failed_upload`, `unverified`, `stale`

Rules:

- **Current variants only** in primary health roll-ups (exclude `stale`/`deleted` object rows), matching `AttachmentSyncDeriver`.
- `stale` rows remain visible in an advanced “Superseded variants” filter — they are historical, not “broken current”.
- **Do not expose a Core Overview filter for `possible_orphan` in MVP.** The constant `ObjectHealthState::POSSIBLE_ORPHAN` exists but `classify_row()` never returns it and `HealthScanService` therefore always counts zero. True remote orphans are Pro LIST (`OrphanScanService`) only. A future leaf may either (a) define a real DB/local predicate and teach `classify_row()` to emit it, or (b) delete the unused constant — not silently show a dead filter.

### 5.3 Attachment vs object

| Level | Used for |
|-------|----------|
| **Object** | Exact HEAD, repair Put, adopt row, orphan key |
| **Attachment** | User list rows, sync status column, restore unit, rebuild batch cursor |

Roll-up: attachment status is derived, never independently authoritative over object rows.

## 6. Operation contracts

### 6.1 Verify

- **Input:** attachment IDs or filter (all offloaded / partial / failed).
- **Action:** exact-key HEAD per expected current variant via existing storage gateway + `RemoteObservation`.
- **Dry-run (Integrity default):** **must be zero-persistence.** Do **not** call today’s `VerificationService::verify()` for dry-run — that method writes `_s3ms_verified_at` / can flip `_s3ms_status` and has no dry-run flag. Leaves must either:
  - add `VerificationService::observe()` / `verify(…, dry_run: true)` that performs HEAD + classification only; or
  - call a thin Integrity facade that HEADs through `ProfileStorageGateway` / adopt observation helpers and returns §5.1 classes without writing postmeta/rows.
- **Profile resolution for HEAD (normative, same as §6.4 / rebuild):** per expected variant, use the row’s bound profile when present. For path-level `NOT_IN_INVENTORY`, apply the wholly-untracked vs partial-gap rule from §6.4 step 0 — including **importer/exact-key evidence before legacy fallback**. Never silent legacy `S3Storage` HEAD when sibling current rows bind a custom profile, or when importer/retained exact-key evidence still exists without inventory rows (that yields false present/missing in the default bucket and Apply must not persist it). Infer unique sibling profile **and** an authoritative exact key for that variant, or block/skip as `ambiguous_profile` / `ambiguous_key` — never synthesize `prefix + relative` for a partial-gap verify HEAD when import/custom-key evidence implies otherwise.
- **Expected size source precedence (normative for observation → §5.1 class):** before a HEAD result may become `PRESENT` / `size_verified`, compare HEAD `Content-Length` to a trustworthy expected size chosen in this order:
  1. **Local file size** when the local file exists and is authoritative/current for that variant (same path the offload/cleanup paths treat as the live local object).
  2. Otherwise the **persisted current inventory row** `size_bytes` for that variant (authoritative when local was deleted after successful offload — the common fully-offloaded case).
  3. If neither yields a trustworthy expected size (no usable local file **and** current inventory `size_bytes` is null/`<= 0` / absent), the observation **must** be marked **existence-only** (`exists_verified` or equivalent). It **must not** be reported as size-verified PASS, and Apply **must not** pretend a size check succeeded.
- **Size compare / conflict:** when a trustworthy expected size exists, HEAD `Content-Length` **must** be compared to it before classifying `PRESENT` / `size_verified`. Mismatch ⇒ `SIZE_CONFLICT` (never coerce to `PRESENT` or `missing`). Fully-offloaded attachments without a local file **must** still detect truncated/replaced remote objects via inventory `size_bytes`. When expected size is unavailable per (3), report inability to size-verify explicitly — never a false PASS.
- **Hazard today:** `VerificationService::verify()` passes `null` as expected size when the local file is absent (`VerificationService.php` ~71–74), so `RemoteObservation` can only confirm existence and cannot emit `SIZE_CONFLICT` for the fully-offloaded case. Integrity observation-only verify **must not** reuse that null-expected-size behavior.
- **Apply (optional leaf):** update `verified_at` / etag/size metadata **only** for `PRESENT` that passed size verification (or an explicitly accepted size policy); write `missing` only on CONFIRMED_MISSING; leave TRANSIENT/AUTH/`INVALID_REQUEST`/`SIZE_CONFLICT` untouched for missing-coercion. **Apply must not overwrite inventory `size_bytes` with the remote Content-Length when a size mismatch exists** (that would mask truncated/replaced objects). Existence-only observations without trustworthy expected size may refresh presence/`verified_at` only under an explicit product policy — never silently upgrade to size-verified. Apply may reuse/extend `VerificationService` **only after** that service’s legacy site-Settings readiness gate is removed or replaced with **profile-aware** readiness (same class of fix as #32 for restore) **and** expected-size precedence above is honored. Today `VerificationService::verify()` short-circuits the entire loop when `Settings::is_aws_configured()` is false (`VerificationService.php` ~69–87), so profile-credentialed / non-default-profile objects never get HEADed even though a dry-run Integrity facade could observe them via `ProfileStorageGateway`. Integrity Verify Apply **must not** ship while that gate remains or while expected size still falls through to `null` for offloaded-local-absent rows.
- **Idempotent.** Safe to re-run.

### 6.2 Repair / Retry

- Reuse repair semantics from `RepairObjectService` / `RepairAttachmentService` and failed-item retry, **with a hard profile-routing requirement:**
  - Each inventory row’s Put/HEAD must go through the **row’s `storage_profile_id`** (profile-aware gateway / `ProfileS3ClientFactory`), never blindly through the site default/`S3Storage` singleton.
  - Today’s `RepairObjectService` uses a single default `S3Storage` while preserving the row’s original `storage_profile_id` on success — that can repair into the wrong bucket after Pro cross-profile migration. Integrity Apply **must not** ship until repair is profile-aware **or** non-default-profile rows are explicitly excluded from repair scope with a clear operator message.
- Dry-run lists repairable rows without uploading. Candidate selection **must not** treat superseded inventory as repairable:
  - Exclude `remote_status` in `{stale, deleted}` (and any future superseded/removed terminal) **before** calling `ObjectHealthState::is_repairable`.
  - Rationale: after cross-profile migration (or crop/regenerate), the source row may be retained as `deleted`/`stale` while the local file still exists. Today `ObjectHealthState::classify_row()` has no `deleted` branch and can classify those rows as `unverified`, which `is_repairable()` accepts when local is present — Integrity Apply must not re-upload intentionally deleted/superseded source objects.
  - Prefer fixing `ObjectHealthState` to classify `deleted`/`stale` as non-repairable (and update `is_repairable` callers) as part of the repair leaf; until then, Integrity facades must filter those rows explicitly.
- Apply uploads only when local file present **and** the row is a current (non-stale/non-deleted) candidate; verifies with HEAD on the **same** profile before success.
- **Repair HEAD must size-verify (normative):** do not mark `remote_status=present` / set `verified_at` on existence-only HEAD. Compare HEAD `Content-Length` to the local file size through `RemoteObservation` (same size-verified bar as offload/verify/rebuild). Mismatch ⇒ leave failed/conflict — never report a truncated/replaced object as repaired. Hazard today: `RepairObjectService` only checks `head['exists']` before writing present.
- Never deletes remote or local as part of repair.

### 6.3 Rebuild Storage Inventory (#5)

Productized “rebuild index” over existing adopt/manifest primitives.

**Algorithm:**

1. Enumerate attachments in scope (resumable cursor).
2. For each attachment, build expected descriptors via `ManifestBuilder` / file resolver (original + sizes + known sidecars WordPress exposes).
3. **Resolve the authoritative storage profile before key math / HEAD** (normative for #5):
   - Prefer an **explicit** profile id from the operator/CLI/REST scope when provided **only if** it is compatible with surviving current inventory bindings for that attachment:
     - If surviving current rows exist and unanimously bind a different `storage_profile_id` → **exclude** / conflict (`explicit_profile_conflicts_surviving_rows`) — do **not** HEAD the explicit profile while still preferring surviving rows’ `object_key` (cross-profile migration keys are destination-prefix-specific; a source key in the destination bucket yields false missing or colliding adopt).
     - If surviving current rows disagree on profile → **exclude** (`ambiguous_profile`) regardless of explicit override, unless the leaf defines a documented force mode that also **re-resolves keys for the selected profile** (never reuse another profile’s stored keys against the forced profile).
   - Else (no explicit profile): if the attachment has surviving **current** inventory rows (`remote_status` not in `{stale, deleted}`), use that profile when it is unambiguous (single distinct `storage_profile_id`). If surviving current rows disagree on profile → **exclude** the attachment from apply (count as `ambiguous_profile` / conflict) — do **not** silently HEAD the site default.
   - Else (no surviving current rows): require explicit profile **or** a documented empty-inventory policy (e.g. default upload target) — never silently adopt into the current default when surviving non-default rows were the last known location and were only partially lost.
   - Rationale: bare `AdoptAttachmentService` falls back to `find_default_upload_target()` when no profile is supplied, which HEADs the wrong bucket after a profile switch.
4. Resolve **exact** remote keys (normative — do **not** invent keys for imported/custom-prefix objects):
   - Prefer an **authoritative key** already known for that variant **on the selected profile**: surviving current inventory `object_key` **only when that row’s `storage_profile_id` matches the selected profile**, importer/`ImportedObjectDescriptor` `source_key` for that profile, or other exact key-mapping evidence retained from import/adopt for that profile.
   - Do **not** reuse a surviving row’s `object_key` from profile A while HEADing profile B.
   - Only when no authoritative key exists for that variant **on the selected profile** may rebuild synthesize via the normal profile-prefix + relative-path key resolvers (**that** profile + prefix).
   - **Hazard:** `WpOffloadMediaImporter` (and similar) supplies exact per-object paths; `AdoptAttachmentService::adopt_descriptors()` stores the descriptor’s `source_key`. After an inventory row is lost, synthesizing `prefix + relative` can HEAD the wrong key → false `CONFIRMED_MISSING` or adopt an unrelated colliding object.
   - If the attachment has importer/key-mapping evidence that a custom/exact key existed but that evidence is incomplete/ambiguous for a required variant → classify as `ambiguous_key` / skip (count separately) — **never** coerce to `CONFIRMED_MISSING` from a synthesized key alone.
5. HEAD each key → classify via §5.1, using the **§6.1 expected-size precedence** (local file size → current inventory `size_bytes` → existence-only). Rebuild must still emit `SIZE_CONFLICT` for fully-offloaded (no local) variants when inventory `size_bytes` disagrees with HEAD Content-Length.
6. **Dry-run:** accumulate counts only; **zero** `s3ms_objects` writes; **zero** postmeta writes; **zero** remote writes.
7. **Apply:** for `PRESENT` (and size OK or explicitly accepted size policy), upsert inventory rows via adopt path (**no upload**) bound to the resolved profile. For `CONFIRMED_MISSING`, record missing in report; optionally create/update missing rows only when product policy for #5 says so (default: report missing; do not invent uploads). Never upload during rebuild. **Do not overwrite `size_bytes` with remote Content-Length on `SIZE_CONFLICT`.** Existence-only (no trustworthy expected size) must not be counted as size-verified PASS.
   - **Cross-attachment key ownership (normative for #5):** before reusing `AdoptAttachmentService` / `ObjectRepository::upsert()`, treat a profile+key already owned by **another live attachment** as a conflict for **every current, non-superseded** inventory row — not only `present`/`uploading`. Hazard today: `active_profile_key_conflict()` ignores `missing`/`failed`/`pending` (and similar) rows, so upsert’s unique profile/key hash then silently rewrites that row’s `attachment_id` to the rebuilt attachment. Rebuild must report `key_owned_by_other_attachment` (or equivalent) and skip apply for that key — never silently transfer ownership.
   - **Same-attachment relative-path ownership (normative for #5):** a current non-superseded row for profile+key is idempotent **only** when both `attachment_id` **and** `local_relative_path` match the descriptor being applied. If the same attachment already owns that profile+key under a **different** relative path (e.g. path A is `missing`/`failed`/`pending` while rebuild resolves path B to the same key), report `key_owned_by_other_relative` (or equivalent) and skip — do **not** let `upsert()` rewrite `local_relative_path` from A→B and lose A’s mapping.
   - **Same-attachment relative → different key (normative for #5):** if a current non-superseded row already owns `(attachment_id, local_relative_path)` under profile+key K1 and rebuild resolves that same relative to a **different** key K2, do **not** insert a second current row. Hazard today: `active_conflict_for_descriptor()` / status filters can miss `missing`/`failed`/`pending`, and `upsert()` uniqueness is profile+key only — so Apply inserts a second current row; `AttachmentSyncDeriver` then looks partial and later repair can yield two present locations for one path. Require an atomic conflict/reconciliation: supersede/stale the old row, or report `relative_key_conflict` and skip — never two current rows for the same attachment+relative.
   - **Atomic ownership on apply (normative for #5):** a pre-check alone is insufficient under concurrency. Two rebuild workers can both pass the conflict check for a previously untracked profile+key, then the later `upsert()` steals `attachment_id` via the unique hash. Per-attachment `AttachmentLock` (§9) does **not** serialize this cross-attachment race. Apply must use one of:
     - a **conditional insert / owner-preserving upsert** that never changes an existing row’s `attachment_id` **or** `local_relative_path` to a different owner/path (conflict → skip/report); or
     - a **profile+key-scoped lock** (or equivalent serialization) with an **owner+relative recheck** immediately before write.
     Silent ownership / relative-path transfer under race is a leaf defect.
8. Conflicts (`SIZE_CONFLICT`, **cross-attachment key ownership**) and `AUTH`/`TRANSIENT` are counted separately and never coerced to missing.
9. Emit auditable summary: scanned, present_adopted, already_tracked, confirmed_missing, conflicts, transient, auth, skipped_stale, ambiguous_profile, ambiguous_key, key_owned_by_other_attachment.
10. Idempotent: second apply is a no-op for already-correct present rows.

**Non-goals for #5:** remote delete; blind prefix LIST; URL rewrite; content-hash drift (later leaf).

**Free/Pro:** **Core / Free.** This is recovery for the site’s media inventory.

### 6.4 Restore Preflight (#6)

New Core service (name suggestion: `RestorePreflightService`) shared by UI + CLI + REST.

**Computes:**

- attachment count in restore scope;
- **required-download set** (normative): only valid manifest/relative paths in scope for which the local absolute path is **absent or not trustworthy as already restored**. Matching `AttachmentRestorer`’s intent to skip already-local variants, but **`is_file($absolute)` alone is insufficient**:
  - A path with no local file → in the required-download set.
  - A path whose local file exists **and** matches a trustworthy expected size (§6.1: inventory `size_bytes` when available, else known remote Content-Length from a fresh HEAD when expected size is only remote) → **out** of download accounting (already restored).
  - A path whose local file exists but size is **unknown** (no trustworthy expected size) or **mismatches** expected size → treat as **not restored**: include in required-download set (redownload) **or** `BLOCKED`/`WARNING` per #6 policy — never silently exclude truncated leftovers from an interrupted prior `SaveAs`. Hazard: pre-atomic-download restores can leave partial finals that `is_file()` would otherwise skip, after which `mark_attachment_local()` drops inventory while keeping corrupted bytes.
- object/variant count **in the required-download set** (not every inventory variant);
- remote observation counts **over the required-download set only**: CONFIRMED_MISSING, SIZE_CONFLICT, TRANSIENT, AUTH, plus **structural location failures** (below); `SIZE_CONFLICT` detection for required downloads follows §6.1 expected-size precedence (typically inventory `size_bytes` when local is already absent / untrustworthy);
- **capacity bytes (normative):** sum known sizes over the **remotely restorable** subset only — required-download paths **minus** those observed as `CONFIRMED_MISSING` (and minus structurally unusable paths already blocked). Matching `AttachmentRestorer`, confirmed-missing objects are not downloaded; including their inventory sizes in the free-space check can `BLOCKED` an otherwise-allowed partial restore. Unknown size among the restorable subset → WARNING/BLOCKED per policy;
- local free space for uploads basedir (portable probe; see §10);
- **destination writability / directory-creation probe** for uploads basedir and any child path the **restorable** required-download paths would need to write (see §10) — capacity alone is not sufficient for `SAFE`;
- configurable reserve margin (default conservative, e.g. 10% or fixed MiB floor — finalize in #6);
- outcome: `SAFE` | `WARNING` | `BLOCKED`.

**Outcome rules (normative intent):**

| Outcome | When | UI |
|---------|------|-----|
| `BLOCKED` | See precedence below (auth, **invalid_request / non-retryable**, size conflict, disk, unknown free space, **not writable / cannot create dest dirs**, **structural location unresolved**) | Start restore disabled |
| `WARNING` | See precedence below (confirmed missing, **retryable** transient provider uncertainty) | Start requires acknowledge |
| `SAFE` | Capacity OK for restorable subset, **destination writable**, required-download set empty **or** no auth/conflict/structural issues | Start enabled |

**Remote observation → outcome precedence** (exhaustive; capacity uses **restorable** bytes after observation):

0. Any required path with a **structurally unusable** location → `BLOCKED` with an actionable reason. Structurally unusable means location data that cannot download **and** cannot use legacy fallback because profile/key binding is **genuinely absent or ambiguous**: e.g. `AMBIGUOUS_OBJECT_LOCATION`, `PROFILE_MISSING`, `OBJECT_KEY_MISSING`, **`AMBIGUOUS_OBJECT_KEY`** (and similar). Do **not** map these through `REMOTE_UNKNOWN` into acknowledge-`WARNING`.
   - **`OBJECT_NOT_PRESENT` is not structurally unusable by itself.** Today `ProfileObjectLocator::locate()` returns `OBJECT_NOT_PRESENT` (and drops the binding) when current rows exist for the path but none are `present` (e.g. inventory says `missing`/`failed`/`pending` while profile_id + object_key remain valid). Preflight **must not** treat that as a hard structural `BLOCKED` without observation: resolve an **unambiguous** current row’s stored profile/key (same single-authoritative rule as present rows; if multiple disagree → `BLOCKED` ambiguous) and **re-observe via HEAD** through that profile gateway. Classify the result under §5.1 (`PRESENT`, `CONFIRMED_MISSING`, `SIZE_CONFLICT`, `TRANSIENT`, `AUTH`, `INVALID_REQUEST`, …). Reserve structural blocking for missing/ambiguous location **data**, not for a stale non-present inventory status that still points at a usable key.
   - **`NOT_IN_INVENTORY` is path-specific** (`ProfileObjectLocator::locate()`), not “attachment has no inventory.” Preflight must distinguish:
     1. **No usable current inventory rows**, but **importer / retained exact-key evidence still exists** for the attachment (e.g. WP Offload Media import descriptors with custom profile + per-object `source_key`): **do not** classify as wholly-untracked legacy. Prefer those authoritative profile/keys for observation (same exact-key rule as partial-gap / §6.3). Legacy `S3Storage` fallback is **forbidden** while such non-legacy evidence remains — otherwise preflight HEADs the site-default key/bucket and can false-miss or approve unrelated colliding bytes. Verify inherits this rule (§6.1).
     2. **Wholly untracked attachment** (no usable current inventory rows **and** no authoritative importer/exact-key evidence): legacy `S3Storage` fallback is allowed when site Settings are configured — same as today’s `can_use_legacy_fallback()` for pure legacy offloads.
     3. **Partial gap** (this path is `NOT_IN_INVENTORY` but sibling current rows exist): do **not** mandate legacy site-default HEAD/download. Infer the unique sibling `storage_profile_id` **only as the gateway/profile binding** — siblings do **not** authorize synthesizing this path’s remote key as `prefix + relative`.
        - **Exact key required (normative for #6):** observe/download this missing path only when an **authoritative key** for that exact variant exists (importer/`ImportedObjectDescriptor` `source_key`, retained key-mapping evidence, or another exact per-object mapping). Same hazard as §6.3: `WpOffloadMediaImporter::descriptors_for_file_rows()` preserves arbitrary per-object paths that need not equal profile prefix + WordPress-relative path.
        - If siblings disagree on profile → `BLOCKED` (`ambiguous_profile`).
        - If profile is unambiguous but **no authoritative key** exists for the missing path → `BLOCKED` with reason `ambiguous_key` / structurally unusable — **never** HEAD or download a synthesized key for a partial-gap restore (wrong-object risk after import/custom prefix).
   - Reserve acknowledge-`WARNING` for **retryable** provider uncertainty only — never for deterministic `INVALID_REQUEST` / `NoSuchBucket` / bad-request classes even if a lower-level `from_head_result()` still labels them `REMOTE_UNKNOWN`.
1. Any required-object `AUTH` count > 0 → `BLOCKED`.
1b. Else any required-object **non-retryable** observation error (`INVALID_REQUEST`, HTTP 400 / `invalid_request`, `NoSuchBucket`, malformed key, other deterministic client/config failures) → `BLOCKED`. Do **not** coerce these into acknowledge-`WARNING` via `REMOTE_UNKNOWN`.
2. Else any `SIZE_CONFLICT` count > 0 → `BLOCKED` by default (optional acknowledge-WARNING mode may be added in #6 only with explicit product decision).
3. Else if `CONFIRMED_MISSING` count equals the required-download set size (and that size > 0) → `BLOCKED` (nothing restorable).
4. Else free-space unknown (when restorable bytes > 0) or free − reserve < **restorable** expected bytes → `BLOCKED`.
5. Else destination **not writable** or required child directory for a **restorable** path **cannot be created** (non-mutating ancestor writability probe fails — see §10; never `wp_mkdir_p` during preflight) → `BLOCKED`.
6. Else any **restorable** required-object **unknown download size** count > 0 → `WARNING` by default (or `BLOCKED` if #6 chooses fail-closed size policy).
7. Else any `CONFIRMED_MISSING` > 0 (partial) → `WARNING` requiring acknowledge.
8. Else any **retryable** `TRANSIENT` / unclassified **retryable** provider `UNKNOWN` > 0 → `WARNING` requiring acknowledge. Non-retryable classes must already have exited at step 1b.
9. Else → `SAFE` (including when the required-download set is empty — nothing to download).

**Preflight never starts restore** and never writes inventory/media. Appending to `AuditLog` is **optional** (default off for Integrity preflight) and, when enabled, is an explicit exemption for the audit option only — not a media mutation.

After SAFE/acknowledged WARNING, restore continues through existing `MigrationService` / `AttachmentRestorer` paths **only when profile-aware download works**.

**Resolved location must flow into download (normative for #6):** Preflight’s allowed profile/key for each required-download path is authoritative for the subsequent restore Apply. The gated download **must not** independently re-resolve via a bare `ProfileObjectLocator::locate()` and then fail or fall back to a different key. Hazard today: after a `missing`/`failed`/`pending` row is re-observed as `PRESENT`, `locate()` still returns `OBJECT_NOT_PRESENT` (it only accepts `present` rows), so `ProfileAwareObjectOperations::download_attachment_relative_to_local()` refuses the download — and a partial-gap / importer exact key that preflight HEADed can be replaced by a legacy relative-key download. `#32`’s `download_key_to_local()` alone does not fix this second lookup. Acceptable shapes: (a) pass the preflight-resolved `{profile_id, object_key}` (or location DTO) into the download path; (b) extend the download resolver to accept non-`present` current rows / authoritative importer keys the same way preflight does. A SAFE/acknowledged WARNING preflight that cannot execute the **same** resolved download is a leaf defect — do not ship gated restore Apply until observation and download share one location decision.

**Atomic verified download (normative for #6 gated restore Apply):** Restore downloads must not write the final PathGuard-jailed path until the object is fully fetched and size-validated. Hazard today: `S3Storage::download_relative()` uses SDK `SaveAs` directly to the final path; an interrupted GET can leave a **partial** file. On retry, `AttachmentRestorer::restore()` treats `is_file($absolute)` as “already local” and skips re-download, then may `mark_attachment_local()` / drop inventory while keeping corrupted bytes. Profile-aware `download_key_to_local()` alone does not fix this if it mirrors the same final-path `SaveAs` pattern. Required contract before Integrity-gated restore Apply ships:

1. Download into a **temporary file** under the same PathGuard-jailed directory (or a jailed temp sibling).
2. **Validate** downloaded size against the trustworthy expected size (§6.1 precedence / preflight known size) when available; mismatch → fail, delete temp, do not promote.
3. **Atomically rename** (or equivalent replace) temp → final path only after validation succeeds.
4. On any failure/interrupt: delete the temp (and never leave a partial final path that `is_file()` would treat as restored).
5. A path counts as restored only after the atomic promote succeeds — never after a partial `SaveAs` to the final name.
6. **Existing local files** at restore start must pass the same size-trustworthiness gate as preflight’s required-download set (above): size mismatch / unknown expected size → redownload or block — never skip solely because `is_file()` is true.

**Bind Apply to the preflight restorable plan (normative for #6):** When preflight returns `WARNING` with partial `CONFIRMED_MISSING` (operator acknowledges), capacity and outcome were computed for the **remotely restorable** subset only. Gated restore Apply **must not** independently re-HEAD every locally absent path and download objects that preflight classified `CONFIRMED_MISSING` (or otherwise excluded from the restorable plan). Hazard: between acknowledge and Apply a previously missing object may become present; `AttachmentRestorer::restore()` would then download it and exceed the capacity that produced the preflight result. Acceptable shapes: (a) pass the observed restorable key/path set into Apply and download **only** that set; (b) re-run observation + capacity immediately before download and re-evaluate SAFE/WARNING/BLOCKED. Do not ship gated restore that can exceed acknowledged preflight capacity.

**Hard prerequisite before Integrity-gated restore Apply ships:** Issue **#32** (and/or #6 Apply work) must:

1. Implement `ProfileStorageGateway::download_key_to_local()` (called today from `ProfileAwareObjectOperations::download_attachment_relative_to_local()` but missing) — **landed / verify on main before Apply**.
2. Remove/replace the legacy **site Settings** readiness gate in `AttachmentRestorer::restore()` (“AWS is not configured”) so restores of profile-credentialed / non-default-profile objects are not blocked when site-wide Settings credentials are empty. Readiness must be profile-aware (or pass when the inventory row’s profile gateway can authenticate).
3. Satisfy the **resolved-location → download** contract above (preflight and download must not diverge on profile/key).
4. Satisfy the **atomic verified download** contract above (temp → validate size → atomic rename; no partial final paths; existing `is_file` paths must pass size trustworthiness before skip).
5. Satisfy the **bind Apply to preflight restorable plan** contract (or re-preflight before download) so acknowledged partial-missing restores cannot exceed preflight capacity.

Without these, profile-located restores fail, download the wrong object, or mark corrupted partials as restored. Legacy `S3Storage::download_relative()` fallback is insufficient for non-default profile rows, importer exact keys, and interrupt-safe restore.

**Enforcement (normative for #6):** A `BLOCKED` preflight must not be bypassable by alternate callers. `#6` must gate **every** restore execution path through a shared decision, including at least:

- Integrity UI Start;
- CLI `wp universal-storage restore`;
- REST `POST /restore-batch`;
- Media Library single/bulk restore (`MediaLibraryColumn::run_many()` → `AttachmentRestorer::restore()` today).

Acceptable shapes: (a) `MigrationService::restore_batch()` and `AttachmentRestorer::restore()` both validate a fresh preflight token/result before download; or (b) all callers go through one shared gated entrypoint. Leaving Media Library calling `restorer()->restore()` directly while only batch/CLI are gated is **not** acceptable.

**Empty/invalid scope → `BLOCKED`:** If expected-variant enumeration yields zero restorable paths (empty/invalid `_wp_attached_file`, PathGuard rejection, or empty manifest after filters), preflight outcome is `BLOCKED` with reason `invalid_or_empty_manifest` — never `SAFE` with zero bytes.

**Any invalid expected path → `BLOCKED`:** If the expected set contains **at least one** path that cannot obtain a PathGuard-jailed destination (symlink-escape parent, PathGuard rejection, unusable absolute path) while other paths are valid, preflight is still `BLOCKED` (reason e.g. `invalid_restore_path`) — do **not** silently drop the rejected path from the required-download set and return `SAFE` for the valid subset. Hazard: `AttachmentRestorer::restore()` still counts the rejected path as missing and returns partial failure after a false-SAFE preflight. The zero-path rule above is necessary but not sufficient.

**Concurrent restore capacity (normative for #6):** Per-attachment `AttachmentLock` alone does not protect free space when two restore scopes pass preflight against the same `disk_free_space` snapshot and then download concurrently. Before Integrity-gated restore Apply ships, #6 must choose one: (a) a **shared uploads-volume / filesystem capacity reservation** held across Apply; (b) **serialize** restore Applies that share the uploads basedir; or (c) recheck free space **under a shared lock** as each Apply consumes capacity. Rechecking immediately before each unlocked run does **not** close the race.

**Free/Pro:** **Core / Free.**

### 6.5 Orphan reporting (Pro)

- Keep `OrphanScanService` report-only LIST semantics (no remote delete).
- **Profile-scoped LIST (prerequisite for #29):** today’s `OrphanScanService::scan_page()` loads inventory keys for the selected/default profile ID but performs LIST via legacy site-wide `S3Storage` / Settings credentials. After Pro `switch_default_profile()` to a custom profile/bucket, that can LIST the wrong location. #29 (or a tiny predecessor) must LIST through a **profile-scoped gateway** for the selected profile (or explicitly restrict the Integrity Orphans panel to the legacy profile with a clear warning).
- **Active ownership for “known keys” (prerequisite for #29):** orphan comparison must not treat **dangling** inventory rows as ownership that suppresses a LIST key. Hazard today: `AttachmentRestorer::on_delete_attachment()` can catch a remote-delete failure and leave `s3ms_objects` rows behind while WordPress still deletes the attachment post; `ObjectRepository::list_known_keys()` currently returns every `object_key` for the profile with **no** check that the owning `attachment_id` still exists / is authoritative. A later orphan LIST then hides exactly those leaked remote keys from quarantine. Before #29 ships, known-key membership must either:
  - join/filter to **active** attachment ownership (attachment post still exists, and row is current/authoritative — not superseded-only noise unless product policy says otherwise); and/or
  - run an explicit **dangling-row reconciliation** step (report or purge inventory rows whose attachment is gone) so leaked keys become visible as orphans.
  Dangling rows must never silently count as “owned” forever.
- Integrity Orphans panel shows report + “quarantine” (persist report IDs/keys — **no delete**).
- **Operational-report exemption:** Orphan “dry-run” means **no remote mutation**. Persisting resumable scan cursor / quarantine report / audit of the scan is allowed (same narrow exemption class as optional preflight audit) and does **not** violate media zero-mutation. Global “dry-run = zero persistence” applies to rebuild/verify dry-runs; orphan scan already mutates scan-state options today — document that as intentional operational state, not media mutation.
- **Quarantine persistence invariant (#29):** today’s `OrphanScanService::scan_page()` returns only `array_slice($orphans, 0, 100)` while the S3 continuation token advances across a full LIST page (up to `max_keys`, default 1000). Persisting only the returned slice would silently drop up to ~900 orphans per page. Leaf #29 **must**:
  - persist the **full** orphan set discovered on each LIST page before advancing the cursor; and/or
  - change the service so returned orphan keys and page advancement cannot diverge (e.g. `max_keys` aligned with the return cap, or return+persist all orphans on the page under a report ID).
- Future destructive delete = separate HUMAN-GATE issue.

### 6.6 Future: metadata / content drift

Out of MVP Center. Later leaf may add content-hash / WebP-AVIF sidecar consistency using characterization from Issue #7. Spec placeholder only: extend observation levels toward `content_verified` without changing HEAD confirmed_missing rules.

## 7. Dry-run vs apply matrix

| Operation | Dry-run | Apply |
|-----------|---------|-------|
| Verify | Report observations | Optional metadata refresh / confirmed-missing persist |
| Repair | List repairable | Put+HEAD+row update |
| Rebuild inventory (#5) | Report only | Adopt present rows; no upload; no remote delete |
| Restore preflight (#6) | N/A (always non-mutating) | N/A |
| Restore | (preflight first) | Existing download path |
| Orphan scan | Always | N/A (no apply delete) |

## 8. Audit / evidence model

Every Integrity **Apply** should append an audit entry via existing `AuditLog` (and, if volume requires, a dedicated `s3ms_integrity_runs` summary option/table in a leaf issue). Preflight audit is optional (default off) — see §6.4.

Minimum fields:

- `op` (`verify`|`repair`|`rebuild`|`restore_preflight`|`restore`|`orphan_scan`)
- `mode` (`dry_run`|`apply`)
- `scope` (filters, counts)
- `summary` counts by §5.1 class
- `actor` (user id / CLI)
- `started_at` / `finished_at`
- `result` (`ok`|`partial`|`failed`|`blocked`)

No secrets in audit payloads.

## 9. Retry, idempotency, locks, resumability

- **Idempotency:** rebuild/verify/repair re-entry must not duplicate inventory rows for the same attachment+profile+relative key; adopt upsert semantics stay authoritative.
- **Resumability:** batch cursor pattern from `AdoptService` / `MigrationService` / background jobs — Integrity facades should expose the same cursor/job IDs rather than inventing a third queue.
- **Locks:** reuse `AttachmentLock` for per-attachment apply; do not run rebuild-apply and restore-apply on the same attachment concurrently. Rebuild Apply that inserts/adopts by profile+key must also serialize **cross-attachment** ownership (§6.3 atomic ownership) — per-attachment locks alone do not prevent two attachments racing the same previously untracked key. Restore Apply that consumes disk must also honor §6.4 concurrent capacity reservation / serialization.
- **Failure recovery:** partial batch → `partial` summary; transient units remain retryable; never convert transient to missing on resume.
- **Queue:** prefer existing handlers; add Integrity-specific job types only if a leaf proves necessary (default: wrap existing).

## 10. Portability notes (restore preflight disk + writability)

Free-space and writability detection must work on typical WordPress hosts:

- Prefer `disk_free_space( uploads basedir )` when the basedir **already exists**.
- **When uploads basedir does not yet exist:** probe free space on the **nearest existing PathGuard-jailed ancestor** of that basedir (same ancestor selection as the writability/creatable check below). Do **not** call `disk_free_space($basedir)` on a missing path and treat `false` as permanent `BLOCKED` while the writability contract still considers the destination creatable — that falsely blocks restores that can create the uploads directory.
- If free-space probe is disabled/`false` after the correct existing-path target is chosen, outcome is **BLOCKED** (or WARNING only if issue #6 explicitly chooses soft mode — default **BLOCKED** fail-closed).
- Do not shell out to `df`.
- Byte math must be overflow-safe (`int`/`float` policy documented in #6 tests).
- **Writability probe (normative for #6):** when the restore scope requires downloads, preflight must verify that the uploads basedir (and any needed child directory under PathGuard) is writable / creatable **before** returning `SAFE`. The probe **must be non-mutating**:
  - Do **not** call `wp_mkdir_p()` (it creates directories) or any create-then-noop that leaves empty dirs behind — that contradicts preflight’s zero-mutation / non-destructive contract.
  - Determine creatability from the **nearest existing ancestor** under the uploads basedir: that ancestor must be writable (`is_writable`), and PathGuard must still jail the intended child path under basedir. If no suitable writable ancestor exists → `BLOCKED`.
  - Capacity and writability **must share the same nearest-existing-ancestor target** when basedir is absent.
  - If a leaf later permits a temporary mkdir probe, it must define an explicit create+rollback (delete only empty dirs the probe created) and still treat rollback failure as `BLOCKED` — default for #6 is the ancestor-only check with **zero** filesystem mutation.
  - Probe failure → `BLOCKED`. Do not treat capacity-OK alone as write-OK.

## 11. Free vs Pro ownership (decision)

| Capability | Owner | Rationale |
|------------|-------|-----------|
| Integrity Overview (single profile) | **Core** | Recovery UX |
| Verify / Repair / Retry UI+CLI+REST | **Core** | Already Free; must remain ungated |
| Rebuild inventory (#5) | **Core** | Inventory correctness is safety, not upsell |
| Restore preflight (#6) + gated Start | **Core** | Prevents local disk wipe/fail mid-restore |
| Failed items / Needs attention | **Core** | Already Free |
| Orphan LIST + quarantine UI | **Pro** | Physical absence today; keep |
| Cross-profile integrity views | **Pro** | Requires multiple profiles |
| Advanced health extras (orphan embed, multi-profile filters) | **Pro** | Matches `advanced_health` |
| Destructive orphan delete | Future **HUMAN-GATE** | Not in Center MVP |

**Hard rule:** deactivating Pro must not remove verify/repair/rebuild/restore preflight on the Free profile.

## 12. CLI / REST / Admin ownership

| Channel | MVP surface |
|---------|-------------|
| Admin Integrity page | Primary guided UX |
| CLI | `wp universal-storage integrity …` **or** extend existing subcommands with shared result objects (`rebuild`, `restore --preflight`, `repair`, `verify`) — leaf chooses one namespace; avoid two CLIs for one service |
| REST | Prefer thin routes calling the same facades (`/integrity/rebuild`, `/integrity/restore-preflight`, existing `/repair`, `/verify-batch`, `/health/orphan-scan`) |

All channels must share one PHP result DTO per operation (same counts/enums).

## 13. Safety invariants (normative)

1. Definitive **object** absence (NoSuchKey / NotFound / object-level 404) ⇒ `CONFIRMED_MISSING` only. Non-object 404s such as `NoSuchBucket` are **not** confirmed missing.
2. Timeout / 5xx / TLS / auth / rate limit / `invalid_request` / `NoSuchBucket` ⇒ never `missing`.
2b. Verify/rebuild size check uses §6.1 expected-size precedence; mismatch ⇒ `SIZE_CONFLICT`; Apply never overwrites `size_bytes` with remote on mismatch; no trustworthy expected size ⇒ existence-only (never false size-verified PASS).
3. No inferred deletion of remote objects.
4. Rebuild never uploads and never deletes remote/local.
5. Orphan scan never deletes.
6. Restore preflight never downloads.
7. Local deletes remain only via existing cleanup/restore/delete-attachment paths with `PathGuard`.
8. Remote deletes remain explicit key lists + safety guard — not part of Integrity apply.
9. `stale`/`deleted` variants do not poison current attachment health.
10. Free recovery remains complete without Pro.

## 14. Leaf issues

### 14.1 Existing (update contracts after this spec)

| Issue | Role after #4 | Notes |
|-------|---------------|-------|
| **#32** Profile-aware download | P0 restore prerequisite | Implement missing `download_key_to_local` before Integrity-gated restore / #6 Apply |
| **#5** Rebuild Storage Inventory | Implement §6.3 | Confirm **Core/Free**; UI entry = Integrity Rebuild panel; reuse Adopt/Manifest; dry-run zero mutation |
| **#6** Restore Preflight | Implement §6.4 | Confirm **Core/Free**; block Start when BLOCKED; shared DTO; depends on #32 for gated restore Apply |

### 14.2 New leaves to open (canonical ai-task:v1)

| Working title | Autonomy | Depends | Scope |
|---------------|----------|---------|-------|
| **#27 Integrity Center admin shell** | REVIEW | #4 | New Integrity admin page IA, Overview + navigation, deep-links from Health/Migration; no new recovery algorithms |
| **#28 Integrity verify/repair UI wiring** | REVIEW | Integrity shell | Wire Verify + Repair panels; **requires** observation-only verify dry-run + §6.1 expected-size precedence (inventory `size_bytes` when local absent) + profile-aware repair (§6.1–§6.2) before Apply is enabled |
| **#29 Orphan quarantine (Pro, non-destructive)** | REVIEW | Integrity shell | Persist orphan report for review; still no delete; **requires** profile-scoped LIST + full-page orphan persistence + **active-ownership / dangling-row** filtering (§6.5) before quarantine can ship |
| **Integrity run history / evidence** | REVIEW | shell | Structured run summaries beyond 200-ring if needed |
| **Metadata/content drift checks** | REVIEW | #7 characterization | Later; WebP/AVIF/sidecar/hash — after lifecycle QA |
| **Destructive orphan delete** | HUMAN-GATE | quarantine + security review | Explicit opt-in only; not scheduled with MVP |

### 14.3 Explicitly not leaves of #4

- Direct Upload (#8/#9)
- Cross-profile migration UX changes
- Optimizer integrations

## 15. Implementation sequencing (recommended)

```text
#4 (this spec)
 → #32 profile-aware download (P0 restore prerequisite)
 → #27 Integrity admin shell (navigation only)
 → #5 Rebuild inventory (Core)
 → #6 Restore preflight (Core; gated restore Apply needs #32)
 → #28 Verify/Repair UI wiring
 → #29 Pro orphan quarantine
 → (after #7) drift checks
 → (later HUMAN-GATE) orphan delete
```

`#5` and `#6` both touch Integrity UX but different services; they may use separate worktrees. **Do not share mutable QA runtime** concurrently. If both edit the same Integrity page, serialize or stack carefully.

## 16. Test / evidence expectations for leaves

- Characterization first where behavior exists (`tests/CHARACTERIZATION.md`).
- Unit tests for classification (404 vs 403 vs timeout) — extend `HeadKeyConfirmedMissingTest` / `VerificationObservationTest` / adopt tests.
- MinIO integration for rebuild apply + preflight happy path.
- Exact `TESTS` / `ASSERTIONS` / `FAILURES` / `ERRORS` / `SKIPPED` reporting.
- Free ZIP audit if any Free/Pro boundary code moves.

## 17. Documentation impact

When leaves ship:

- User README: Integrity section
- CLI help
- Changelog
- Update `FREE-PRO-CODE-AUDIT.md` if new routes/pages appear
- Keep this architecture doc as the design source; mark leaf deviations via follow-up issues, not silent drift

## 18. Acceptance mapping for Issue #4

| Criterion | How this doc satisfies it |
|-----------|---------------------------|
| Written architecture/spec committed | This file |
| Existing primitives reused | §3, §6 |
| Free/Pro explicit | §11 |
| Missing/transient/conflict distinct | §5 |
| Destructive ops excluded/human-gated | §2, §6.5, §14 |
| Leaf issues identified | §14 |

---

## Appendix A — Non-goals reminder

- No implementation in #4
- No destructive orphan deletion
- No Cloudflare-specific transforms
- No second S3 client stack
- No weakening of Free recovery
