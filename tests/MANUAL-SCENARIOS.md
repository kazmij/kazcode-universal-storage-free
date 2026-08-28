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

## CLI smoke

```bash
wp universal-storage status
wp universal-storage test
wp universal-storage migrate --dry-run --batch-size=5 --verbose
wp universal-storage migrate --batch-size=5
wp universal-storage verify --batch-size=5 --verbose
```
