# WordPress.org Submission — KAZCODE Universal Storage (Free)

Only the Free core plugin goes to WordPress.org.

`kazcode-universal-storage-pro` is **never** submitted to WordPress.org, never bundled in the Free ZIP, and never committed to the WordPress.org plugin SVN. Pro is distributed separately.

## 1. Plugin identity

| Field | Value |
|---|---|
| Plugin Name | KAZCODE Universal Storage |
| Proposed slug | `kazcode-universal-storage` |
| Version | `1.0.0` |
| Requires at least | `6.7` |
| Tested up to | `7.1` |
| Requires PHP | `8.3` |
| Stable tag | `1.0.0` |
| License | GPLv2 or later |
| License URI | `https://www.gnu.org/licenses/gpl-2.0.html` |
| Text Domain | `kazcode-universal-storage` |
| Author | KAZCODE |
| Author URI | `https://kazcode.net/` |
| Contributors | `kazmij` |

`Contributors` must remain the exact WordPress.org username and is case-sensitive.

## 2. Plugin URI before approval

The cleanest first-submission setup is:

- keep `Author URI: https://kazcode.net/`;
- **omit `Plugin URI` before approval** unless a dedicated product page such as `https://kazcode.net/universal-storage/` is live;
- after WordPress.org approval, `Plugin URI` may point to the accepted WordPress.org listing or to a genuine dedicated product page.

Do not intentionally ship a dead pre-approval WordPress.org URL in the plugin header.

### Pre-submission code action — DONE

The `kazcode-universal-storage.php` header previously had `Plugin URI: https://wordpress.org/plugins/kazcode-universal-storage/`, which is exactly the dead pre-approval URL described above. Removed, then re-added once `https://kazcode.net/universal-storage/` went live: the header now has `Plugin URI: https://kazcode.net/universal-storage/`, a real, live dedicated product page — not the pre-approval WordPress.org URL this section warns against. `Author URI: https://kazcode.net/` remains unchanged.

## 3. Short description

Use this version (under the WordPress.org 150-character limit):

> Offload WordPress media to Amazon S3, Cloudflare R2, and S3-compatible storage while keeping the native Media Library experience.

Use the same line at the top of `readme.txt`.

## 4. Main description positioning

Recommended first paragraph:

> KAZCODE Universal Storage moves your WordPress Media Library files — originals and generated image sizes — to Amazon S3, Cloudflare R2, or S3-compatible object storage while WordPress remains the source of truth for attachment posts and metadata.

Key Free features to state clearly:

- automatic offload of new uploads and generated sizes;
- object-level inventory and partial-failure handling;
- retry of failed/missing objects;
- one Storage Profile;
- local → remote media migration;
- verification and restore;
- native Media Library integration;
- WP-CLI and REST tooling;
- no upload cap and no time-limited trial.

## 5. External services disclosure

KAZCODE Universal Storage Free does **not** send media, credentials, telemetry, analytics, or site data to any KAZCODE-owned server.

The plugin connects only to storage infrastructure explicitly configured by the site administrator. The administrator chooses the provider/endpoint and supplies the credentials.

The connection is used when the user:

- tests a storage connection;
- offloads media;
- verifies remote media;
- restores media;
- migrates/adopts media;
- deletes a remote object when the corresponding configured option/action requires it.

### Amazon S3

Purpose: S3 object storage selected and configured by the administrator.

- Service: `https://aws.amazon.com/s3/`
- Customer Agreement: `https://aws.amazon.com/agreement/`
- Privacy: `https://aws.amazon.com/privacy/`

### Cloudflare R2

Purpose: S3-compatible object storage selected and configured by the administrator.

- Service/docs: `https://developers.cloudflare.com/r2/`
- Cloudflare legal terms: `https://www.cloudflare.com/legal/terms/`
- Privacy: `https://www.cloudflare.com/privacypolicy/`

Cloudflare account customers may be governed by the applicable Self-Serve Subscription Agreement, Enterprise agreement, and service-specific terms linked from Cloudflare's legal pages.

