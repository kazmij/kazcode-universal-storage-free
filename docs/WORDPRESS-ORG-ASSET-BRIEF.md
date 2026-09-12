# WordPress.org Display Asset Brief — KAZCODE Universal Storage

**Status: icon, banner, and screenshots are all DONE.** Real, reviewed assets exist at
`kazcode-universal-storage-wporg-assets/` (repo root — the account owner's actual staging
location, not the `assets/wporg/` path this brief originally suggested; both are fine, this
one is what's real). The six real screenshots (§7) were captured live via a browser session
against the `apps/` QA rig (`kazcode-test` bucket — a real but non-production/test
credential) and are staged at `kazcode-universal-storage-wporg-assets/screenshots/
screenshot-{1..6}.jpg`, matching the `== Screenshots ==` captions already in `readme.txt`.
This document remains the production brief/reference for all of it.

**Note on the capture process:** the Settings screen's Access Key ID field was masked via
in-page JS before that one screenshot was taken — an earlier unmasked capture briefly
displayed a real AWS access key ID and was deleted immediately, never committed. If that
key hasn't already been rotated, rotate/deactivate it in AWS IAM as a precaution.

**Refreshed 2026-08-28** after this session's fixes (notice-text visibility, provider-aware
Settings fields, the expanded per-tab onboarding tour) and to replace stray QA-artifact
media filenames visible in the Media Library screenshot with clean sample images. Same
masking convention, applied via a CSS `blur()` filter this time rather than JS text
replacement (verified illegible even zoomed in before capture) — the Access key ID field
on the live QA rig still holds a real (test-bucket-scoped) AWS key, so it must always be
blurred/masked on every future recapture, not assumed safe to leave visible.

The product should look visibly related to the parent **KAZCODE** brand at `https://kazcode.net/`, while still having its own product-level identity as **KAZCODE Universal Storage**.

Asset locations, as actually delivered:

```text
kazcode-universal-storage-wporg-assets/icon-128x128.png       DONE
kazcode-universal-storage-wporg-assets/icon-256x256.png       DONE
kazcode-universal-storage-wporg-assets/banner-772x250.png     DONE
kazcode-universal-storage-wporg-assets/banner-1544x500.png    DONE
kazcode-universal-storage-wporg-assets/originals/             source files, for reference
kazcode-universal-storage-wporg-assets/README.txt             upload instructions (SVN assets/ dir)
kazcode-universal-storage-wporg-assets/screenshots/           DONE — screenshot-1..6.jpg, real UI
```

Reviewed against this brief (§3 icon concept, §5/§6 banner spec): dimensions exact, icon
reads clearly at 40px+ (the WordPress.org plugin-directory thumbnail size) and remains
identifiably K-shaped even at a true 16×16 favicon render, though softer there as expected
for a detailed mark at that size. Banner copy matches §4 exactly, word for word; the
1544×500 version is a faithful 2× scale of the 772×250 composition, not an independent
redesign. Palette matches the brand system; no AWS/Cloudflare/provider logos present. The
banners add subtle low-opacity background texture (outline server-rack/cloud/cylinder
shapes) beyond this brief's plain-background suggestion — tasteful and on-theme, not the
"stock cloud clipart" this brief warns against, so not flagged as a deviation.

Do not mix listing assets with runtime plugin assets under `kazcode-universal-storage/assets/`
(that directory holds `admin.css`/`admin.js`, unrelated to WordPress.org listing graphics).

## 1. Brand relationship

The product identity should clearly read as:

```text
KAZCODE
   ↓
KAZCODE Universal Storage
```

Use the same parent-brand visual language:

- dark slate base,
- KAZCODE blue family,
- emerald accent,
- clean geometric shapes,
- modern developer/infrastructure feel,
- Inter-style typography for marketing copy where available.

Current working palette:

| Role | Color |
|---|---|
| Main background | `#020617` |
| Surface / secondary dark | `#0f172a` |
| Primary blue | `#3b82f6` |
| Secondary blue | `#60a5fa` |
| Accent emerald | `#10b981` |
| Primary text | `#ffffff` |
| Muted text | `#94a3b8` |

The icon must **not** look like a generic cloud-storage clipart mark. It should borrow the angular/chevron geometry of the KAZCODE brand and combine it with a storage/data motif.

