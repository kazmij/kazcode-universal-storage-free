# Runtime Branding Integration

How the approved KAZCODE Universal Storage product identity (dark slate + blue/emerald
palette, geometric K/storage-layer mark — see `docs/KAZCODE-BRAND-EXTRACTION.md` and
`docs/UNIVERSAL-STORAGE-BRAND-SYSTEM.md`) is brought into the plugin's own wp-admin UI, as
distinct from the WordPress.org listing assets (`kazcode-universal-storage-wporg-assets/`,
which are a separate concern — see "WordPress.org assets remain separate" below).

This is a visual-consistency pass only. No storage/migration/provider/licensing logic was
touched — see the "Storage code changed" line in the final acceptance report for this task.

## 1. Audit — where branding already existed before this pass

| Surface | State before this pass |
|---|---|
| `AdminLayout::header()` | Per-page title/tagline/dashicon + action buttons — page-specific, not product identity |
| `AdminLayout::footer()` | Product name + version + Built-by-KAZCODE line + Documentation/Support links, already on all 10 admin screens (added in the earlier "Branding Assets + Plugin Attribution" pass) |
| `AdminLayout::subnav()` | Core page navigation — unrelated to brand identity |
| Settings page sidebar | An "About" card (product description, version, Website/Documentation/Support/Release-notes links) |
| Plugins list screen | `plugin_row_meta` filters on both Free and Pro (Settings/Documentation/Support/KAZCODE, and Documentation/Support/KAZCODE respectively) |
| Plugin headers | `Author: KAZCODE`, `Author URI: https://kazcode.net/` on both plugins |
| Admin CSS | `s3ms-*`-prefixed classes throughout; no dedicated brand-mark/logo treatment anywhere |

What was missing, and what this pass adds: an actual visual mark (logo) anywhere in the
admin UI, and a compact top-of-page identity strip distinct from the existing per-page
header.

## 2. Where the product mark appears

- **Top of every Universal Storage admin screen** (all 7 core pages: Dashboard, Media,
  Storage, Migration, Health, Logs, Settings; all 3 Pro pages: Storage Change Wizard,
  Network Settings, Storage Profiles) — a new, compact `AdminLayout::brand_header()`
  strip: `[logo mark] KAZCODE Universal Storage [PRO if active]` / tagline. Rendered above
  the existing per-page `header()`, which keeps its own page-specific title/tagline/action
  buttons — the two are separate, composable pieces, not a replacement of one by the other.
- Nowhere else. No frontend output, no dashboard widgets outside the plugin's own pages, no
  admin notices/nags carrying the mark.

## 3. Where it will NOT appear

- Frontend/public-facing pages — this plugin has none of its own, and the brief explicitly
  ruled this out regardless.
- WordPress admin notices, upsell banners, or nags of any kind.
- Inside form fields, tables, or anywhere it would compete with actual plugin content.
- The WordPress.org SVN listing assets are **not** duplicated into the plugin ZIP (see §5
  below) — the runtime mark is a separate, smaller, simpler SVG reconstruction of the same
  design, not the marketing banner.

## 4. Free / Pro shared identity

Both plugins render the *exact same* `AdminLayout::brand_header()` call — Pro's two
admin pages (`StorageChangeWizardPage`, `NetworkSettingsPage`) both
`use Kazcode\WpStorage\Admin\AdminLayout;` and call the Core method directly, the same way
they already reused `AdminLayout::footer()`. Pro never constructs an asset URL itself and
never ships its own copy of the logo files — it reuses Core's `KAZUS_PLUGIN_URL`-based URL
via the shared method, so there is exactly one source of truth for the asset URL and
exactly one physical copy of the asset files (`kazcode-universal-storage/assets/brand/`).

**Pro badge:** `AdminLayout::brand_header()` checks `Kazcode\WpStorage\Core\Features::is_pro_active()`
and renders a small `PRO` badge next to the product title only when a Pro module is
actually active — not merely installed-but-inactive. The product mark itself never changes
between Free and Pro; there is no separate Pro visual identity, per the brief's explicit
instruction.

## 5. WordPress.org assets remain separate

Two entirely separate asset sets, never mixed:

