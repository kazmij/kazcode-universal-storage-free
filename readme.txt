=== KAZCODE Universal Storage ===
Contributors: kazmij
Tags: s3, cloudflare r2, media offload, amazon s3, object storage
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Amazon S3, Cloudflare R2, and S3-compatible storage while keeping the native Media Library experience.

== Description ==

KAZCODE Universal Storage moves your Media Library's binary files — originals and every generated image size — to S3 or S3-compatible storage, and can serve them from your bucket or a CDN. WordPress stays the source of truth: attachment posts, titles, alt text, and metadata never leave your database.

* Auto-offload new uploads and generated sizes (per-object inventory and partial/retry-safe uploads)
* A storage profile with admin CRUD and profile-scoped delivery URLs
* Dashboard, Media, Storage, Migration, Health, and Logs admin screens
* Migrate, verify, restore, adopt-existing, health scan, and repair tools
* Provider presets (AWS, Cloudflare R2, DigitalOcean Spaces, MinIO, Wasabi, Backblaze B2)
* WP-CLI, REST batch APIs, and resumable background jobs
* Optional Pro add-on for multiple storage profiles, cross-provider migration wizard, orphan scan, and advanced health tools — a separate plugin, not a trial or license key on this one

Theme and plugin static assets are never rewritten — only Media Library attachments.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/kazcode-universal-storage/`
2. Activate through the **Plugins** menu
3. Open **Universal Storage → Settings**, enter bucket credentials, and test the connection
4. Enable offload and migrate existing media from **Universal Storage → Migration**

== Frequently Asked Questions ==

= Does this replace the Media Library? =

No. WordPress keeps attachment records; S3 stores the binary files.

= Which S3 providers are supported? =

AWS S3 and S3-compatible APIs (R2, Spaces, MinIO, Wasabi, B2, generic endpoint).

= What's the difference between Free and Pro? =

Free (this plugin) is a complete, non-trial product: automatic offload, migrate, verify, retry, restore, one storage profile, and full native Media Library integration. The separate Pro add-on unlocks multiple storage profiles with independent credentials, cross-provider/cross-bucket migration with verify-before-switch, orphan scan, advanced health, and multisite network defaults. Deactivating Pro never deletes data or breaks media that's already being served.

= Does this plugin connect to any external service? =

Only the storage endpoint you configure yourself — Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, MinIO, or another S3-compatible service — using the credentials and bucket you provide under Universal Storage → Storage. KAZCODE Universal Storage does not send your media files, credentials, or site data to any KAZCODE-owned server; there is no telemetry, tracking, or phone-home in this plugin.

= Does uninstalling delete my bucket files? =

No. Uninstalling never deletes remote storage objects, never contacts your storage provider, and never deletes WordPress attachments or local media files. By default it only removes disposable runtime state (locks, caches, queued job state) and preserves your storage profiles, credentials, and object inventory so a reinstall can recover them. An explicit "Delete Universal Storage data when uninstalling" setting (off by default) additionally removes that local plugin data — still never remote objects or media — if you want a full local cleanup.

= Where can I find documentation? =

Full documentation — installation, storage-provider setup, migration, restore, health, WP-CLI, troubleshooting, and Pro — is at https://kazcode.net/universal-storage/docs/.

= Where can I get support? =

Use this plugin's WordPress.org support forum first. For direct contact, see https://kazcode.net/universal-storage/support/ or reach KAZCODE at kazmij@gmail.com.

== External Services ==

KAZCODE Universal Storage connects only to object-storage services explicitly selected and configured by the site administrator. It does not send media, credentials, telemetry, or site data to any KAZCODE-owned service, and KAZCODE does not proxy your media through its own servers.

The plugin can connect to Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2 through its S3-compatible API, MinIO, or another administrator-supplied S3-compatible endpoint. An account with the selected storage provider may be required. Connections occur only when the administrator configures or tests storage, or uses storage operations such as upload/offload, download/restore, HEAD/verification, migration/adoption of existing objects, or remote delete when that behavior is configured.

Media files and storage API requests move directly between this WordPress site and the administrator-selected storage provider. Credentials are used only to authenticate to the configured storage provider and are not sent to KAZCODE servers. Data sent to the configured provider can include media/object binary data, object keys and paths, and metadata or protocol fields required by the S3-compatible API.

Amazon S3 — https://aws.amazon.com/s3/
Customer Agreement: https://aws.amazon.com/agreement/ — Privacy: https://aws.amazon.com/privacy/

Cloudflare R2 — https://developers.cloudflare.com/r2/
Terms: https://www.cloudflare.com/legal/terms/ — Privacy: https://www.cloudflare.com/privacypolicy/

DigitalOcean Spaces — https://www.digitalocean.com/products/spaces
Terms: https://www.digitalocean.com/legal/terms-of-service-agreement — Privacy: https://www.digitalocean.com/legal/privacy-policy

Wasabi Object Storage — https://wasabi.com/product-terms
Legal: https://wasabi.com/legal — Privacy: https://wasabi.com/legal/privacy-policy

Backblaze B2 Cloud Storage — https://www.backblaze.com/cloud-storage
Terms: https://www.backblaze.com/company/policy/terms-of-service — Privacy: https://www.backblaze.com/company/policy/privacy

MinIO and other self-hosted or generic S3-compatible endpoints — the plugin talks directly to the administrator-configured endpoint; applicable terms and privacy depend on whoever operates it, not on KAZCODE. (https://min.io/)

== Screenshots ==

1. Dashboard with storage status, media statistics, and health overview.
2. Configure Amazon S3, Cloudflare R2, or another S3-compatible storage endpoint.
3. Native WordPress Media Library with remote-storage status and actions.
4. Migrate existing WordPress media to object storage in resumable batches.
5. Verify storage health and repair missing or failed media objects.
6. Guided settings and connection testing before enabling offload.

== Development ==

The human-readable development source and release build tools for KAZCODE Universal Storage are maintained at:

https://github.com/kazmij/kazcode-universal-storage-free

Release builds use Composer and PHP-Scoper (third-party dependencies are namespace-prefixed to avoid collisions with other plugins). Build instructions are in that repository's BUILD.md.

== Changelog ==

= 1.0.1 =
* Strengthened remote verification before local media cleanup.
* Improved recovery behavior during transient storage-provider failures.
* Hardened shared-media and multi-profile object handling.
* Fixed remote-only media compatibility with WordPress editor and REST workflows.
* Improved restore and partial-failure safety.
* Hardened concurrent attachment operations against stale workers.
* Fixed Amazon S3 preset handling so stale custom endpoint settings cannot be reused for AWS connections.
* Added a global admin control to disable onboarding tutorials across plugin screens.
* Improved Composer and AWS SDK dependency isolation for compatibility with other plugins and Composer-based WordPress installations.

= 1.0.0 =
* First public release
* Object inventory (`s3ms_objects`), storage profiles, derived attachment status, queue jobs
* Admin IA: Dashboard, Media, Storage (profile CRUD), Migration, Health, Logs, Settings
* Partial upload handling, retry for failed/partial attachments, restore clears meta and inventory
* Profile-scoped public URLs, storage migration wizard, health/repair/adopt tools
* Free/Pro feature separation, with the Pro-only implementation physically packaged in the separate Pro add-on rather than shipped (gated) inside this plugin; default plan is Free/Lite unless the Pro add-on is active
* PHP-Scoper release builds (`Kazcode\WpStorage\Vendor` prefix)

== Upgrade Notice ==

= 1.0.1 =
Reliability, data-integrity, and compatibility hotfix. Update recommended for all sites.

= 1.0.0 =
First public release. Requires PHP 8.3+.
