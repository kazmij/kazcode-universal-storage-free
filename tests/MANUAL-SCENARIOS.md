# Manual / integration test scenarios

Run against a WordPress site with the plugin activated and a writable S3 (or MinIO) bucket.

**Phase 0 baseline (2026-08):** behaviors below are the accepted pre-v2 product contract. Automated characterization of destructive/offload/URL/job/crypto paths lives in `tests/CHARACTERIZATION.md` and `tests/Unit/*CharacterizationTest.php`. Optional MinIO: repo-root `docker-compose.minio.yml` (`--profile minio`).

## Scenario A — Upload JPG

1. Enable plugin, configure AWS, enable Serve media from S3, keep local files.
2. Upload a JPG via Media → Add New.
3. Confirm Media Library shows the image.
4. Open Attachment Details: preview, dimensions, Copy URL (S3/CDN).
5. Confirm all sizes exist on S3 (CLI `wp universal-storage verify --attachment-id=ID --verbose`).
6. Confirm frontend `<img src>` / `srcset` use S3/CDN hosts.

## Scenario B — Delete local files

1. Enable **Delete local files after successful upload** (+ verify before delete).
2. Upload a JPG.
3. Confirm local files under `wp-content/uploads/` are gone.
4. Open Media → Library (Grid and List). Image must still display (no broken icon).
5. Attachment Details preview must work.

## Scenario C — Featured Image

1. Edit a post → Set featured image.
2. Media modal lists offloaded images with thumbnails.
3. Selecting an image sets featured image normally.

## Scenario D — Gutenberg

1. Add Image block → Media Library.
2. Pick an S3-offloaded image.
3. Block renders correctly on frontend.

## Scenario E — Regenerate thumbnails (S3-only)

1. Offload with delete-local so original is missing locally.
2. Run a regenerate tool (or `wp media regenerate --yes` for that ID).
3. Plugin downloads original, WP regenerates sizes, plugin re-uploads.
4. Verify S3 has new sizes.

## Scenario F — Edit Image (S3-only)

1. Open Attachment Details → Edit Image for an S3-only attachment.
2. Crop / rotate / scale and save.
3. Preview updates; new files on S3; Media Library shows new preview.

## Scenario G — Delete attachment

1. Enable **Delete remote object when attachment deleted**.
2. Delete Permanently an offloaded attachment.
3. Confirm original + sizes removed from S3 (HEAD fails). No recursive prefix wipe of other objects.

## Scenario H — Clear a failed item and re-offload from scratch

1. Force a failure (e.g. break credentials temporarily) and upload a JPG so it lands in Media → Failed items.
2. Confirm the row appears with its error message.
3. Restore credentials. Select the row, click **Clear selected**, confirm the dialog.
4. Confirm the row disappears from the Failed items list (all filters) and the plugin's own `_s3ms_*` postmeta is gone for that attachment — but the attachment post and local file are untouched.
5. Re-trigger an offload for that attachment (e.g. Media Library bulk action → Offload). Confirm it succeeds cleanly, as if never previously attempted.
6. Check Logs (Pro) for a `failed_items_cleared` entry with the right count.

## Scenario I — Competitor import wizard state transitions

1. Open Universal Storage → Migration and find exactly one **Switch from another plugin** panel. Initial state is Detect: the source selector contains only **Detect sources first**, the profile selector is enabled, **Analyze** and **Commit import** are disabled, pause/resume/cancel are disabled, and all report counters show `0`.
2. Click **Detect sources**. Expected REST call: `GET kazcode-storage/v1/imports/detect`. The source selector is rebuilt from detected source IDs without secret/access-key/token fields; an error response displays only the sanitized service message in the panel status and does not add options from the error JSON.
3. Select a detected source and storage profile, then click **Analyze**. Expected REST call: `POST kazcode-storage/v1/imports/dry-run/start` with `source` and `profile`. The returned run UUID becomes the current run, step state changes to Analyze, pause/cancel are enabled, resume remains disabled, and **Commit import** remains disabled.
4. While the dry-run is `running`, polling `GET kazcode-storage/v1/imports/{uuid}` updates Attachments scanned, Objects checked, Verified / adopted, Confirmed missing, Transient errors, Conflicts, Skipped, and Updated. If that GET returns a non-2xx REST application error, polling stops, the panel displays only the sanitized service message, and the wizard controls are no longer left in a stale running state. If the run becomes `paused`, Pause is disabled and Resume is enabled. If the run becomes `cancelled` or `failed`, Commit stays disabled.
5. When polling returns the same dry-run UUID with `mode=dry_run` and `status=completed`, the state changes to Import, pause/resume/cancel are disabled, and **Commit import** becomes enabled for that same source/profile pair.
6. Click **Commit import**. Expected REST call: `POST kazcode-storage/v1/imports/commit/start` with the selected source/profile. The returned commit UUID becomes the current run, Commit is disabled again, pause/cancel are enabled, and polling updates the same counters until completion.
7. When commit polling returns `status=completed`, the state changes to Report, all run controls are disabled, the final counters remain visible, and multipart work is shown only as resumable transfer progress, never as S3 part-size/concurrency tuning controls.
8. During any running dry-run or commit, Pause/Resume/Cancel call `POST kazcode-storage/v1/imports/{current_uuid}/pause|resume|cancel`; they must not operate without the current run UUID.


