# KAZCODE Universal Storage

Offload WordPress Media Library binaries to Amazon S3 (or any S3-compatible storage — Cloudflare R2, DigitalOcean Spaces, MinIO, Wasabi, Backblaze B2) while keeping WordPress as the source of truth and the native Media Library UX unchanged.

WordPress keeps attachment posts, metadata, titles, alt text, and `_wp_attached_file` (always a **relative path**, never a URL). Remote storage holds only the binary files — originals and every generated image size. Public URLs are built at render time from your current settings, not stored, so switching a CDN hostname or storage profile never requires touching the database.

**Full documentation:** [kazcode.net/universal-storage/docs/](https://kazcode.net/universal-storage/docs/) — this README covers the same ground for people already reading the repo.

This repository is the public development source for the **Free** KAZCODE Universal
Storage WordPress plugin — [product page](https://kazcode.net/universal-storage/). The
commercial **Pro** add-on is a separate plugin (installed independently, requires Free
active) and is **not** contained in this repository. WordPress.org listing status:
submitted and pending review — do not assume "Approved"/"Official" until that's
actually true.

## Table of contents

1. [Why use it](#why-use-it)
2. [Free vs Pro](#free-vs-pro)
3. [Supported storage providers](#supported-storage-providers)
4. [Requirements](#requirements)
5. [Installation](#installation)
6. [Quick start](#quick-start)
7. [Dashboard](#dashboard)
8. [Storage](#storage)
9. [Media](#media)
10. [Migration](#migration)
11. [Health](#health)
12. [Local storage policies](#local-storage-policies)
13. [Restore / recovery](#restore--recovery)
14. [Private media](#private-media)
15. [WP-CLI](#wp-cli)
16. [Amazon S3 setup](#amazon-s3-setup)
17. [Cloudflare R2 setup](#cloudflare-r2-setup)
18. [Other S3-compatible providers](#other-s3-compatible-providers)
19. [Security](#security)
20. [Troubleshooting](#troubleshooting)
21. [Development](#development)
22. [License](#license)

---

## Why use it

- **Offload** — new uploads (and every generated image size) move to remote storage automatically after WordPress finishes processing them.
- **Migrate** — move existing Media Library files to remote storage in resumable batches, with dry-run and per-attachment modes.
- **Restore** — download files back to local disk on demand; nothing is one-way.
- **Repair** — re-upload or re-verify individual objects that failed or drifted, without touching everything else.
- **Native UX** — Media Library Grid/List, the media modal, Featured Image, Gutenberg image blocks, and Edit Image all keep working exactly as before; theme and plugin static assets are never touched, only Media Library attachments.

## Free vs Pro

| Feature | Free | Pro |
|---|---:|---:|
| AWS S3 / R2 / Spaces / MinIO / Wasabi / B2 / generic S3-compatible | ✓ | ✓ |
| Automatic offload, migrate, verify, retry, restore | ✓ | ✓ |
| Single storage profile | ✓ | ✓ |
| Native Media Library integration (S3 column, row/bulk actions) | ✓ | ✓ |
| Health checks, repair, WP-CLI, REST API | ✓ | ✓ |
| Background/resumable migration (WP-Cron) | ✓ | ✓ |
| Private media (signed, time-limited URLs) | ✓ | ✓ |
| Audit log (settings saves, background job history) | ✓ | ✓ |
| Multiple storage profiles | — | ✓ |
| Cross-provider / cross-bucket migration (with verify-before-switch) | — | ✓ |
| Per-profile independent credentials | — | ✓ |
| Orphan scan (dry-run, never deletes) | — | ✓ |
| Advanced health / reconcile | — | ✓ |
| Multisite network defaults | — | ✓ |

Free is a complete, standalone product, not a trial — no upload limits, no time limits. Pro is a separate add-on plugin that requires Free to be active; deactivating Pro never deletes data or breaks media already being served.

**KAZCODE Universal Storage Pro:**
Feature details and plans: https://kazcode.net/universal-storage/#pricing

## Supported storage providers

Amazon S3, Cloudflare R2, DigitalOcean Spaces, MinIO, Wasabi, Backblaze B2, and any other S3-compatible endpoint.

## Requirements

- WordPress 6.7+
- PHP 8.3+
- Pro (if installed) requires Free active, version 1.0.0 or newer

## Installation

1. Upload/activate the plugin under **Plugins** (from WordPress.org, or by uploading the ZIP)
2. Open **Universal Storage → Storage**, pick a provider, enter credentials, and test the connection
3. Open **Universal Storage → Settings**, enable offload, and (once you've spot-checked a test upload) enable **Serve from S3**
4. Migrate existing media from **Universal Storage → Migration**

## Quick start

1. Choose a provider and enter its credentials
2. Test the connection
3. Enable offload
4. Upload a test image, confirm it appears in your bucket and the Media Library thumbnail still loads
5. Migrate the rest of your library

## Dashboard

**Universal Storage** top-level menu — at-a-glance totals (offloaded / pending / failed / verified) and quick links into the other screens.

## Storage

**Universal Storage → Storage** — configure your storage profile: provider, bucket, region, endpoint, credentials, delivery URL (CDN or direct). Free manages one profile; Pro adds the ability to create additional profiles, each with its own independent credentials, and a guided wizard for migrating between them.

## Media

**Universal Storage → Media** — the Media Library gets a status column and per-row/bulk offload, restore, and verify actions. A dedicated failed-items panel lets you filter, ignore, and export failures as CSV.

## Migration

**Universal Storage → Migration** — batch-migrate existing attachments with dry-run, retry-failed, and verify modes. Large libraries should prefer WP-CLI (below) for unattended runs.

## Health

**Universal Storage → Health** — health checks, object-inventory stats, and an AWS setup assistant (checklist + a least-privilege IAM policy for your bucket/prefix). Pro adds orphan scan (a dry-run comparison of what's actually in your bucket against what the plugin's inventory expects — it only ever reports, never deletes) and advanced reconcile.

## Local storage policies

Configurable per-site: keep local files indefinitely, delete after a verified upload, or remote-only. Local file deletion always requires a successful remote HEAD verification first — there is no way to disable that check.

## Restore / recovery

`wp universal-storage restore` (or the Migration screen) downloads files back from remote storage into `wp-content/uploads`. Restore, verify, and basic repair are Free capabilities and are never gated — recovering your own media never requires a paid upgrade.

## Private media

Private media (signed, time-limited GET URLs instead of public bucket/CDN URLs) is a Free capability — turn it on any time under Universal Storage → Settings, no Pro add-on required.

## WP-CLI

```bash
wp universal-storage status                 # totals: offloaded/pending/failed/verified
wp universal-storage test                   # connection test (client, bucket, upload, HEAD, delete)
wp universal-storage migrate --dry-run
wp universal-storage migrate --batch-size=100
wp universal-storage migrate --attachment-id=123 --verbose
wp universal-storage migrate --delete-local
wp universal-storage verify
wp universal-storage retry_failed
wp universal-storage restore
wp universal-storage health scan --full
wp universal-storage health orphan          # Pro
wp universal-storage repair --dry-run
wp universal-storage storage_migrate --source-profile=1 --dest-profile=2 --dry-run   # Pro
wp universal-storage adopt                  # inventory pre-existing offloaded media (HEAD-only, never re-uploads)
wp universal-storage queue status
```

Docker Compose example:

```bash
docker compose exec -T php wp universal-storage test
docker compose exec -T php wp universal-storage migrate --dry-run --batch-size=20
```

## Amazon S3 setup

1. **Bucket** — S3 console → Buckets → create or choose one; note the name and region. Object Ownership is usually "Bucket owner enforced" (ACLs disabled) — this plugin never sets object ACLs.
2. **IAM user** — IAM console → Users → Create user → programmatic access only (no console password needed).
3. **Policy** — attach an inline policy scoped to your bucket:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       { "Sid": "ListBucket", "Effect": "Allow",
         "Action": ["s3:ListBucket", "s3:GetBucketLocation"],
         "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME" },
       { "Sid": "ObjectReadWrite", "Effect": "Allow",
         "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject",
                     "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts"],
         "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*" }
     ]
   }
   ```

   To scope to a key prefix instead, add a `Condition` with `s3:prefix` on the `ListBucket` statement and change the object-actions `Resource` to `bucket/PREFIX/*` — then set the same prefix in **Universal Storage → Storage**. **Universal Storage → Health** generates this policy pre-filled for your current bucket/prefix.
4. **Access key** — IAM → your user → Security credentials → Create access key ("Application running outside AWS"). The secret is shown once — store it now.
5. **Public access** — the plugin never sets public-read ACLs. For public delivery, use a CDN (CloudFront + Origin Access Control is the standard pattern) or a bucket policy allowing `s3:GetObject`, and set that hostname as the CDN/Base URL in **Universal Storage → Storage**.

## Cloudflare R2 setup

1. Cloudflare dashboard → R2 → create a bucket
2. R2 → Manage API tokens → create a token with **Object Read & Write** scoped to that bucket; copy the Access Key ID and Secret Access Key
3. In **Universal Storage → Storage**: provider preset **Cloudflare R2**, Region `auto`, Endpoint `https://<account-id>.r2.cloudflarestorage.com`, enable **Force Path Style**
4. For public delivery, either enable the bucket's public R2.dev domain or connect a custom domain, and set it as the CDN/Base URL

## Other S3-compatible providers

DigitalOcean Spaces, MinIO, Wasabi, and Backblaze B2 all work via the same **Endpoint** + **Force Path Style** fields as R2 — pick the matching provider preset in **Universal Storage → Storage** for sane defaults, or choose **Generic S3-compatible** and fill in the endpoint yourself. MinIO in particular is what this plugin's own integration tests run against (see `docker-compose.minio.yml` at the repo root).

## Security

- Secret access keys are encrypted at rest (libsodium `sodium_crypto_secretbox`, with an AES-256-GCM fallback), keyed off your WordPress salts (`AUTH_KEY`, `SECURE_AUTH_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`) — never stored in plaintext, never written to logs, never returned by the REST API or admin HTML once saved (the secret field stays blank on later edits; leaving it blank keeps the existing value).
- Remote delete is always an explicit, chunked key list — never a recursive prefix wipe.
- Local file deletion only happens after `realpath` confirms the file is under the uploads directory, and only after a successful remote HEAD verification.
- Uninstalling never touches your bucket, WordPress attachments, or local media files. By default it only clears disposable runtime state (locks, caches, queued job state) and preserves storage profiles/credentials/inventory for recovery on reinstall; an opt-in "Delete Universal Storage data when uninstalling" setting (Settings, off by default) additionally purges that local plugin data — still never remote objects or media.

**Reporting a vulnerability:** email kazmij@gmail.com directly rather than opening a public issue.

## Troubleshooting

**Test connection fails with AccessDenied/403** — the IAM policy is missing, attached to the wrong user, or scoped to the wrong bucket/prefix. Confirm under IAM → Users → your plugin's user → Permissions. Use `wp universal-storage test` for a CLI-side check independent of the admin button.

**Media Library shows broken images after enabling local file deletion** — confirm **Serve from S3** is on, the attachment's status is `offloaded` (Media column), and the CDN/public URL is reachable directly in a browser. Run `wp universal-storage verify --attachment-id=ID --verbose` for detail.

**Wrong region** — the Region field must match the bucket's actual region exactly.

**S3-compatible storage (MinIO, etc.)** — set **Endpoint**, enable **Force Path Style** if the provider requires it, and use that provider's own access keys (not AWS credentials).

## Development

Public development source: **https://github.com/kazmij/kazcode-universal-storage-free**
(this repository's own upstream, if you're reading this from the WordPress.org SVN copy
or a release ZIP). Full build instructions there in [`BUILD.md`](BUILD.md).

```bash
cd wp-content/plugins/kazcode-universal-storage
composer install       # includes PHPUnit + PHP-Scoper (dev)
composer test           # or: ./vendor/bin/phpunit
composer build:release  # produces dist/*.zip (PHP-Scoper isolated; requires PHP 8.3/8.4)
```

No local PHP/Composer? If you're working inside KAZCODE's private combined-monorepo
checkout (which also has a Pro add-on directory alongside this one — not present in the
public source repo above), its repo-root `Makefile` (`make test-all`, `make build`) runs
everything inside the `apps/` docker QA rig instead, documented in that monorepo's own
`docs/DEVELOPMENT.md`. Neither the Makefile nor that doc are part of this public
repository — if you're reading this on GitHub at kazmij/kazcode-universal-storage-free,
use the plain `composer`/`vendor/bin/phpunit` commands above instead.

Manual QA scenarios: [`tests/MANUAL-SCENARIOS.md`](tests/MANUAL-SCENARIOS.md). Locked/expected behaviors with pointers to the guarding test: [`tests/CHARACTERIZATION.md`](tests/CHARACTERIZATION.md).

## License

GPL-2.0-or-later
