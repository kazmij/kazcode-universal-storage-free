# KAZCODE Universal Storage 1.0.0

First public release.

## Highlights

- Amazon S3, Cloudflare R2, and S3-compatible storage (DigitalOcean Spaces, Wasabi, Backblaze B2, MinIO, or any custom S3-compatible endpoint)
- Native WordPress Media Library integration — status column, row/bulk actions, no change to the Grid/List/modal/Featured Image/Gutenberg experience
- Object-level media inventory, including every generated image size
- Safe handling of partial uploads — a failed size never gets falsely marked offloaded
- Retry of failed or missing media objects
- Restore remote media back to WordPress on demand
- Storage health checks and verification, with an AWS setup assistant (checklist + a ready-to-use least-privilege IAM policy)
- Storage Profiles — connection, credentials, and delivery URL configuration
- PHP-Scoper–isolated AWS SDK, so it won't collide with other plugins bundling their own copy

## Pro

KAZCODE Universal Storage Pro is a separate commercial add-on.

[View Pro features and pricing](https://kazcode.net/universal-storage/#pricing)

It adds:

- Multiple Storage Profiles, each with independent credentials
- Cross-provider / cross-bucket media migration, including Amazon S3 → Cloudflare R2
- Verify-before-switch migration safety — delivery only changes after the destination is confirmed
- Orphan scan (dry-run — reports, never deletes)
- Advanced storage health and reconcile tools
- Multisite network defaults

Deactivating Pro never deletes data or breaks media that's already being served — existing profiles, credentials, and delivery keep working; only new premium operations become unavailable until reactivated.

## Requirements

- WordPress 6.7+
- PHP 8.3+

## Testing

Verified against:

- WordPress 7.1
- PHP 8.3 / 8.4 / 8.5
- Amazon S3 (real account)
- Cloudflare R2 (real account), including a live Amazon S3 → Cloudflare R2 migration
- MinIO

## Downloads

```
kazcode-universal-storage-1.0.0.zip
sha256: 938443ebfd2e05de763a82cdc26fa2374bbf89577dbf0e93807a96d1cd18a041
```

`build/build-release.sh` embeds file timestamps in the ZIP, so re-running the build from
identical source still produces a different hash each time (content is unchanged; only the
archive bytes differ). Publish the checksums of the exact ZIP files being distributed, not
a freshly rebuilt pair.

## Links

Product: https://kazcode.net/universal-storage/

Documentation: https://kazcode.net/universal-storage/docs/

Pro pricing: https://kazcode.net/universal-storage/#pricing

Built by [KAZCODE](https://kazcode.net/). Support: [kazmij@gmail.com](mailto:kazmij@gmail.com).