## Scenario K — HEIC upload with retained source image

Requires a WordPress host whose image editor can ingest HEIC (Imagick/HEIC support). Skip and record NOT_RUN if the environment cannot decode HEIC.

1. Enable plugin; configure storage; keep local files initially.
2. Upload a HEIC via Media → Add New.
3. Confirm WordPress created a generated JPEG used as `_wp_attached_file` / Media Library preview.
4. Confirm attachment metadata contains `original_image` pointing at the retained HEIC (or equivalent retained source filename).
5. Confirm both variants are in inventory / expected keys (e.g. `wp eval` / REST object list / Integrity verify once shipped showing both the JPEG relative path and the HEIC/`original_image` sibling). Do **not** rely solely on `wp universal-storage verify --verbose` to print successful paths — current CLI only prints overall status and non-empty `details`, which are empty on successful HEADs.
6. Enable delete-local after verify; re-offload or upload another HEIC; confirm both variants were uploaded before local cleanup.
7. Frontend/Media Library must still render the JPEG derivative; retained HEIC must remain addressable via inventory.

## Scenario J — 1.1.0 release qualification

Run these checks against the built ZIP, not a live source mount. Configure an S3-compatible
profile (MinIO is suitable) and record the attachment IDs, object keys, and transfer/import
run UUIDs used for the run.

1. Install the Free ZIP on a clean site, activate it, configure an S3/R2-style profile, and confirm the connection test succeeds. Install/activate Pro only for checks 8-9.
2. Upload a small image. Confirm the expected objects are present and its Media Library URL resolves through the configured delivery host.
3. Upload a file over a test-overridden multipart threshold, interrupt after at least one part, then resume. Confirm the same transfer session reconciles provider parts, does not upload completed parts again, and completes only after a destination HEAD with the expected size.
4. Enable delete-local-after-upload for a test attachment. Confirm the local file remains if destination verification fails or is transient, and is removed only after verified destination success.
5. With a WP Offload Media fixture/site, run Detect then a dry-run. Confirm the exact source keys are checked, no KAZCODE object inventory is written by the dry-run, and no source credentials appear in the response, DOM, logs, or report. Commit only after a completed matching dry-run and confirm verified objects are adopted without re-upload.
6. Repeat step 5 with an Advanced Media Offloader fixture/site. Confirm malformed source data is skipped/reported rather than guessed and the competitor's data is unchanged.
7. Repeat with a Media Cloud fixture that retains `ilab_s3_info` / metadata `s3.key` on an S3-compatible driver. Confirm GCS-native or keyless Imgix-only attachments are skipped with a clear unsupported message, not guessed.
7. Force a transient/auth/network HEAD failure during either import. Confirm it is reported as transient and is not persisted as `missing` or used to mark the attachment offloaded.
8. In Pro, interrupt a cross-provider migration after work begins. Confirm the source inventory row remains `present` and its delivery URL remains active.
9. Complete a Pro cross-provider migration. Confirm the destination is HEAD-verified and committed before the source becomes `stale`, its URL changes, or source deletion is queued.
10. Uninstall with preserve selected, then with the opt-in purge setting in a disposable site. Preserve must retain import/transfer recovery tables; purge must remove them. Neither mode may contact remote storage or delete media objects.

## CLI smoke

```bash
wp universal-storage status
wp universal-storage test
wp universal-storage migrate --dry-run --batch-size=5 --verbose
wp universal-storage migrate --batch-size=5
wp universal-storage verify --batch-size=5 --verbose
```