```text
kazcode-universal-storage/assets/brand/           runtime, ships inside the plugin ZIP
  logo-mark.svg          — 64×64 viewBox, ~500 bytes, transparent background
  logo-mark-64.png        — PNG fallback/alternate use
  logo-mark-128.png       — PNG fallback/alternate use

kazcode-universal-storage-wporg-assets/            WordPress.org SVN listing assets,
  icon-128x128.png, icon-256x256.png,               NEVER packaged into the plugin ZIP
  banner-772x250.png, banner-1544x500.png
```

The runtime SVG is a fresh, simple vector reconstruction of the same "K built from layered
storage blocks + emerald chevron" concept and palette (`#3b82f6`/`#60a5fa`/`#10b981`) used
in the WordPress.org icon — visually the same mark family, not a pixel-identical trace
(no vector source file existed for the delivered WordPress.org PNGs to trace from; a
from-scratch simple-shapes reconstruction was both necessary and preferable for an admin-UI
asset, since it needed to stay sharp at much smaller sizes than the WordPress.org icon ever
renders at — see §"SVG sharpness" below). The large marketing banner files are not
included in the plugin ZIP at all; there is no runtime need for a 772×250 image inside
wp-admin.

## SVG sharpness

Verified by rendering `logo-mark.svg` at 20px, 24px, 32px, 40px, 64px (via `rsvg-convert`)
and visually confirming the K silhouette and chevron accent both remain legible at every
size, including at the smallest (20px) — the mark uses only flat rectangles and polygons
(no fine detail, no thin strokes that could disappear on downscale).

## Accessibility notes

- The logo `<img>` uses `alt=""` — decorative, since the visible text "KAZCODE Universal
  Storage" sits immediately next to it; a screen reader would otherwise announce the
  product name twice in a row.
- The Pro badge originally used white text on the brand emerald (`#10b981`) background —
  measured contrast ratio ≈2.6:1, well under WCAG AA's 4.5:1 for normal text. Changed to
  dark-navy text (`#0f172a`, the brand system's own "dark" token) on the same emerald
  background — ≈6.6:1, passes AA. The emerald itself as a background color was kept
  unchanged (it's the approved brand accent); only the foreground text color changed.
- The footer/brand-header link color was `#3b82f6` (brand-500) on white — ≈3.7:1,
  under AA. Changed to `#2563eb` (brand-600) — ≈5.2:1, passes. Links also gain a
  `:focus` state (color shift + underline) in addition to the browser's own default focus
  outline, which was never suppressed.
- No information is conveyed by color/icon alone: the Pro badge is literal text ("PRO"),
  not a bare icon or color swatch.

## Responsive behavior

`.kazus-brand-header` is a `flex` row with `flex-wrap: wrap`; at the WordPress admin's own
782px mobile breakpoint the logo shrinks slightly (32px → 28px) and title/tagline text
wraps naturally rather than overflowing horizontally. No fixed pixel widths are used
anywhere in the new CSS.

## Asset URL resolution

`AdminLayout::brand_header()` builds the logo URL as `KAZUS_PLUGIN_URL . 'assets/brand/logo-mark.svg'`
— `KAZUS_PLUGIN_URL` is `plugin_dir_url(__FILE__)` from the main plugin bootstrap, the
same canonical URL constant every other asset reference in this plugin already uses. No
hardcoded `wp-content/plugins/...` path anywhere. This is safe under a renamed
`wp-content` directory, a mapped/mu-plugin install, or a subdirectory multisite — anywhere
`plugin_dir_url()` itself resolves correctly.

## New CSS classes

```text
.kazus-brand-header        flex row: logo + text block
.kazus-brand-mark           the <img> itself
.kazus-brand-header__text   title + tagline wrapper
.kazus-brand-title          product name (+ optional Pro badge)
.kazus-brand-tagline        "Reliable cloud & object storage for WordPress"
.kazus-brand-pro-badge      small PRO pill
.kazus-brand-footer         (renamed from .s3ms-footer this pass, for naming consistency
                              with the new kazus-brand-* family)
.kazus-brand-footer__sep    footer link separator
```

No existing WordPress admin colors, buttons, or unrelated UI were restyled. No dark-mode
admin area was introduced. The brand colors are used only as accents inside these new,
narrowly-scoped classes.

## Real URLs used (no invented domains)

- Website: `https://kazcode.net/`
- Documentation: `https://github.com/kazmij/kazcode-universal-storage#documentation` (the
  actual repository — no separate docs site exists yet)
- Support: `mailto:kazmij@gmail.com` (the one real contact link published on kazcode.net)
- Release notes: `https://github.com/kazmij/kazcode-universal-storage/releases`