### DigitalOcean Spaces

Purpose: S3-compatible object storage selected and configured by the administrator.

- Service: `https://www.digitalocean.com/products/spaces`
- Terms of Service: `https://www.digitalocean.com/legal/terms-of-service-agreement`
- Privacy: `https://www.digitalocean.com/legal/privacy-policy`

### Wasabi Object Storage

Purpose: S3-compatible object storage selected and configured by the administrator.

- Service / product terms: `https://wasabi.com/product-terms`
- Customer Agreement / Legal: `https://wasabi.com/legal`
- Privacy: `https://wasabi.com/legal/privacy-policy`

### Backblaze B2 Cloud Storage

Purpose: S3-compatible object storage selected and configured by the administrator.

- Service: `https://www.backblaze.com/cloud-storage`
- Terms of Service: `https://www.backblaze.com/company/policy/terms-of-service`
- Privacy: `https://www.backblaze.com/company/policy/privacy`

### MinIO

Purpose: self-hosted or third-party-operated S3-compatible endpoint configured by the administrator.

The plugin talks directly to the configured MinIO endpoint. Applicable terms/privacy depend on whoever operates that endpoint.

MinIO project information:
- `https://min.io/`

### Generic S3-compatible endpoints

The administrator may configure another S3-compatible endpoint.

The plugin connects directly to that administrator-supplied endpoint. Terms and privacy policies depend on the selected provider/operator, not on KAZCODE.

## 6. Suggested `readme.txt` external-services wording

Add a dedicated section similar to:

```text
== External services ==

KAZCODE Universal Storage connects only to object-storage services explicitly configured by the site administrator. It does not send media, credentials, telemetry, or site data to any KAZCODE-owned service.

The plugin can connect to Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, MinIO, or another administrator-supplied S3-compatible endpoint. Connections occur only when the administrator configures/tests storage or uses offload, verify, restore, migrate/adopt, or remote-delete functionality.

Provider service, terms, and privacy links are documented in this plugin's WordPress.org submission documentation and should remain current.
```

For the final WordPress.org listing, it is preferable to include the provider service/terms/privacy links directly in `readme.txt` as well, rather than relying only on an internal submission document.

## 7. Free / Pro boundary

Free is a complete, non-trial plugin.

Free includes:

- automatic offload;
- generated image sizes;
- one Storage Profile;
- migrate existing media to remote storage;
- verify;
- retry;
- restore;
- native Media Library integration;
- basic health/recovery functionality;
- WP-CLI / REST features intended for Free.

No time limits, media-count limits, or storage quotas are imposed by the plugin.

Pro is a **separate plugin** and adds premium implementation such as:

- multiple Storage Profiles;
- independent per-profile credentials;
- cross-provider/cross-bucket migration;
- orphan scan;
- advanced health/reconcile tools;
- multisite network defaults.

Do not ship premium implementation inside the WordPress.org Free ZIP merely hidden behind a commercial boolean gate.

## 8. Privacy and data handling

- Secret access keys are encrypted at rest.
- Secrets must never be logged.
- Saved secrets must not be returned in cleartext by REST or admin HTML.
- Media files go directly from WordPress to the administrator-configured storage destination.
- No KAZCODE relay/proxy is used.
- Uninstall must never delete the configured bucket.
- Existing remote media must never be deleted merely because Free/Pro status changes.

## 9. Support and company links

Before WordPress.org approval:

- Support email: `kazmij@gmail.com`
- Company / Author URI: `https://kazcode.net/`
- GitHub project/release source: use the real repository where appropriate.

After approval:

- the WordPress.org support forum should become the primary public support channel;
- the email may remain as a fallback/direct contact channel.

Keep KAZCODE links tasteful and admin-only where applicable. No frontend promotional output.

## 10. Screenshots

Before publishing screenshots in WordPress.org SVN, add this section to `readme.txt`:

```text
== Screenshots ==

1. Dashboard with storage status, media statistics, and health overview.
2. Configure Amazon S3, Cloudflare R2, or another S3-compatible storage endpoint.
3. Native WordPress Media Library with remote-storage status and actions.
4. Migrate existing WordPress media to object storage in resumable batches.
5. Verify storage health and repair missing or failed media objects.
6. Guided settings and connection testing before enabling offload.
```

Expected files:

```text
screenshot-1.png
screenshot-2.png
screenshot-3.png
screenshot-4.png
screenshot-5.png
screenshot-6.png
```

Use only real screenshots from a sanitized test installation.

## 11. WordPress.org display assets

Preferred launch set:

- `icon-128x128.png`
- `icon-256x256.png`
- optional `icon.svg`
- `banner-772x250.png`
- `banner-1544x500.png`
- real screenshots 1–6

File-size targets / WordPress.org limits:

- icon: up to 1 MB;
- banner: up to 4 MB;
- screenshot: up to 10 MB each.

See `docs/WORDPRESS-ORG-ASSET-BRIEF.md`.

## 12. Pre-submission checklist

### Code / package

- [x] Final Free ZIP is `kazcode-universal-storage-1.0.0.zip`.
- [x] Pro plugin is not inside the Free ZIP.
- [x] `Version: 1.0.0`.
- [x] `Stable tag: 1.0.0`.
- [x] `Requires at least: 6.7`.
- [x] `Tested up to: 7.1`.
- [x] `Requires PHP: 8.3`.
- [x] `Text Domain: kazcode-universal-storage`.
- [x] `Contributors: kazmij`.
- [x] Short description is ≤150 characters.
- [x] External-services disclosure is present and accurate.
- [x] No telemetry / phone-home to KAZCODE in Free.
- [x] No secrets or real production credentials in ZIP.
- [x] `Plugin URI` is removed before approval unless a real dedicated product page exists.
- [x] `Author URI` remains `https://kazcode.net/`.

### Assets

- [x] Icon 128×128. (`kazcode-universal-storage-wporg-assets/icon-128x128.png`)
- [x] Icon 256×256. (`kazcode-universal-storage-wporg-assets/icon-256x256.png`)
- [x] Banner 772×250. (`kazcode-universal-storage-wporg-assets/banner-772x250.png`)
- [x] Banner 1544×500. (`kazcode-universal-storage-wporg-assets/banner-1544x500.png`)
- [x] Screenshot captions added to `readme.txt` (the `== Screenshots ==` section text is in place, matching the real files below).
- [x] Real sanitized screenshots created (`kazcode-universal-storage-wporg-assets/screenshots/screenshot-1..6.jpg`, captured live from the `apps/` QA rig).

### Review readiness

- [ ] Free/Pro boundary rechecked against the actual built Free ZIP.
- [ ] External service links checked for current validity.
- [ ] Full CI green for the exact submitted commit.
- [ ] Final submitted ZIP install-tested on clean WordPress.
- [ ] SHA256 recorded for the exact ZIP being submitted.

## 13. Submission status

**All doc/code fixes below are DONE. Remaining blockers are the visual assets only.**

1. [x] short description replaced with the ≤150-character version (129 chars);
2. [x] formal external-services section added to `readme.txt`, including per-provider links;
3. [x] `== Screenshots ==` section added to `readme.txt` with captions, and the six referenced image files now exist (see §11/§12 Assets checklist) — the section is fully submission-ready;
4. [x] the pre-approval WordPress.org `Plugin URI` removed from the plugin header, then re-added pointing at the now-live `https://kazcode.net/universal-storage/` product page — compliant with the exception in §2/§12 (a dead pre-approval WP.org URL is what's disallowed, not any `Plugin URI` at all);
5. [x] `Contributors: kazmij` confirmed present in the actual `readme.txt`.

Icon, banner, and the six real UI screenshots are all done (`kazcode-universal-storage-wporg-assets/`,
reviewed — see `docs/WORDPRESS-ORG-ASSET-BRIEF.md`). Nothing in code or docs blocks
submission on the visual-assets front anymore. Only the Free ZIP is submitted to WordPress.org.