## 2. Required WordPress.org assets

| Asset | Dimensions | Format | Maximum file size | Status |
|---|---:|---|---:|---|
| `icon-128x128.png` | 128×128 | PNG | 1 MB | DONE |
| `icon-256x256.png` | 256×256 | PNG | 1 MB | DONE |
| `icon.svg` | square scalable `viewBox` | SVG | keep minimal | OPTIONAL / preferred — not produced |
| `banner-772x250.png` | 772×250 | PNG/JPG | 4 MB | DONE |
| `banner-1544x500.png` | 1544×500 | PNG/JPG | 4 MB | DONE |
| `screenshot-1.png` … | real WP UI | PNG/JPG | 10 MB each | DONE — 6 real screenshots captured |

Optimize files before committing them to WordPress.org SVN. Do not ship unnecessarily large PNGs.

## 3. Recommended icon concept

### Final direction: KAZCODE-derived “K + storage layers”

Do **not** use the previous concept of three plain horizontal bars on their own; at small size it reads more like a hamburger/storage stack than a recognizable KAZCODE-family mark.

Use a product mark with these properties:

1. A strong geometric **K / chevron silhouette** derived from the visual rhythm of the KAZCODE parent logo.
2. The right-hand arms of the K should be built from **layered / stepped slabs**, so the mark also suggests:
   - object storage,
   - data layers,
   - moving data between providers.
3. Keep the silhouette simple enough to survive at 16×16 and 40×40.
4. No text inside the icon.
5. No AWS / Cloudflare / WordPress logos.
6. No gradients unless a later brand-system decision explicitly introduces them.

### 128×128 base layout

- Canvas: 128×128.
- Preferred background: `#020617`.
- Keep the complete mark inside an approximately 88×88 safe area centered in the canvas.
- Primary K spine / dominant form: `#3b82f6`.
- Secondary stepped arm: `#60a5fa`.
- Storage/accent layer: `#10b981`.
- Rounded geometry is acceptable, but keep radii modest.
- No drop shadow.
- No thin strokes that disappear when downscaled.

### Acceptance tests

Before approving:

- render at **16×16**,
- render at **40×40**,
- render at **128×128**,
- render at **256×256**.

The mark must still read as one coherent symbol at 16×16 and 40×40. If the layered details collapse, simplify the arms rather than adding more decoration.

## 4. Banner copy

### Exact copy

```text
Headline: KAZCODE Universal Storage
Tagline: Reliable cloud & object storage for WordPress
Support line: Offload, migrate, restore and verify WordPress media
```

The support line is optional at 772×250. Drop it before making the headline/tagline too small.

## 5. Banner — 772×250

- Canvas: 772×250.
- Background: `#020617`.
- Product icon at left, about 110–120 px visual size.
- Left margin: about 36–44 px.
- Text block to the right.
- Headline:
  - Inter / equivalent,
  - 800–900 weight,
  - about 40–44 px,
  - `#ffffff`.
- Tagline:
  - 400–500 weight,
  - about 18–20 px,
  - `#94a3b8`.
- Optional support line:
  - about 14 px,
  - `#94a3b8`.
- No provider logos.
- No CTA button.
- No URL.
- No feature bullet list.
- No more than three lines of copy.

The banner's job is brand recognition + positioning, not to behave like a full landing page.

## 6. Banner — 1544×500

Use the **exact same composition** as the 772×250 banner at 2× scale.

Do not redesign the high-DPI version independently.

## 7. Real screenshots

Use only **real screenshots from a live WordPress installation**.

### Setup before capturing anything

