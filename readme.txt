=== KAZCODE Universal Storage ===
Contributors: kazmij
Tags: s3, cloudflare r2, media offload, amazon s3, object storage
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable WordPress media offload for S3, Cloudflare R2 and S3-compatible storage, with safe restore and resumable transfers.

== Description ==

KAZCODE Universal Storage offloads your WordPress Media Library to Amazon S3, Cloudflare R2, or another S3-compatible provider without replacing the native Media Library.

Upload media normally. KAZCODE can move originals and generated sizes to object storage, serve them from your bucket/CDN, restore them, and resume supported interrupted transfers.

Reliability is built into the workflow: remote files are verified before destructive cleanup, temporary provider errors are not treated as missing files, and interrupted operations remain recoverable.

= What you can do with Free =

* Offload new uploads and existing Media Library files.
* Serve media from your bucket, endpoint, or CDN URL.
* Restore offloaded media to local WordPress storage.
* Use resumable multipart transfers for large files.
* Verify remote files before local cleanup.
* Review health, logs, and media status in wp-admin.
* Import/adopt supported WP Offload Media and Advanced Media Offloader records.
* Use supported admin, WP-CLI, and REST workflows.
* Configure one primary storage profile.

= Supported storage =

Free supports Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2 S3 API, MinIO, and custom S3-compatible endpoints. [Read the storage provider docs](https://kazcode.net/universal-storage/docs/storage-providers/)

= Import from another offload plugin =

Already using WP Offload Media or Advanced Media Offloader? KAZCODE can read supported offload data without copying credentials, deleting competitor records, or changing bucket files. Verify imported media before disabling the old plugin.

= Moving between storage providers =

Pro is a separate add-on for multiple Storage Profiles and provider-to-provider migration, such as Amazon S3 to Cloudflare R2. Pro keeps the source authoritative until the destination is transferred, verified, and cut over. Optional source deletion is available after verification.

[View Pro features and pricing](https://kazcode.net/universal-storage/#pricing)

Safety and integrity features in Free remain part of the core plugin; they are not paywalled.

= Documentation and support =

[Documentation](https://kazcode.net/universal-storage/docs/) | [WordPress.org support](https://wordpress.org/support/plugin/kazcode-universal-storage/) | [KAZCODE support](https://kazcode.net/universal-storage/support/)

== Installation ==

= Install from WordPress =

1. In WordPress, go to **Plugins > Add New**.
2. Search for **KAZCODE Universal Storage**.
3. Click **Install Now**, then **Activate**.
4. Open **Universal Storage** and follow setup, or open **Universal Storage > Storage**.
5. Choose your provider, enter bucket/endpoint/credentials, and run **Test Connection**.
6. Enable automatic offload when you are ready.

New uploads can now be offloaded automatically.

= Existing Media Library =

For an existing site, configure and test storage first, then process attachments in batches. Free moves local media into your first storage profile; Pro moves already-offloaded media between profiles/providers. See [media offload](https://kazcode.net/universal-storage/docs/guides/media-offload/) and [existing media](https://kazcode.net/universal-storage/docs/guides/migrate-existing-media/).

= Already using another offload plugin? =

Use the WP Offload Media or Advanced Media Offloader importer. It reads supported records, builds KAZCODE inventory, and leaves source data and credentials untouched. Verify before disabling the old plugin: [WP Offload Media](https://kazcode.net/universal-storage/docs/guides/import-wp-offload-media/) or [Advanced Media Offloader](https://kazcode.net/universal-storage/docs/guides/import-advanced-media-offloader/).

= Moving from one storage provider to another =

Amazon S3 to Cloudflare R2 and provider-to-provider migration are Pro workflows: configure source and destination, transfer, verify, cut over, and optionally delete the old source. See [S3 to R2](https://kazcode.net/universal-storage/docs/migration/s3-to-r2/) and [provider migration](https://kazcode.net/universal-storage/docs/migration/provider-to-provider/).

= Before production cleanup =

Before bulk local cleanup, test the connection, verify several media URLs, and use health/verification tools.

== Frequently Asked Questions ==

= Does this replace the WordPress Media Library? =

No. WordPress keeps attachment records, titles, alt text, and metadata. KAZCODE stores files in your object storage.

= What is included in Free? =

Free includes one storage profile, S3-compatible offload, existing media processing, URL delivery, restore, resumable transfers, verification before cleanup, diagnostics, and import/adoption tools.

= What does Pro add? =

Pro adds multiple Storage Profiles, provider migration, verified cutover, optional source deletion, advanced health/orphan tools, and network/agency functions. [View Pro features and pricing](https://kazcode.net/universal-storage/#pricing)

= Can I migrate from Amazon S3 to Cloudflare R2? =

Yes, with Pro. Destination storage is transferred and verified before delivery cuts over. [Read the S3 to R2 guide](https://kazcode.net/universal-storage/docs/migration/s3-to-r2/)

= What happens if my storage provider is temporarily unavailable? =

Temporary errors, timeouts, authentication problems, and uncertain responses are unknown, not proof that a file is missing. Cleanup requires verification.

= Can I migrate from WP Offload Media? =

Yes. It supports legacy records and the newer 3.4+ per-file model where supported. It is read-only toward the source plugin. [Read the guide](https://kazcode.net/universal-storage/docs/guides/import-wp-offload-media/)

= Does uninstalling delete files from my bucket? =

No. Uninstalling does not delete remote objects, attachments, or local media files. The optional local data purge setting removes only KAZCODE's local plugin data.

== External services ==

KAZCODE Universal Storage connects only to object-storage services configured by the site administrator. It does not send media, credentials, telemetry, or site data to any KAZCODE-owned service.

The plugin can connect to Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, MinIO, or another administrator-supplied S3-compatible endpoint. Connections occur only when the administrator configures/tests storage, or uses offload, verify, restore, migrate/adopt, or remote-delete functionality.

Amazon S3 - https://aws.amazon.com/s3/
Agreement: https://aws.amazon.com/agreement/ - Privacy: https://aws.amazon.com/privacy/

Cloudflare R2 - https://developers.cloudflare.com/r2/
Terms: https://www.cloudflare.com/legal/terms/ - Privacy: https://www.cloudflare.com/privacypolicy/

DigitalOcean Spaces - https://www.digitalocean.com/products/spaces
Terms: https://www.digitalocean.com/legal/terms-of-service-agreement - Privacy: https://www.digitalocean.com/legal/privacy-policy

Wasabi Object Storage - https://wasabi.com/product-terms
Legal: https://wasabi.com/legal - Privacy: https://wasabi.com/legal/privacy-policy

Backblaze B2 - https://www.backblaze.com/cloud-storage
Terms: https://www.backblaze.com/company/policy/terms-of-service - Privacy: https://www.backblaze.com/company/policy/privacy

MinIO and other S3-compatible endpoints - the plugin talks directly to the configured endpoint; terms and privacy depend on whoever operates it, not on KAZCODE. (https://min.io/)

== Screenshots ==

1. See offload status, storage health, and recent activity.
2. Configure Amazon S3, Cloudflare R2, or another S3-compatible endpoint and test before offload.
3. Review offloaded items inside the normal WordPress media workflow.
4. Process existing media in resumable batches with visible progress.
5. Verify storage health, failed operations, and repair options before cleanup.
6. Choose a provider, enter credentials, and confirm storage in guided setup.

== Changelog ==

= 1.1.1 =
* Improved WordPress.org plugin description and onboarding instructions.
* Added clearer installation guidance for new and existing Media Libraries.
* Added clickable documentation, migration, support, and pricing links.
* Clarified Free vs Pro capabilities and supported migration/import workflows.
* Improved FAQ and screenshot descriptions.

= 1.1.0 =
* Added resumable multipart transfers.
* Improved WP Offload Media and Advanced Media Offloader import/adoption.
* Improved restore, verification, retry, profile-aware delivery, and cleanup safety.
* Added Pro multiple profiles, provider migration, verified cutover, and optional source deletion.

= 1.0.2 =
* Improved long object-key identity and database upgrade safety.

== Upgrade Notice ==

= 1.1.1 =
Documentation and listing update only. Storage configuration, media inventory, schema, and runtime behavior are unchanged from 1.1.0.

= 1.1.0 =
Adds resumable transfers, importer improvements, and safer restore/cleanup behavior. Existing settings, profiles, media inventory, and attachment state are preserved.
