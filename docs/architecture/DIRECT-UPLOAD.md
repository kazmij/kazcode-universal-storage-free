# Direct Upload — Architecture Spec (Pro 1.2.x)

**Status:** NORMATIVE HARDENING IN REVIEW (Issue #8) — **not approved**; reasoned acceptance, separate exact-HEAD Codex review, zero unresolved findings and required green CI precede merge and #9 readiness.
**Date:** 2026-09-27
**Product:** KAZCODE Universal Storage Pro (orchestration) + Core shared primitives
**Autonomy of this issue:** REVIEW — architecture decision delegated by the owner; engineering safeguards unchanged.
**Prerequisite:** #7 WordPress lifecycle QA — CLOSED
**Blocked child:** #9 Direct Upload MVP implementation (must remain blocked until this gate passes)

This document is the product/architecture contract for Direct Upload. It deliberately freezes decisions so #9 does not require guessing. It does **not** authorize implementation, pricing, licensing, or release.

---

## OWNER-DELEGATED ARCHITECTURE DECISION

On **2026-09-27 at 22:04 Europe/Warsaw**, the owner explicitly delegated the architecture decision in the Universal Storage conversation: “Human gate sam oceniaj wciel się w rolę recenzenta wtedy. Działaj całkowicie automatycznie. Pozwalam”. Issue #8 records the authorization and its scope.

The assistant may assess and accept or reject this design, but must record the decision on #8 as **AI assessment under explicit owner authorization**, with its rationale and exact reviewed SHA. This is neither a human review nor an independent review by that same assistant. Delegation is **not acceptance** of this candidate. A separate completed clean Codex review of the exact HEAD, zero unresolved findings, all required green CI, an up-to-date mergeable branch and repository protections remain mandatory. Only after accepted-spec merge may #9 be refined and marked ready. Production release, pricing/licensing, credentials, destructive storage actions and protection bypass remain outside this authorization.

| Decision area | Proposed default (subject to human override) |
|---------------|-----------------------------------------------|
| **MVP scope** | Large **non-image / non-PDF** binaries first (zip, video, audio, generic documents WordPress does not subsize). Images + PDF deferred to a follow-on leaf after remote-first finalization is proven. |
| **State machine** | §4 (CREATED → … → REMOTE_VERIFIED durable → FINALIZING_WORDPRESS → READY; FAILED / CANCELLED / EXPIRED). |
| **Security** | Server-owned bucket/key/upload_id/profile snapshot; atomic unique key **and** relative_path reservation; short-TTL part-bound UploadPart URLs only (no PutObject write in MVP); no generic sign-anything; single-flight Complete/Finalize; cancel excluded from active COMPLETING (§4.5, §5). |
| **Cleanup** | Stale UPLOADING may AbortMultipart after **24h** session expiry (configurable); **REMOTE_VERIFIED never auto-deleted** because WP finalize failed (§6). |
| **CORS** | First-class prerequisite with Pro CORS check UX; per-provider matrix, not identical-behavior promise (§9). |
| **Free/Pro** | Physical Pro ownership of orchestration/REST/UI/presign-write; Core supplies reusable multipart/HEAD and the shared reservation/profile-safety primitives needed by normal uploads too (§10). |
| **#9 readiness** | Reasoned acceptance on #8 under the authorization above, accepted-spec merge after all technical gates, then #9 acceptance/dependency refinement before `ai:ready`. |

**Product decisions for the delegated assessment** (accept or revise proposed defaults with reasons): see §16.

---

## 1. Outcome

Specify a secure **browser → object storage** Direct Upload path for Pro where:

1. bytes never traverse PHP;
2. the server creates the exact remote destination and multipart upload;
3. the browser uploads via short-lived, session-scoped **UploadPart** presigned URLs (multipart-only MVP; no PutObject write URL);
4. the server reconciles with ListParts, completes multipart, and HEADs the exact key;
5. **`REMOTE_VERIFIED` is durable** — WP finalization failures retry finalize only, never re-upload;
6. WordPress attachment + `s3ms_objects` inventory are created only after remote verification;
7. Core’s existing multipart/transfer primitives are reused — **no second storage engine**.

---

## 2. Design principles

1. **Reuse Core transfer primitives.** `StorageGatewayInterface` multipart ops, `MultipartPlan`, `RetryClassifier`, HEAD/`confirmed_missing`, and (where fields fit) `s3ms_transfer_sessions` / stale cleanup patterns. Do not fork a second ListParts/Complete stack.
2. **Browser is never authoritative** for bucket, key, upload_id, completed parts, or “upload succeeded”.
3. **Remote verification precedes WP ownership.** No attachment row / inventory `present` until HEAD size verification succeeds and is persisted as `REMOTE_VERIFIED`.
4. **Fail closed on transient errors.** Transient HEAD/ListParts never becomes “missing” or triggers Abort of a verified object.
5. **Destructive cleanup is conservative.** Abort incomplete multipart only; never delete a verified remote object waiting for WP finalize.
6. **Pro physical ownership.** Free ZIP must not ship a working Direct Upload path via filter, hidden REST, or flag.
7. **CORS is a product prerequisite**, not an afterthought FAQ.
8. **Unique remote keys and relative paths are reserved atomically** before any write URL is issued (§5.6).
9. **Complete and finalize are single-flight** — concurrent retries claim an atomic lease / compare-and-swap state transition and never create duplicate attachments (§4.5).
10. **Active sessions pin profile location** — completion/cleanup use an immutable snapshot; location edits are locked while sessions are non-terminal (§5.7).
11. **MVP write URLs are multipart UploadPart only** — no replayable PutObject write URL in this contract (§3, §5.3).

---

## 3. Normative end-to-end flow

```text
browser
  → POST create upload session (auth, capability, MIME/ext/size)
  → server validates + selects default upload profile, session UUID and attempt UUID (server-side)
  → server reserves the UUID-derived attempt object key **and** relative_path atomically (server-side)
  → server CreateMultipartUpload (multipart-only MVP) → stores upload_id on session
  → server returns session id + short-TTL UploadPart URLs bound to (key, upload_id, part_number)
  → browser PUTs parts directly to storage
  → browser POST completion candidate (session id only; optional client progress hint)
  → server claims COMPLETING (CAS/lease) → ListParts → Complete → HEAD → persist REMOTE_VERIFIED
  → server claims FINALIZING_WORDPRESS (CAS/lease) → WP attachment + inventory → READY
```

**Normative MVP write path: multipart only.** Even for files below Core’s multipart byte threshold, Direct Upload uses **CreateMultipartUpload** + one or more **UploadPart** URLs (then Complete). Optional single-object **PutObject** presigned write URLs are **out of MVP** because they remain replayable for the URL TTL after HEAD/finalize (unlike UploadPart URLs, which become unusable after Complete). A later leaf may reintroduce PutObject only with an explicit single-use / create-only condition (e.g. `If-None-Match: *`) **and** a non-replay ownership rule covered by tests — not by silent reuse of this MVP contract.

Cancel is excluded from active `COMPLETING` claims; see §4.5.

---

## 4. State machine

### 4.1 Direct Upload session states (normative)

| State | Meaning | Durable? | Browser may upload? |
|-------|---------|----------|---------------------|
| `CREATED` | Session row exists; destination key chosen; multipart not yet created | yes | no |
| `MULTIPART_CREATED` | `upload_id` stored; part plan fixed | yes | yes (after URLs issued) |
| `UPLOADING` | At least one part URL issued / progress observed | yes | yes |
| `PARTS_VERIFIED` | Server ListParts shows all required parts | yes | no (complete pending) |
| `COMPLETING` | CompleteMultipartUpload in flight or retryable | yes | no |
| `REMOTE_VERIFIED` | Complete succeeded **and** HEAD size match persisted | **yes — critical** | no |
| `FINALIZING_WORDPRESS` | Creating/updating WP attachment + inventory | yes | no |
| `READY` | Attachment + inventory consistent; session terminal success | yes | no |
| `FAILED` | Non-retryable failure (or exhausted policy) | yes | no |
| `CANCELLED` | Explicit user/admin cancel → AbortMultipart if incomplete | yes | no |
| `EXPIRED` | Past session expiry while still incomplete (pre-REMOTE_VERIFIED) | yes | no |

### 4.2 Mapping to existing Core `TransferSessionStatus`

Core today: `pending` · `uploading` · `completing` · `completed` · `failed` · `aborted` (`TransferSessionStatus`).

**Decision for #9 (proposed):**

- Use a **Pro-owned transactional SQL session table**, with the richer state machine above, while **calling Core gateway multipart primitives**. An option-backed session store does not satisfy the atomic reservation/handoff contract in §5.6.
- MVP uses a **Pro-owned cleanup job**, not a second writable Core transfer-session mirror. Core retains the generic durable reservation/profile pins even when Pro is absent (§5.6.1, §10). A future cleanup mirror needs its own reviewed ownership protocol; two independent cleanup authorities are not an MVP alternative.
- Do **not** overload Core `completed` to mean “WP ready”. Core `completed` ≈ transfer bytes verified; Direct Upload `READY` ≈ WP+inventory done. `REMOTE_VERIFIED` sits between those concepts and **must** be named and durable in the Direct Upload model.

### 4.3 Normative invariant — `REMOTE_VERIFIED`

If the remote object exists and is size-verified:

1. Persist `REMOTE_VERIFIED` **before** starting WordPress finalization (or in the same atomic DB write that records verified size/etag, **before** any WP side effects that can fail independently).
2. If WP finalization fails (PHP fatal, DB error, hook exception): retry path is **finalize-only**.
3. **Forbidden:** requiring the browser to re-upload the full object because PHP/WP failed after remote complete+verify.
4. Browser reconnect after `REMOTE_VERIFIED` must receive “finalize in progress / retry finalize”, not new part URLs.

### 4.4 Allowed transitions (summary)

```text
CREATED → MULTIPART_CREATED → UPLOADING → PARTS_VERIFIED → COMPLETING → REMOTE_VERIFIED
  → FINALIZING_WORDPRESS → READY

CREATED | MULTIPART_CREATED | UPLOADING | PARTS_VERIFIED → FAILED | CANCELLED | EXPIRED
COMPLETING → REMOTE_VERIFIED | FAILED (retry Complete while Completing lease held; cancel excluded — §4.5)
COMPLETING (lease expired, no REMOTE_VERIFIED) → claim outcome reconciliation → REMOTE_VERIFIED | pending Abort/Complete resolution → final HEAD reconcile → CANCELLED/EXPIRED only if still absent
REMOTE_VERIFIED → FINALIZING_WORDPRESS → READY | (stay REMOTE_VERIFIED / FINALIZING_WORDPRESS on WP error)
READY, FAILED, CANCELLED, EXPIRED are terminal
```

`COMPLETING` is **not** a cancellable “pre-REMOTE_VERIFIED” catch-all. `REMOTE_VERIFIED` and later states are **not** eligible for automatic AbortMultipart / object delete by cleanup workers.

### 4.5 Single-flight Complete / Finalize (normative)

Concurrent clients (retry after timeout, two tabs, duplicate POSTs) must not double-execute side effects.

| Transition | Requirement |
|------------|-------------|
| → `COMPLETING` | Atomic compare-and-swap from `PARTS_VERIFIED`/`UPLOADING` (when all parts present) **or** renew a short lease if already `COMPLETING` by the same worker token. Losers return current status without calling Complete again until lease expiry + re-claim. |
| → `REMOTE_VERIFIED` | Persist verified size/etag under the Completing claim; idempotent if HEAD already matches. |
| → `FINALIZING_WORDPRESS` | Atomic CAS from `REMOTE_VERIFIED` (or lease renew). **Only the claim holder may `wp_insert_attachment`.** |
| → `READY` | Persist `attachment_id` before releasing the finalize lease. Retries that see `attachment_id` already set must **reuse** that post/inventory and never insert a second attachment. |
| Crash after `wp_insert_attachment` before `attachment_id` persist | Finalize must be crash-idempotent. The **first `wp_posts` INSERT itself** must carry a deterministic, session-unique recovery marker (for example a provisional `post_name` derived from the session UUID), or #9 may use a pre-reserved draft id. Writing only postmeta after insert is **not sufficient** for this crash window. If `attachment_id` is still null on retry, lookup by that marker, reuse the existing attachment, persist its id, then continue inventory binding — never insert a second post. After the session has durably stored `attachment_id`, the provisional marker may be normalized if needed. |
| Cancel vs active `COMPLETING` claim | **Normative:** Cancel may CAS to `CANCELLED` **only** from `CREATED` / `MULTIPART_CREATED` / `UPLOADING` / `PARTS_VERIFIED`. Cancel **must not** win a CAS while a Completing lease is held (state `COMPLETING`). After a Completing lease expires without `REMOTE_VERIFIED`, a recovery claimant must keep the outcome pending: HEAD the exact key; if it exists with expected size, promote `REMOTE_VERIFIED` and reject cancel. A missing HEAD does **not** prove an in-flight Complete has stopped. Reconcile the known multipart ID/create intent, issue Abort only when that reconciliation permits it, wait for a definitive Abort/Complete outcome, then perform a **final exact-key HEAD** under the same fenced claim. Only a final absent HEAD plus confirmed closed/aborted multipart may CAS to `CANCELLED`/`EXPIRED`; a present or uncertain result remains/re-enters `COMPLETING` or becomes `REMOTE_VERIFIED`, never terminal cancel. Cancel after `REMOTE_VERIFIED+` is always rejected. |

#9 must include concurrent-request tests for complete and finalize, a crash-window test between attachment insert and `attachment_id` persistence, and a cancel-during-COMPLETING test that asserts no terminal `CANCELLED` + completed remote object divergence.

---

## 5. Security contract

### 5.1 Server-owned identity (non-negotiable)

| Attribute | Owner | Browser input allowed? |
|-----------|-------|------------------------|
| Storage profile / bucket / endpoint | Server (default upload target profile) | No arbitrary profile override without capability + allowlist |
| Object key | Server (`ObjectKeyService` / profile prefix + exclusive Direct Upload session-and-attempt UUID namespace + sanitized filename plan) | Filename/title hint only; never raw key |
| `upload_id` | Server (from CreateMultipartUpload) | Never client-supplied for Complete/Abort |
| Part numbers | Server plan bounds (1..N, N ≤ MultipartPlan max) | Client requests URL for part *n* only within bounds |
| Part size / total size | Server enforces min/max | Client declares intended size at create; mismatch → reject |
| MIME / extension | Server MVP allowlist **intersected** with current user's WordPress `get_allowed_mime_types()` / `wp_check_filetype_and_ext` | Client proposes; server validates; never bypass site/network MIME filters |
| Session owner | Authenticated WP user id on create | Foreign session id → 403 |

### 5.2 AuthZ / session

- Capability: **`upload_files`** (Media uploaders), not only `manage_options`. Admin tooling may additionally require `manage_options` for CORS diagnostics.
- Auth: logged-in WP user + REST nonce (cookie auth) or application password with same capability.
- Session ownership: every mutating call checks `session.user_id === current_user_id` (admins may be allowed to cancel/cleanup others via explicit `manage_options` path — product choice §16).
- Session expiry: default **24 hours** from create/last progress (human may adjust).
- Presigned URL TTL: default **15 minutes** (clamp e.g. 60s–1h); refresh via authenticated “issue part URL” endpoint, not by extending a stolen URL.
- Rate limiting: per-user create-session and issue-URL budgets (suggested: N sessions/hour, M URL issues/minute) — exact numbers in #9.
- Cross-session `upload_id` reuse: rejected.
- Cross-user session takeover: rejected.
- No arbitrary key signing; no arbitrary bucket signing; no “sign this Command JSON” endpoint.

### 5.3 Presign surface (write)

New Pro-only signer (illustrative):

- `presign_upload_part(session, part_number) → URL` bound to session’s key + upload_id + part_number + Content-Length bounds as provider allows.
- **No** `presign_put_object` in MVP (see §3 multipart-only rule).

**Must not** widen Free `S3Storage::presigned_url_for_key` / private-media GET path into UploadPart/PutObject.

### 5.4 Threat model (abbrev)

| Threat | Mitigation |
|--------|------------|
| Client picks another user’s key | Key server-generated; session-scoped |
| Stolen presigned URL | Short TTL; part-number bound; rate limit refresh |
| Complete with forged part list | Server ListParts only |
| Session fixation / CSRF | Nonce + capability + owner check |
| Pro deactivated mid-upload | Core retains durable reservation/profile pins; reactivating Pro resumes reconciliation/cleanup; Free offload/recovery remains available on non-conflicting paths, with no Free Direct Upload UI/REST |
| Audit leak of secrets | Audit fields §11 — never credentials or signed URLs |

### 5.5 Validation bounds (defaults for #9 — tunable)

| Bound | Proposed default |
|-------|------------------|
| Min part size | 5 MiB (S3 rule; last part may be smaller) |
| Default part size | Align `MultipartPlan` (64 MiB) |
| Max parts | 10_000 |
| Max total size | Product default **5 GiB** MVP (override via filter only in Pro) |
| MIME/ext MVP allowlist | Explicit non-image/non-PDF set (§8), then intersect with WordPress allowed MIME types for the current user |

### 5.5.1 Remote-only content-policy boundary (normative)

At session creation, Direct Upload can authoritatively validate only the server-planned filename/extension, client-proposed MIME, size bounds, the MVP allowlist, and the effective `get_allowed_mime_types()` / `wp_check_filetype_and_ext` result available without uploaded bytes. The server must run the same effective WordPress MIME allowlist/filter chain used for a normal upload; an allowed extension or client MIME is not a claim that arbitrary remote bytes were inspected.

The MVP has **no silent bypass** for a site policy that requires a local/content-inspecting upload callback. #9 must provide an explicit site/plugin capability/filter declaring that such content validation is required for this upload path. When it is required, unavailable, or returns an indeterminate result, Direct Upload session creation fails closed with a clear “remote-only upload is unavailable under this content-validation policy” error; it must not issue write URLs and must not fall back to accepting client-supplied MIME. A later reviewed leaf may instead add a remote-compatible post-upload quarantine validator that streams the exact HEAD-verified object into a protected temporary file, invokes the declared content policy before attachment/inventory ownership or delivery, and deletes the temporary copy; that is out of this MVP.

Thus the MVP guarantee is precise: it never bypasses WordPress's effective **name/MIME allowlist** and it refuses to operate where declared policy requires byte inspection. It does not promise that an opaque third-party content scanner, which has not declared this requirement and cannot receive remote bytes, ran on the object.

---


### 5.6 Atomic unique key **and** relative_path reservation (normative)

Remote-only Direct Upload does **not** get WordPress's local `wp_unique_filename` protection before bytes land. Distinct remote keys with a shared `_wp_attached_file` relative path are still unsafe: restore / local-file logic derives the destination from that metadata and would collide on disk.

Before CreateMultipartUpload / issuing any write URL:

1. Allocate the durable session UUID and first attempt UUID, then derive a candidate relative path from the sanitized client filename (uploads dated path as usual).
2. Derive the candidate **attempt object key** from the profile prefix and the exclusive Direct Upload namespace, e.g. `prefix/direct-upload/{session_uuid}/{attempt_uuid}/{sanitized-name}`. This exact UUID-derived key — **not** `ObjectKeyService::key_for(prefix, relative_path)` — is the `object_key` reserved here, written by multipart Complete, and later handed off to inventory. The relative path remains the separately reserved value written to `_wp_attached_file`; a relative-path collision changes that relative path, while an object-key collision requires a fresh attempt UUID/key.
3. **Atomically reserve both** of the following (unique constraints and/or reservation rows):
   - `(profile_id, object_key)`
   - `(site/blog id, relative_path)` — the exact value later written to `_wp_attached_file`
4. Reject or allocate the appropriate fresh suffix/attempt on collision with: another Direct Upload reservation; **any** existing `s3ms_objects` row for that profile+key **including `stale`** (migration may still have a queued source delete); `deleted` only after remote delete is confirmed; another in-flight session; or an existing attachment already using that relative path.
5. Only after **both** reservations succeed may the server create multipart / issue UploadPart URLs.
6. **Reservations are authoritative across upload paths (normative).** A unique index confined to the Direct Upload sessions table is **not** sufficient by itself. **Every** writer that chooses an uploads relative path or object key — Direct Upload create, normal Media Library / `wp_handle_upload` / `wp_unique_filename`, Core `ObjectOffloadService::write_owned_row()`, and any other attachment path under this site — must **atomically acquire** the shared reservation for that `(blog_id, relative_path)` and `(profile_id, object_key)` **before** creating a local attachment, writing `_wp_attached_file`, or claiming inventory. “Consult while a DU reservation exists” is not enough: the later writer must hold its own reservation. Collision → reject or force a different suffix for the losing writer; never overwrite.
7. **Shared reservation lifecycle / crash recovery is normative.** The Core-owned generic reservation registry must persist at least: reservation UUID, `blog_id`, `relative_path`, optional `profile_id/object_key`, owner kind (`direct_upload` / `normal_upload` / inventory handoff), durable owner UUID, opaque worker token, monotonic fencing epoch, state (`HELD` / `PINNED` / `OWNED`), worker `lease_expires_at`, and timestamps. Acquisition of both unique reservations and the owning DU session is transactional; no write URL or remote create is allowed before commit. `lease_expires_at` is an **execution lease**, not the lifetime of a durable owner. A normal upload uses `HELD` with a renewable short lease. A Direct Upload uses **`PINNED` from creation** and the owner-sensitive rules in §5.6.1; even a legacy DU row labeled `HELD` must be treated as pinned. Handoff converts `HELD` or `PINNED` to `OWNED` in the same DB transaction that exposes durable attachment/inventory ownership. There must be **no gap** with neither reservation nor durable owner. Generic cleanup must never delete all rows selected solely by `lease_expires_at < now`. An `OWNED` reservation is not lease-expired out from under live ownership; retirement follows the normal object/attachment deletion lifecycle, including confirmed remote cleanup and the stale-key exclusion above.
8. **Remote namespace and unmanaged-object protection are normative.** Each multipart attempt key must live below a server-generated, unguessable **session-and-attempt UUID** namespace reserved exclusively for Direct Upload beneath the selected profile prefix (for example `direct-upload/{session_uuid}/{attempt_uuid}/{sanitized-name}`). The profile must grant Direct Upload exclusive control of that namespace; a shared or legacy prefix without an isolatable Direct Upload child prefix is ineligible and session create fails closed. The exact UUID-derived attempt key in step 2 is the already-reserved final key: HEAD it and require absence before `CreateMultipartUpload`, and never treat an unmanaged existing object as this session's result. This namespace, rather than a best-effort inventory lookup, prevents Complete from overwriting a legacy/unmanaged object and ensures exact-key recovery cannot mistake foreign bytes for this session. The UUID key is intentionally **not** required to equal the attachment's `_wp_attached_file` relative path; the authoritative binding and delivery rules below are therefore mandatory.
9. Persist the first multipart-create intent in an immutable **per-attempt** record before calling CreateMultipartUpload; the session holds only an atomically fenced `current_attempt_uuid` pointer (§12.1). Every attempt record contains its immutable attempt UUID/key, generation, object-key reservation id, create intent, multipart upload id (nullable only before persistence), reconciliation/Abort/absence proof, create-outcome settlement evidence, and lifecycle state. Create the session, first attempt, both reservations, and current-attempt pointer in one transaction. Persist an attempt's `multipart_upload_id` durably as part of entering `MULTIPART_CREATED` **before returning any write URL** to the browser. The response-before-persist crash window is handled normatively, not left to #9: Core must expose a narrow paginated gateway primitive that lists incomplete multipart uploads for the **exact isolated attempt key** (S3 `ListMultipartUploads`, exact-key filter). If the **current attempt** has a create-intent marker but no persisted `multipart_upload_id`, resume/cleanup must first CAS/lease the session recovery claim (same single-flight discipline as §4.5), load and lock every non-retired attempt record, and reconcile the current attempt only; losers observe the winning pointer/attempt row and must not create a second replacement upload. The claim holder may abort only a listing result whose exact isolated key matches that attempt's durable UUID/key evidence; unidentified uploads are never aborted and remain for the provider lifecycle policy. Because no part URL is returned before id persistence, such an evidenced orphan belongs to that failed attempt. **One empty/early list result is an unknown create outcome, not absence proof:** a timed-out PHP request can still complete CreateMultipartUpload remotely after that list. Record that result and retain the attempt's `PINNED` reservation. A replacement may become current only through the fenced transaction below, while the old attempt remains `SUPERSEDED_PENDING_RECONCILIATION`; it must be revisited until either its matching upload is reconciled and aborted/closed, the provider supplies an idempotency/settlement guarantee for the recorded create request, or a documented provider lifecycle-cleanup boundary plus subsequent exact-key reconciliation proves that no late multipart upload can remain. Before a replacement create, in one transaction create a new immutable attempt record and its pinned object-key reservation, increment the generation, and advance `current_attempt_uuid` under the same session fencing epoch while retaining every older attempt and the session's relative-path reservation. Only after that commit may the claimant call CreateMultipartUpload for the new current attempt. A crash at any boundary therefore leaves either the old current attempt or a durably recorded new current attempt; recovery never overwrites singular session fields or guesses which key to reconcile. Once an older non-current attempt's remote outcome is **settled** and its remote absence or successful Abort is durably proven, atomically mark that exact attempt retired and release only its reservation under the same session/recovery claim: lock and revalidate the session pointer, owner UUID, fencing epoch, attempt UUID/key, settlement evidence, reconciled outcome, and non-current status; record the proof and release in one transaction. This retirement must neither release the session's relative-path reservation nor touch the current attempt/final `OWNED` handoff, and a stale worker, missing attempt record, early absence result, or unknown outcome keeps the old pin. A provider lifecycle rule that aborts old incomplete multipart uploads is mandatory settlement evidence where no provider idempotency/other bounded settlement mechanism exists; it is not permission to release before that boundary and exact-key reconciliation. Direct Upload must fail closed for a profile/provider that cannot support this reconciliation primitive **and** a bounded create-outcome settlement proof.

**Authoritative key binding and delivery are normative.** On finalization, the `s3ms_objects` row binds the attachment's `local_relative_path` to the UUID-derived `object_key` and selected profile; that exact inventory key—not `ObjectKeyService::key_for(prefix, relative_path)`—is the source of truth for every read, signed GET, CDN URL, restore, delete, and audit operation. Before marking `READY`, #9 must also persist an immutable, profile-bound Direct Upload attachment-location record containing at least schema version, attachment id, `relative_path`, `object_key`, profile id, and the immutable provider/**normalized region**/bucket/endpoint/path-style/prefix fingerprint used by the session. It is recovery evidence, not client-editable routing input, and survives audit-session pruning while the attachment can still be rebuilt. The inventory handoff, attachment record, and `READY` state must be crash-recoverable under the finalize claim; a retry must complete missing durable evidence rather than synthesize a key from the relative path.

Core delivery code must resolve the authoritative present inventory row before choosing a URL. Private media must sign that row's exact `object_key` against its bound profile, never call a relative-path signer. CDN delivery must likewise use the row's `object_key`; when `cdn_includes_prefix=false`, it must remove only the normalized, profile-bound prefix from that exact key before appending it to the CDN base URL, and fail closed if the key is outside that prefix. It must never substitute `relative_path` for a UUID-derived key. Rebuild Inventory must recognize the durable Direct Upload attachment-location record only when its attachment id/relative path/profile id/location fingerprint — including normalized region — all match the selected profile, then recreate the inventory row with its exact `object_key`; absent, contradictory, or profile-incompatible evidence is ambiguous and must not fall back to synthesized `prefix + relative_path`.

**Migration successor / retirement protocol is normative.** A profile migration must not strand this durable evidence on the source profile. Under the migration's attachment/object claim, it must first create a new immutable **successor** attachment-location record for the destination inventory row, with the destination profile id, exact destination `object_key`, and complete destination fingerprint (including normalized region); then atomically make that successor the record Rebuild Inventory uses for the attachment and mark the source record as historical. The source record remains protected by the source inventory's ordinary stale/delete lifecycle, but is never eligible to rebuild a destination row. If destination copy/verification/inventory handoff fails, no successor becomes current and the source record remains current. Do not synthesize a destination UUID key from `relative_path`, and do not retire the current source evidence before a verified destination successor is durable. A later migration may repeat this fenced generation handoff; contradictory concurrent/current records fail closed.

#9 must cover concurrent duplicate filenames in the same uploads directory and assert **both** `object_key` and `relative_path` differ across the two sessions; that the UUID-derived attempt key is the key held by the `(profile_id, object_key)` reservation, used for Complete, and handed off to inventory; an unmanaged object at a candidate key / a profile with no exclusive DU namespace (both reject without overwrite); cross-path races in **both** orderings; and concurrent orphan-multipart recovery (single replacement `upload_id`, never abort an unidentified upload). It must also prove that the UUID object key is used for private signed delivery and both CDN-prefix modes, that inventory rebuild after intentional row loss restores that same profile-bound key rather than synthesizing one from `relative_path`, rejects a normalized-region mismatch, and after a completed profile migration restores the current destination key from its successor evidence when destination inventory is intentionally lost.

### 5.6.1 Owner-sensitive reservation retention and release (normative)

| Owner / state | May the path/key become available to a different owner? |
|---------------|--------------------------------------------------------|
| `normal_upload` + `HELD`, worker lease current | No. |
| `normal_upload` + `HELD`, worker lease expired | Only after an atomic recovery claim fences the old worker, proves there is no durable attachment/inventory owner and reconciles any in-flight write or existing local file. Crash before any write may allow reuse; an existing file or unknown side effect is not absence. |
| `direct_upload`, **any non-terminal session** (`CREATED` through `FINALIZING_WORDPRESS`) | **Never because of worker lease, session age, idle browser, URL TTL or lack of inventory.** Reclaiming an execution lease transfers work on the same session, not ownership of its path/key. This includes `REMOTE_VERIFIED` and `FINALIZING_WORDPRESS`. |
| `direct_upload`, `FAILED` / `CANCELLED` / `EXPIRED` | Only after remote cleanup is durably confirmed, no create/complete attempt has an unknown outcome, no valid write path can recreate the object, and no attachment/inventory or retained completed object owns the destination. A terminal label alone is insufficient. |
| `direct_upload`, `READY` | The pin is **atomically handed off to `OWNED` attachment/inventory**, not released to competitors. Historical session pruning may remove the audit row only after that handoff; normal inventory deletion rules then govern ownership. |
| Missing/unreadable owner, unavailable Pro module, unknown cleanup outcome or inconsistent ownership | **Fail closed: retain the pin**, surface a recovery diagnostic, and reconcile under a claim. Neither a missing callback nor a missing session row authorizes reuse. |

**Release protocol:** lock the reservation and owning session in a fixed order, validate their current state plus owner UUID/fencing epoch, and persist either the `OWNED` handoff or an authorized release in one transaction. This also governs a superseded-attempt object-key retirement: after a **settled** create outcome and durable Abort/absence proof, release only that non-current attempt key while retaining the session's relative-path reservation and current-attempt/final-owner protections. Unique constraints continue protecting **both** identities during that transaction. Do not perform network calls while holding the transaction: record an intent/claim, reconcile outside it, then revalidate the same epoch/state before committing evidence and release. A stale worker cannot renew, finalize, issue URLs or release under its old token after another worker has claimed recovery. Lease expiry alone does not prove an already-started remote call has stopped; an early list absence and any other unknown result keep the destination pinned. A DB/lookup failure rolls back and keeps the pin.

For cancelled/expired uploads, stop issuing new part URLs, reconcile all create intents and known multipart IDs, and confirm cleanup using provider-specific evidence. `NoSuchUpload` means an upload ID is closed, **not** that no completed object exists: when Complete could have run, HEAD/reconcile the exact key before authorizing release, resolve Abort/Complete, then take a **final exact-key HEAD** before terminalization or release. A verified object without durable WP ownership remains pinned for finalize/recovery and is never cleanup-deleted. Timeout, transient HEAD/Abort/List errors or incomplete orphan enumeration leave cleanup pending. Merely waiting for an issued URL or session to expire is not cleanup confirmation.

Core's generic `PINNED` state and profile-reference guard must work **without loading Pro classes or reading a Pro callback as permission to release**. Pro owns DU state transitions and supplies the verified release/handoff evidence. If Pro is deactivated or its owner store is unavailable, Core continues refusing conflicting filename/key acquisition and unsafe profile/credential removal; reactivation resumes Pro cleanup/finalization. Do not prune the owning session before its pin is safely released or handed off.

**Required #9 regression matrix (future implementation tests, not executed by this spec):**

- Expire the execution lease while `UPLOADING`, keep session/part URLs valid and inventory empty; race normal upload and another DU against both identities. Both must reject/suffix while the original can resume/complete.
- Repeat retention checks in every non-terminal DU state, especially `REMOTE_VERIFIED` and `FINALIZING_WORDPRESS`; session expiry alone may schedule cleanup but cannot release either identity.
- Terminal cancel/expiry/failure with transient Abort, unresolved create intent or unknown Complete remains pinned; closed upload ID plus a completed exact-key object is not free space.
- Confirmed terminal cleanup with no durable/local/remote owner allows exactly one competing writer to acquire the released identities; test release/acquire in both orderings.
- Recover a create-intent crash through replacement and `READY`; crash before and after each transaction that creates an attempt row, advances `current_attempt_uuid`, persists its upload id, and retires an old attempt. Simulate a timed-out CreateMultipartUpload that first lists absent but completes remotely after recovery: the first absence remains unknown, the old pin survives the replacement, and later reconciliation aborts/settles that old upload before release. Every resume must select the durable current generation, retain all unreconciled older pins, create at most one replacement per fenced generation, and never use an overwritten session-level key/id. Retire exactly that superseded reservation only after settled create outcome plus durable old-attempt absence/Abort proof, while retaining the relative-path reservation through the final `OWNED` handoff. Later attachment/profile deletion must not be blocked by a durably retired attempt.
- Crash immediately before/after the `PINNED` to `OWNED` handoff; one durable owner always remains visible, with no duplicate `_wp_attached_file` or inventory claim. Stale worker tokens cannot undo the handoff.
- Normal upload crashes after acquire but before file/attachment insert: fenced expiry recovery can reuse safely; existing file, in-flight work or an already inserted attachment keeps ownership protected.
- Missing owner row, DB failure and Pro deactivation retain pins and profile references; reactivation reconciles without opening a Free write-presign route.

### 5.7 Immutable / pinned profile location for active sessions (normative)

Direct Upload sessions have no inventory row until finalization, so `ObjectRepository::count_by_profile() === 0` must **not** allow administrators to rewrite bucket/endpoint/prefix out from under an in-flight upload.

**Normative requirements:**

1. On session create, persist an **immutable location snapshot** on the session row: at minimum `provider_type`, `bucket`, `region`, `endpoint`, `path_style`, `prefix`, and an immutable **credential-version identifier** (not merely a mutable `credentials_ref`). Subsequent ListParts / Complete / Abort / HEAD **must** use this snapshot (or a client built from it), never a live re-read of a mutated profile. The referenced credential version must remain decryptable/usable until the session reaches `READY` and its inventory handoff is durable, or until terminal cleanup is confirmed. Credential rotation creates a new version; switching to shared credentials or deleting/replacing an old secret must not delete or overwrite a version still pinned by a non-terminal or cleanup-pending session.
2. While any Direct Upload session for a profile is non-terminal (`CREATED`…`FINALIZING_WORDPRESS`), profile location fields are **locked** exactly as if inventory count > 0 (`StorageProfileAdminService` must treat active DU sessions as location-locking references).
3. **Profile deletion / credential removal has two independent blockers and both are normative:**
   **(a)** any **non-terminal Direct Upload session** for that profile (`CREATED` through `FINALIZING_WORDPRESS`) blocks deletion regardless of whether the multipart upload is already closed; this covers `REMOTE_VERIFIED` / `FINALIZING_WORDPRESS`, which may not have inventory yet;
   **(b)** any terminal `FAILED` / `CANCELLED` / `EXPIRED` session with **remote cleanup pending or an unresolved remote outcome** also blocks deletion until cleanup is proven: reconcile every create/Complete attempt, resolve Abort versus Complete, and take the final exact-key HEAD required by §5.6.1. Provider-confirmed `NoSuchUpload` proves only that an upload ID is closed; it does not by itself clear this blocker.
   Persist explicit cleanup/outcome state such as `remote_cleanup_pending`, `remote_outcome_reconciled_at` and `multipart_closed_at` rather than inferring it only from non-null `multipart_upload_id`. Successful Complete marks the multipart as closed and therefore not abort-pending, but the session still blocks profile deletion until it reaches a terminal success state. A retained historical `READY` row with cleanup complete does **not** by itself block deletion once its normal inventory/object ownership has been removed. If Abort fails transiently, a final HEAD is transient/unknown, or Complete remains unresolved, credentials must remain available and `AdditionalStorageProfileService::delete()` must refuse. Zero inventory is **not** sufficient while either blocker applies.
4. Administrators may rotate or switch the profile's credentials for future work only through immutable versioning; the old version remains retained for every pinned session. Implementations may alternatively reject credential rotation/switch while such pins exist. In either case, changing bucket/endpoint/region/prefix while sessions are active is rejected.

#9 must test: mutate profile mid-UPLOADING is rejected (or ignored by completion using snapshot); Completing after a forbidden mutation attempt still targets the original bucket/key; credential rotation and custom→shared switch retain the exact credential version usable by `UPLOADING`, `COMPLETING`, `REMOTE_VERIFIED`, `FINALIZING_WORDPRESS`, and cleanup-pending terminal sessions; delete profile in those non-terminal states is rejected; delete profile while terminal failure/cancel/expiry still has cleanup pending **or an unresolved remote outcome** is rejected and credentials remain usable; `Abort`/`NoSuchUpload` alone does not clear the blocker when Complete could have run; only reconciliation plus the required final absent HEAD and no durable owner clears the cleanup blocker and version pin; a historical `READY` session with cleanup complete relies on normal inventory ownership and does not create a permanent deletion lock.

## 6. Multipart recovery (reuse Core semantics)

Reuse patterns from `ResumableTransferService` / `StaleTransferCleanupService`:

| Scenario | Behavior |
|----------|----------|
| Browser refresh / reconnect | GET session → if pre-REMOTE_VERIFIED, re-issue missing part URLs after ListParts; if REMOTE_VERIFIED+, finalize-only |
| Duplicate part submit | Idempotent; ListParts wins; same etag OK |
| Stale client part list | Ignored; server ListParts authoritative |
| Missing part | Remain UPLOADING; do not Complete |
| Complete timeout / unknown | Stay COMPLETING; retry Complete **or** HEAD first (object may already exist) |
| Complete succeeded, response lost | HEAD exact key + size → promote REMOTE_VERIFIED without re-Complete when possible; else safe Complete retry per provider rules |
| HEAD delayed / transient | Stay COMPLETING; do not FAILED-as-missing; do not Abort |
| PHP crash before REMOTE_VERIFIED persist | On resume: HEAD; if verified size match → persist REMOTE_VERIFIED; else ListParts/Complete resume |
| PHP crash after CreateMultipartUpload before upload_id persist | The durable current-attempt row has its UUID/key/create intent but no id: **CAS/lease recovery** loads the fenced current pointer plus all unreconciled older attempts, lists incomplete multipart uploads for that exact isolated key, and aborts only a result matching that attempt's durable ownership evidence (no part URL was ever returned). An initially empty list is an unknown outcome—Create may still finish remotely—so it retains the old pinned reservation while a replacement becomes a new durable generation plus reservation and atomic current-pointer advance before Create/persist of its upload id. Retire the old generation only after matching-upload reconciliation plus Abort/closure, provider idempotency/settlement evidence, or a provider lifecycle-cleanup boundary followed by exact-key reconciliation proves no late create can remain. Keep the session's relative-path reservation through final handoff. A crash at any replacement/retirement boundary resumes from the attempt rows without losing the old generation. Unidentified uploads are left to lifecycle policy. Concurrent resume+cleanup must not create two replacements for one generation. |
| Crash after REMOTE_VERIFIED | Finalize-only retry |
| Crash after wp_insert_attachment before attachment_id persist | Lookup **only** by the deterministic in-row recovery marker from the first `wp_posts` INSERT (or by pre-reserved draft id) — **never** by postmeta-only binding; continue finalize — no second attachment |
| WP finalize failure | Remain REMOTE_VERIFIED or FINALIZING_WORDPRESS; retry finalize; **never** Abort verified object |
| Expired session (pre-verify) | Claim outcome reconciliation; after Abort/Complete is definitive, final exact-key HEAD; only then EXPIRED/CANCELLED when absent |
| Stale UPLOADING cleanup | After expiry/age policy → AbortMultipart + EXPIRED/CANCELLED; **never** if REMOTE_VERIFIED+ |

---

## 7. Cleanup policy

| State class | Auto cleanup |
|-------------|--------------|
| Incomplete (`CREATED` / `MULTIPART_CREATED` / `UPLOADING` / `PARTS_VERIFIED` / expired UPLOADING) | May **AbortMultipartUpload** after session expiry (**proposed 24h**) or shared stale age; mark EXPIRED/CANCELLED |
| `COMPLETING` (lease expired) | **Must not** Abort blindly: under a fenced recovery claim, HEAD exact key first (same reconcile as §4.5). Object present → promote `REMOTE_VERIFIED` (never CANCELLED). An absent first HEAD keeps the outcome pending: reconcile/Abort the multipart, resolve Abort versus an in-flight Complete, then take a **final exact-key HEAD**. Only confirmed closed/aborted multipart plus final absence may terminalize EXPIRED/CANCELLED. |
| `REMOTE_VERIFIED` / `FINALIZING_WORDPRESS` | **Never** auto-delete remote object; never Abort as “stale upload”; may retry finalize via queue |
| `READY` | Session retained for audit TTL then row prune only (object owned by inventory) |
| `FAILED` / `CANCELLED` / `EXPIRED` | Abort if multipart still open; no delete of unrelated keys |

**Normative:** Cleanup worker must not remove a verified remote object that is only waiting on WordPress finalization. Session terminalization and reservation release are separate operations: every row above is subject to §5.6.1. Abort failure, unknown remote outcome or an unavailable owner preserves the pin; `READY` requires atomic inventory handoff before audit pruning.

Align with Core invariant: `StaleTransferCleanupService` never `deleteObject`; only Abort incomplete multipart or mark COMPLETED when HEAD already matches. Direct Upload must preserve that conservatism and add the REMOTE_VERIFIED exclusion.

---

## 8. WordPress lifecycle & MVP file-type policy

### 8.1 Lifecycle inversion vs Free offload

| Path | Order |
|------|-------|
| Free offload today | WP attachment + local files → server upload → HEAD → inventory → optional local delete |
| Direct Upload | Browser upload → Complete → HEAD → **REMOTE_VERIFIED** → WP attachment + `_wp_attached_file` + metadata + inventory → **READY** |

`_wp_attached_file` remains a **relative path**, never a URL. Public URLs still come from live profile delivery resolvers, but those resolvers must obtain the exact `object_key` from the authoritative profile-bound inventory row; a Direct Upload UUID key is never reconstructed from `_wp_attached_file`.

### 8.2 Attachment creation point (MVP)

After `REMOTE_VERIFIED`:

1. Create `attachment` post (or bind pre-reserved draft id if product chooses reservation — §16).
2. Set `_wp_attached_file` to server-planned relative path.
3. Build minimal attachment metadata appropriate to MIME (non-image: filesize/mime; no `sizes`).
4. Insert `s3ms_objects` row(s) with `remote_status=present`, profile/key binding, and the attachment's `local_relative_path`; its UUID-derived `object_key` is authoritative for serving/restoring/deleting this object (§5.6).
5. Persist the immutable profile-bound Direct Upload attachment-location record defined in §5.6. It must retain enough verified location identity for `RebuildInventoryService` to recreate the exact key if the inventory row is later lost; it is not replaced by a session audit row that may be pruned.
6. Derive attachment sync status via existing deriver rules.
7. Local file policy: MVP default **remote-only** (no mandatory local materialization); Image Editor paths out of MVP.

### 8.3 Explicit MVP exclusions

| Topic | MVP | Later leaf |
|-------|-----|------------|
| Images (JPEG/PNG/GIF/WebP/AVIF/HEIC) + WP subsizes | **Out** | After remote-first finalize + derivative strategy |
| PDF (preview/thumb plugins) | **Out** | Same |
| Image Editor / regenerate | **Out** | Needs LocalFileProvider story |
| `original_image` / HEIC retained source | **Out** | Lifecycle matrix #7 |
| WebP/AVIF optimizer sidecars | **Out** | |
| WooCommerce downloadable product files | Optional later | |
| Sidecar/multi-object manifests | **Out** | Single object per session in MVP |

### 8.4 MVP allowlist (proposed)

Allow extensions/MIME that WordPress treats as non-subsize binaries, e.g. common video/audio/archive/document types **excluding** `image/*` and `application/pdf`. Exact list is an #9 constant + tests; unsupported → clear 400 at session create.

### 8.5 Delete attachment after READY

Reuse `AttachmentRestorer::on_delete_attachment` / explicit key delete + inventory row cleanup — same as offloaded media. Direct Upload must not invent a second delete path.

### 8.6 Failure taxonomy (WP side)

| Failure | Session state | Remote object |
|---------|---------------|---------------|
| Finalize throws | REMOTE_VERIFIED (or FINALIZING_*) | Kept |
| Orphan attachment (WP row without inventory) | Repair finalize | Kept |
| Orphan object (verified remote, no WP) | Finalize retry / admin recover tool | Kept — **not** cleanup-deleted |

---

## 9. CORS / providers

Direct browser → storage **requires** correct bucket CORS. Spec treats CORS as a **first-class prerequisite**.

### 9.1 Required CORS shape (logical)

- **Origins:** site origin(s) (admin + front if front-end uploader ships later; MVP = wp-admin origin).
- **Methods:** at least `PUT`, `GET`, `HEAD`, `POST` (provider-dependent for multipart complete — complete stays server-side, so browser mainly needs PUT for parts + possibly POST for some providers).
- **Headers:** `Content-Type`, `Content-MD5` (if used), any checksum headers the client sends; `Authorization` not needed for query-presigned PUTs.
- **ExposeHeaders:** **`ETag`** (required for many browser multipart clients; some providers need explicit expose).
- **MaxAgeSeconds:** optional cache of preflight.

Pro must ship:

1. Documented CORS XML/JSON examples per provider class.
2. A **CORS check** admin action (OPTIONS/PUT probe or documented headless check) that reports pass/fail without storing secrets.

### 9.2 Provider matrix (no identical-behavior promise)

| Provider class | Notes for Direct Upload |
|----------------|-------------------------|
| AWS S3 | Reference behavior; SigV4 query presign UploadPart |
| Cloudflare R2 | S3-compatible; CORS + ETag expose quirks — must be in test matrix |
| DigitalOcean Spaces | S3-compatible; verify part ETag |
| Wasabi | S3-compatible; verify CORS console differences |
| Backblaze B2 (S3 API) | S3-compatible endpoint; confirm UploadPart presign |
| MinIO / generic S3 | Dev/CI primary smoke; path-style / virtual-host |

**Direct Upload capability gate:** the selected provider/profile must support **both** (1) exact-key reconciliation of incomplete multipart uploads via the Core gateway primitive required by §5.6 and (2) a bounded, durable settlement mechanism for a timed-out `CreateMultipartUpload` whose first exact-key listing is empty. The latter must be either provider idempotency/another documented bounded settlement guarantee for the recorded create request, or an enforced bucket lifecycle-abort rule with a known boundary followed by exact-key reconciliation. The capability check must verify and persist which mechanism applies to the immutable session location snapshot; a lifecycle rule is therefore mandatory evidence when it is the selected mechanism, not merely a recommendation. If either condition is unavailable, cannot be proven safe, or cannot remain available for recovery, Direct Upload creation for that profile fails closed.

**CI:** MinIO browser-CORS smoke in #9 or a dedicated leaf. Real-cloud E2E remains HUMAN-GATE / runbook (`docs/testing/AI-REAL-CLOUD-E2E-RUNBOOK.md`) — not implied green by unit tests alone.

---

## 10. Free / Pro boundary

| Concern | Ownership |
|---------|-----------|
| `StorageGatewayInterface` multipart + HEAD | Core (reuse; #9 may add the narrow exact-key incomplete-multipart listing primitive required by §5.6) |
| Shared reservation registry and profile-reference guard | **Core generic safety primitive**, also used by normal WordPress/Core writers. Durable pins remain effective with Pro disabled; no Pro class dependency, DU route or write signer is introduced into Free. |
| GET presign / public-CDN delivery | Core resolver consumes the authoritative inventory key; #9 must close relative-path fallbacks for Direct Upload UUID keys |
| UploadPart **write** presign (multipart) | **Pro only** (MVP; no PutObject write presign) |
| Direct Upload session orchestration / state machine | **Pro only** |
| REST routes `/direct-upload/*` (name TBD) | Registered only when Pro boots; absent → no route or 403 via ProServices pattern |
| Media UI / uploader | **Pro only** |
| Feature key | Add `direct_upload` to `Features::pro_feature_keys()` **and** physical Pro classes |
| Free offload / resume / restore / import | Unaffected when Pro deactivated |

**Leakage tests (#9):** grep Free package for Direct Upload orchestration classes/routes; deactivate-Pro characterization: incomplete browser sessions do not unlock Free write-presign, existing pins still block conflicting writers and profile deletion, and unrelated Free offload/recovery continues. The generic reservation safety primitive is not a hidden Free Direct Upload implementation.

---

## 11. Observability / audit

Audit / admin session view may include:

- session id (uuid)
- user id / attachment id (once known)
- profile id
- remote key
- upload id
- parts count / bytes expected & observed
- state transitions with timestamps
- created / updated / expires
- last error **class** (not raw secret-bearing provider payloads)
- retry counts

**Never log:** credentials, signed URLs, secret query params, raw Authorization headers.

---

## 12. Schema / persistence (proposed for #9)

### 12.1 Pro Direct Upload sessions (preferred)

New table e.g. `{prefix}s3ms_direct_upload_sessions` plus `{prefix}s3ms_direct_upload_attempts` (Pro migration via Core schema hook or Pro-owned installer — exact mechanism in #9; must be upgrade-safe):

The **session** row contains `uuid`, `user_id`, `profile_id`, `relative_path`, `mime`, `ext`, `size_bytes`, `current_attempt_uuid` (non-null after initial creation), `current_attempt_generation`, `remote_cleanup_pending`, `part_size_bytes`, `part_count`, `state`, `bytes_reported`, `etag`, `attachment_id` (nullable until finalize), `last_error_class`, `attempt_count`, `expires_at`, `created_at`, `updated_at`, `remote_verified_at`, `ready_at`, plus **immutable location snapshot** fields from §5.7 (`provider_type`, `bucket`, `region`, `endpoint`, `path_style`, `prefix`, `credential_version_id`). It must not overwrite a singular `multipart_upload_id`, create intent, or attempt key when recovery replaces a generation.

The **attempt** row contains immutable `uuid`, `session_uuid`, monotonically increasing `generation` (unique per session), `object_key` (the UUID-derived attempt key reserved in §5.6, never a `prefix + relative_path` surrogate), reservation id, `create_intent_at`, `multipart_upload_id` (nullable only while a create intent has no known id), `multipart_closed_at`, `remote_cleanup_pending`, exact-key reconciliation / Abort-or-absence proof fields, `create_outcome_settled_at` plus the provider/idempotency/lifecycle settlement evidence, lifecycle state (`CURRENT`, `SUPERSEDED_PENDING_RECONCILIATION`, `RETIRED`), owner UUID/fencing epoch, and timestamps. The session's `current_attempt_uuid` and every attempt-row transition are updated transactionally with the associated reservation records. A new attempt is inserted and becomes current in the same fenced transaction before any remote Create; a non-current attempt becomes `RETIRED` only in the settled-outcome proof-and-release transaction described in §5.6. A foreign key (or equivalent fail-closed integrity check) prevents a session pointer to a missing/wrong-session attempt. Finalization reads the durable current attempt's exact key and binds it to inventory; it never reconstructs or selects a key from a superseded generation.

Credential versions must be immutable and reference-counted/pinned by active and cleanup-pending sessions; they may be retired only after their final session/inventory reference is gone. On `READY`, persist the separate durable attachment-location record from §5.6 (attachment id, relative path, exact current-attempt object key, profile id, **normalized region** plus the other location-fingerprint fields, schema version, current/superseded generation state); it is retained independently of the prunable session/audit record and is recognized by `RebuildInventoryService` only after exact profile-bound validation. `MigrateObjectService` must perform the successor/current-record handoff in §5.6 atomically with verified destination inventory ownership; it cannot treat a source record as evidence for a different profile. The Core-owned shared reservation registry from §5.6 must have its own durable owner UUID, state (`HELD` / `PINNED` / `OWNED`), worker token/epoch/lease fields, profile-reference guard and unique constraints for `(blog_id, relative_path)` and, where a remote key exists, `(profile_id, object_key)`. Session, attempt, and reservation updates must share a transactional DB boundary; #9 must verify actual table engines and fail closed if atomic acquire/handoff cannot be guaranteed. Store the relative-path reservation id on the Pro session and each object-key reservation id on its attempt; do not derive reservation lifetime from worker timestamps.

### 12.2 Cleanup ownership

MVP uses the Pro Direct Upload session/attempt tables and Pro cleanup job as the single DU cleanup authority. Core supplies multipart primitives and retains generic safety pins; it does not infer that Pro absence makes an upload stale/free. A future `s3ms_transfer_sessions` mirror is deferred until a separate ownership/coordination contract is reviewed, including exclusion of `REMOTE_VERIFIED+` from Abort and preservation of §5.6.1 pins.

### 12.3 Inventory

No new object status required for MVP: after finalize, use existing `ObjectRemoteStatus::PRESENT`. Direct Upload session state carries pre-inventory lifecycle.

---

## 13. REST surface (illustrative contract for #9)

Namespace under existing `kazcode-storage/v1` or Pro sub-namespace — exact paths in #9.

| Method | Purpose | Auth |
|--------|---------|------|
| `POST /direct-upload/sessions` | Create session (filename, size, mime) | `upload_files` + nonce |
| `GET /direct-upload/sessions/{uuid}` | Status / resume | owner |
| `POST /direct-upload/sessions/{uuid}/parts/{n}/url` | Issue short-TTL UploadPart URL | owner |
| `POST /direct-upload/sessions/{uuid}/complete` | Completion candidate → ListParts → Complete → HEAD → REMOTE_VERIFIED | owner |
| `POST /direct-upload/sessions/{uuid}/finalize` | WP finalize retry | owner |
| `POST /direct-upload/sessions/{uuid}/cancel` | Cancel + Abort if incomplete | owner |
| `POST /direct-upload/cors-check` | Admin CORS probe | `manage_options` |

All responses omit signed URLs from logs; URLs returned only in HTTPS response bodies to the authorized caller.

---

## 14. Test plan for #9 (matrix)

### 14.1 Happy path

- create → multipart URLs → upload parts → ListParts → Complete → HEAD → WP finalize → READY
- small file still uses multipart (single part OK) → HEAD → finalize → READY — **no PutObject write URL path in MVP**

### 14.2 Recovery

- browser refresh mid-UPLOADING
- duplicate part
- missing part (Complete rejected)
- Complete timeout
- Complete succeeded but response lost
- HEAD delayed / transient
- PHP crash after CreateMultipartUpload but before upload_id persist → CAS/lease + fenced durable current-attempt pointer + exact isolated-attempt-key orphan list; an early empty list remains pinned/unknown while a fresh persisted generation may become current; retire the old generation only after bounded create-outcome settlement and exact-key reconciliation; concurrent recovery does not leak a second upload or lose an older attempt record
- PHP crash before REMOTE_VERIFIED persist
- crash after REMOTE_VERIFIED
- WP finalize failure → retry finalize only (assert no new CreateMultipartUpload)
- concurrent complete retries (single Complete / single REMOTE_VERIFIED)
- concurrent finalize retries (single attachment_id; no duplicate posts)
- cancel while COMPLETING lease held → rejected; after lease expiry, simulate an initially absent HEAD with Complete still in flight; reconcile Abort/Complete and final HEAD → REMOTE_VERIFIED if the object appears, otherwise terminal cancel only after confirmed Abort + final absence
- PutObject write URL **not** offered by MVP API (negative test)

### 14.3 Security

- foreign session
- wrong/missing nonce
- missing `upload_files`
- arbitrary key / bucket in request body ignored/rejected
- invalid part number
- expired URL
- expired session
- MIME/ext mismatch
- oversized upload
- concurrent duplicate filenames → distinct reserved **object_key and relative_path** (no overwrite; no shared `_wp_attached_file`)
- MIME allowed by Pro allowlist but blocked by upload_mimes → reject
- a declared content-inspecting site policy → Direct Upload create fails closed before a write URL; no claim that undeclared byte scanners ran in the remote-only MVP
- key collision with stale inventory row → reject/suffix
- finalize crash after attachment insert → retry reuses same attachment via **in-row recovery marker** / pre-reserved draft id (postmeta-only binding is insufficient)
- active session pins profile location and credential version → mid-upload bucket/endpoint/prefix change rejected or ignored by snapshot-based Complete/Abort; credential rotation/custom→shared switch cannot invalidate the pinned credential version
- profile deletion blocked for **all non-terminal DU states**, including `REMOTE_VERIFIED` / `FINALIZING_WORDPRESS`, plus terminal rows whose remote cleanup is still pending; historical READY+cleanup-complete row is not a permanent lock
- PutObject / generic write-sign endpoint absent from Pro REST surface in MVP
- every filename/object-key chooser atomically acquires shared reservation — race tests for **both** orderings (DU first; normal upload first)
- owner-sensitive lease expiry/release matrix in §5.6.1, including active DU with no inventory, terminal cleanup-pending DU, normal-upload crash, stale worker, atomic handoff and missing owner/Pro deactivation
- concurrent orphan-multipart recovery (create-intent, null upload_id) → single replacement generation/id; crash immediately before/after durable replacement-attempt insert, current-pointer advance, upload-id persist, and old-attempt retirement; no leaked second upload, no lost old attempt, and no use of a superseded key. An initial exact-key list absence followed by a late successful CreateMultipartUpload retains the old pin until the matching upload is reconciled/aborted or provider lifecycle settlement plus exact-key reconciliation proves it gone. Only then does that superseded attempt-key reservation retire while the relative path stays protected through `READY`, and later deletion is not profile-pinned by that retired attempt
- isolated Direct Upload namespace is required; unmanaged pre-existing candidate object and a profile lacking an exclusive DU child prefix both fail closed without overwrite
- exact-key incomplete-multipart recovery aborts only a result carrying the durable session/attempt ownership evidence; an unidentified shared-bucket upload is left to provider lifecycle cleanup
- UUID-derived object key is served—not `_wp_attached_file`—for private signed media and CDN delivery with `cdn_includes_prefix=true` and `false`; prefix-less CDN stripping accepts only the matching profile prefix and otherwise fails closed
- remove a READY Direct Upload inventory row, retain the durable profile-bound attachment-location record, then rebuild: the recreated row has the original UUID `object_key`; absent, mismatched-profile, normalized-region-mismatched, or contradictory record is rejected as ambiguous and never synthesized from `prefix + relative_path`
- migrate a READY Direct Upload object, then intentionally remove destination inventory: Rebuild Inventory uses only the durable current destination successor record and restores its exact destination key; failed migration leaves source evidence current, and concurrent/contradictory successor records fail closed


### 14.4 CORS / providers

- MinIO smoke with browser-like PUT + ETag
- Documented manual matrix for AWS/R2/Spaces/Wasabi/B2

### 14.5 Free/Pro

- Free ZIP / Core tree: no working write-presign Direct Upload
- Pro deactivated: Free offload/recovery still works

### 14.6 Evidence

Unit + integration counts; `git diff --check`; characterization updates; no secret leakage in REST fixtures.

---

## 15. Implementation breakdown (leaf issues after approval)

Do **not** mark these `ai:ready` until #8 has recorded reasoned acceptance under the owner delegation and the accepted spec has merged with all technical gates satisfied. Then refine #9 from that exact spec. The suffixed labels below describe planned leaves, not already-created GitHub issue numbers.

| Leaf | Outcome | Autonomy (suggested) |
|------|---------|----------------------|
| **#9** (existing) | Direct Upload MVP per this spec (session+REST+presign+finalize+tests) | REVIEW |
| **#9a** (new after approval) | CORS admin check UX + provider docs | REVIEW |
| **#9b** | Image/PDF Direct Upload + derivative strategy | HUMAN-GATE or REVIEW |
| **#9c** | Front-end / non-admin uploader (if product wants) | REVIEW |
| **#9d** | Real-cloud Direct Upload E2E evidence | HUMAN-GATE |

#9 body should be updated post-approval to reference this document path and freeze MVP exclusions.

---

## 16. Unresolved product decisions (explicit)

The owner-authorized AI assessment on #8 must accept or revise these defaults with rationale; listing them here is not approval:

1. **MVP file types:** non-image/non-PDF only? (proposed: **yes**)
2. **Session expiry:** 24h?
3. **Presign TTL:** 15m?
4. **Max upload size MVP:** 5 GiB?
5. **Pre-create draft attachment before upload vs create only after REMOTE_VERIFIED?** (proposed: **after** — fewer orphan posts)
6. **Admin may manage others’ sessions?** (proposed: `manage_options` cancel/cleanup only)
7. **Persistence and cleanup:** transactional **Pro table + Pro cleanup job**, with generic Core reservation/profile pins (§4.2, §5.6.1, §12); no dual-writer transfer mirror in MVP.
8. **Local file after READY:** remote-only vs optional download cache? (proposed: **remote-only** MVP)
9. **Whether #9a CORS UX is in MVP or immediately after?** (proposed: **minimal CORS check in #9 MVP**, fuller docs UX can be #9a)

---

## 17. Known risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Provider CORS/ETag inconsistency | High | Matrix + Pro CORS check; no “works everywhere” claim |
| WP finalize bugs leave remote orphans | Medium | Durable REMOTE_VERIFIED + admin recover; never auto-delete verified |
| Accidental Free leakage of write presign | High | Physical Pro + grep tests |
| Confusing Core transfer status with DU session state | Medium | Separate Pro state machine; clear docs |
| Image demand before derivatives ready | Product | Explicit MVP exclusion messaging |
| Cleanup aborting verified object | Critical | Hard exclusion in cleanup policy + tests |
| Duplicate filename key collision | High | Atomic key **and** relative_path reservation before write URLs (§5.6) |
| Concurrent finalize duplicate attachments | Critical | CAS/lease single-flight finalize (§4.5) |
| Finalize crash before attachment_id persist | High | In-row recovery marker on first INSERT / pre-reserved draft (§4.5) — not postmeta-only |
| Reusing stale key before source delete finishes | High | Treat stale inventory keys as reserved (§5.6) |
| Bypassing WP MIME filters | High | Intersect Pro allowlist with upload_mimes (§5.5) |
| Orphan CreateMultipartUpload before id persist | Medium | Persist immutable per-attempt ownership intent and fenced current pointer first; exact isolated-attempt-key reconciliation + abort only evidenced orphan before a durable replacement generation; lifecycle rule handles unidentified uploads (§5.6 / §6 / §12) |
| Shared/legacy bucket object overwritten | Critical | Require exclusive unguessable Direct Upload session namespace and absent-key verification; profiles without it fail closed (§5.6) |
| Cancel during COMPLETING leaves orphan object | Critical | Cancel excluded from active Completing claim; HEAD reconcile (§4.5) |
| PutObject URL replay after READY | Critical | Multipart-only MVP; no PutObject write URL (§3, §5.3) |
| Profile location rewrite under active session | Critical | Immutable location snapshot + admin location lock (§5.7) |
| Profile deletion during DU lifecycle/cleanup | Critical | Block for every non-terminal session **or** terminal cleanup-pending session; explicit cleanup state (§5.7) |
| Shared relative_path across sessions | Critical | Dual reservation of key and relative_path (§5.6) |
| Normal WP upload races reserved DU path/key | Critical | Shared durable reservation registry; atomic acquire on every writer; lease + crash-safe ownership handoff; both race orderings (§5.6) |
| Concurrent orphan multipart recovery | Critical | CAS/lease around list/abort/create-id persist (§5.6 / §6) |
| Replacement recovery loses or selects the wrong generation | Critical | Immutable per-attempt rows, transactional fenced current-attempt pointer, and proof-gated retirement; crash-boundary tests cover every pointer/attempt transition (§5.6 / §6 / §12) |
| Worker lease reclaims active DU destination | Critical | Durable owner-sensitive `PINNED` lifetime; terminal+confirmed cleanup or atomic `OWNED` handoff only; stale-worker fencing and fail-closed missing-owner handling (§5.6.1) |
| UUID remote key becomes unreachable or unrebuildable | Critical | Inventory-key-first private/CDN delivery plus durable profile-bound attachment-location evidence recognized by rebuild; never reconstruct from relative path (§5.6 / §8.2) |
| Profile migration strands UUID-key rebuild evidence | High | Atomic verified destination successor/current-record handoff; source evidence retires only after destination evidence is durable (§5.6) |

---

## 18. Acceptance criteria for #8 (this issue)

- [x] Design spec committed at `kazcode-universal-storage/docs/architecture/DIRECT-UPLOAD.md`
- [x] Threat model / security invariants explicit (§5)
- [x] State machine and recovery semantics explicit (§4, §6)
- [x] Core-vs-Pro class ownership explicit (§10)
- [x] DB/schema changes explicit (§12)
- [x] MVP exclusions explicit (§8)
- [x] Implementation broken into leaf issues (§15)
- [ ] **Reasoned AI architecture acceptance under explicit owner authorization** recorded on #8 with exact reviewed SHA and decisions for §16.
- [ ] Separate Codex review completed clean on that exact HEAD; unresolved findings = 0; all required CI green and branch up to date/mergeable before merge.
- [ ] Accepted spec merged; only then refine #9 acceptance/dependencies and promote it to `ai:ready`. Required future tests above are not evidence of executed implementation tests.

---

## 19. References (AS-IS code)

- `includes/Storage/StorageGatewayInterface.php` — multipart primitives
- `includes/Services/ResumableTransferService.php` — ListParts-authoritative resume, HEAD before complete ownership
- `includes/Services/StaleTransferCleanupService.php` — Abort-only stale incomplete; never deleteObject
- `includes/Domain/TransferSessionStatus.php` — Core transfer statuses
- `includes/Storage/MultipartPlan.php` — thresholds/part bounds
- `includes/Attachment/AttachmentOffloader.php` — local-first offload lifecycle
- `tests/LIFECYCLE-MATRIX.md` — prerequisite before Direct Upload
- `docs/roadmap/2026-09-21-product-and-factory-roadmap.md` — Phase P2

---

**REVIEW CANDIDATE — NOT ACCEPTED**

The owner delegation removes waiting solely for another human reply, not the acceptance or technical gates. Do not start #9 before accepted-spec merge and readiness refinement. Do not change pricing/licensing/release policy under this issue.
