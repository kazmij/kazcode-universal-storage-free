# Universal Storage 1.3.0 / 1.3.0 feature matrix

## Free

- **Media storage and delivery** — available. One active storage profile with offload, delivery URL rewriting, restore, verification, repair, and safe local cleanup after remote verification. Evidence: `docs/testing/RELEASE-E2E-MATRIX.md`.
- **Standard WordPress uploads** — available. Free uses the normal WordPress uploader and never exposes browser-to-bucket Direct Upload. Evidence: `docs/testing/DIRECT-UPLOAD-IMAGE-TEST-MATRIX.md`.

## Pro

- **Advanced storage operations** — available. Multiple profiles, profile-scoped credentials, provider migration with destination verification, advanced health/reconcile, orphan quarantine, and multisite defaults. Evidence: `docs/testing/RELEASE-E2E-MATRIX.md`.
- **Direct Upload images** — available. Licensed Pro administrators may opt in after exact-origin CORS acknowledgement to upload directly from the browser to writable Amazon S3 or Cloudflare R2. WordPress verifies the remote original and generated image artifacts before READY. Evidence: `docs/testing/DIRECT-UPLOAD-IMAGE-TEST-MATRIX.md`.
- **Direct Upload non-image files** — available. Licensed Pro administrators may opt in after exact-origin CORS acknowledgement to upload validated browser-to-bucket PDF, ZIP, MP3, MP4, and DOCX files to writable Amazon S3 or Cloudflare R2. WordPress verifies the remote original before finalization. Evidence: `docs/testing/DIRECT-UPLOAD-NONIMAGE-TEST-MATRIX.md`.
- **Direct Upload from the Media Library entry point** — not_verified. The acceptance-preview/UI path is not a release claim for a production Media Library replacement flow; use the normal WordPress uploader unless a future record provides exact release evidence.