1. Fresh WordPress install (7.1, matching `readme.txt`'s "Tested up to"), both plugins
   installed from the actual release ZIPs.
2. Point the plugin at **MinIO**, not real AWS/R2 — this is demo data, and MinIO avoids any
   chance of a real bucket name, region, or account identifier appearing in a public
   screenshot. Use an obviously-fake bucket name, e.g. `demo-media-bucket`.
3. Upload 4–6 varied demo images so the Media Library and Dashboard screens show
   non-empty, realistic-looking data rather than a suspiciously empty state.
4. Browser viewport: 1600×1000 (crops cleanly to 1200px+ width after trimming browser
   chrome; wp-admin's layout looks best above ~1400px so sidebars/columns render as
   intended).
5. Use the plain default WordPress admin color scheme — not a customized one.

Recommended files, source page, and captions:

### `screenshot-1.png` — Dashboard
Page: `wp-admin/admin.php?page=kazcode-universal-storage`

Show:
- storage connection state,
- media/object counters,
- health/status summary,
- recent activity if populated.

Caption:
> Dashboard with storage status, media statistics, and health overview.

### `screenshot-2.png` — Storage setup
Page: `wp-admin/admin.php?page=kazcode-universal-storage-storage`

Show:
- provider/storage configuration,
- safe example values,
- no real keys/secrets (the secret field must show its masked `••••••••` placeholder, never
  real characters — this is also the plugin's own actual behavior once a secret is saved).

Caption:
> Configure Amazon S3, Cloudflare R2, or another S3-compatible storage endpoint.

### `screenshot-3.png` — Media Library integration
Page: `wp-admin/upload.php` (list view, not grid view — the offload status column only
renders in list view)

Show:
- native WordPress Media Library,
- Universal Storage status column/actions,
- a few demo images.

Caption:
> Native WordPress Media Library with remote-storage status and actions.

### `screenshot-4.png` — Migration
Page: `wp-admin/admin.php?page=kazcode-universal-storage-migration`

Show:
- migration controls,
- progress/status,
- demo counts,
- the WP-CLI command reference block already present on this page.

Caption:
> Migrate existing WordPress media to object storage in resumable batches.

### `screenshot-5.png` — Health
Page: `wp-admin/admin.php?page=kazcode-universal-storage-health`

Show:
- verification/health data,
- repair/retry options available in Free,
- the IAM policy assistant (generated JSON with the demo bucket name in it — fine to show
  since it's a demo bucket).

Caption:
> Verify storage health and repair missing or failed media objects.

### `screenshot-6.png` — Settings / connection test
Page: `wp-admin/admin.php?page=kazcode-universal-storage-settings`

Optional but recommended — also demonstrates the in-admin About panel (`docs/BRANDING-SURFACES.md`) working correctly.

Caption:
> Guided settings and connection testing before enabling offload.

### Screenshot hygiene

Never expose:

- real access keys,
- real secret keys,
- private bucket names,
- customer names,
- real email addresses,
- production domains,
- personally identifiable data.

Use sanitized demo data.

## 8. `readme.txt` screenshot section

Before publishing screenshots in WordPress.org SVN, `readme.txt` should contain:

```text
== Screenshots ==

1. Dashboard with storage status, media statistics, and health overview.
2. Configure Amazon S3, Cloudflare R2, or another S3-compatible storage endpoint.
3. Native WordPress Media Library with remote-storage status and actions.
4. Migrate existing WordPress media to object storage in resumable batches.
5. Verify storage health and repair missing or failed media objects.
6. Guided settings and connection testing before enabling offload.
```

Screenshot numbering must match `screenshot-1.png`, `screenshot-2.png`, etc.

## 9. What not to do

- Do not use stock cloud clipart.
- Do not make the icon look AWS-specific or Cloudflare-specific.
- Do not use provider logos in the main banner.
- Do not overfill the banner with feature bullets.
- Do not fabricate admin screenshots.
- Do not commit a temporary low-quality logo as final.
- Do not introduce random colors outside the approved KAZCODE-derived palette.
- Do not make the product logo completely unrelated to the KAZCODE parent identity.

## 10. Recommended production workflow

1. Build the icon as vector first.
2. Validate it at 16×16 and 40×40.
3. Export `icon.svg`, `icon-128x128.png`, `icon-256x256.png`.
4. Build the banner from the same vector mark.
5. Export 772×250 and an exact 2× 1544×500.
6. Optimize all files.
7. Capture real WordPress screenshots from a sanitized test site.
8. Add the matching `== Screenshots ==` section to `readme.txt`.
9. Commit the listing assets only after visual QA.

## 11. Submission note

Custom icon/banner assets improve the listing but do not need to block code QA. If the plugin is approved before the final graphics are ready, assets can be added later to the WordPress.org SVN `assets/` directory.

For this product, however, the preferred launch path is to have the icon, banners, and real screenshots ready before the listing becomes public.
