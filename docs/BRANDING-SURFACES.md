# Branding Surfaces Inventory

Audit of every place the product identity appears in the repository, run for the
"Branding Assets + Plugin Attribution" pass. Table reflects state **after** the fixes made
in this same pass (see the "Needs change?" column for what was found vs. fixed).

| Area | Current state | Needs change? | Notes |
|---|---|---|---|
| Core plugin header (`kazcode-universal-storage.php`) | `Plugin Name: KAZCODE Universal Storage`, `Author: KAZCODE`, `Author URI: https://kazcode.net/` | **FIXED** | Was `Author: KAZCODE Universal Storage Contributors` with no `Author URI` at all — a plugin author field with no URI is a missed, free attribution opportunity and doesn't match WordPress.org convention (most listings show a clickable author name). |
| Pro plugin header (`kazcode-universal-storage-pro.php`) | `Author: KAZCODE`, `Author URI: https://kazcode.net/`, `Plugin URI: https://kazcode.net/` | **FIXED** | Same Author/Author URI fix as core. `Plugin URI` was previously pointing at `wordpress.org/plugins/kazcode-universal-storage/` — the **Free** listing's URL — which is actively misleading for a plugin that will never itself be on WordPress.org; repointed to the company site, which is real and exists. |
| `readme.txt` — `Contributors:` field | `TODO-set-to-actual-wordpress-org-username` | **FIXED (partially)** | Was `s3mediastorage` — a leftover WordPress.org username from before either rebrand. Left as an explicit TODO rather than guessing a real username, since no WordPress.org account has been created yet (per `docs/LAUNCH-READINESS.md`) — this is a genuine **USER ACTION REQUIRED** item, not something to fabricate. |
| `readme.txt` changelog — PHP-Scoper prefix mention | `Kazcode\WpStorage\Vendor` prefix | **FIXED** | Was still `S3MS\Vendor` — a stale reference from before the namespace rename, missed by the earlier rebrand sweeps because it's prose inside a changelog entry, not a code identifier the mechanical rename passes targeted. |
| `assets/admin.css` top comment | `KAZCODE Universal Storage — admin UI (product-ready).` | **FIXED** | Was `KAZCODE S3 Media Storage`. `.css` files weren't covered by the pattern used in earlier rebrand sweeps (which targeted `.php`/`.md`/`.txt`/`.json`), so this survived three prior audit passes. |
| `AdminLayout.php` — shared chrome comment + subnav `aria-label` | "Universal Storage screens" / "Universal Storage sections" | **FIXED** | Both still said "S3 Media" — string literals without the `→` arrow pattern the earlier "S3 Media →" sweep specifically searched for, so they slipped through. |
| `DashboardPage.php` — class docblock | "Universal Storage dashboard" | **FIXED** | Same class of miss as `AdminLayout.php`. |
| `SettingsPage.php` — network-defaults help text | "Network Admin → Universal Storage defaults" | **FIXED** | User-facing help copy inside a settings field, same miss. |
| `NetworkSettingsPage.php` (Pro) — class docblock | "Network Admin → Universal Storage defaults inherited..." | **FIXED** | Same. |
| Admin footer / attribution | New `AdminLayout::footer()`, rendered on all 7 admin screens (Dashboard, Media, Storage, Migration, Health, Logs, Settings) | **ADDED** | Previously nothing — no attribution existed anywhere in the admin UI. Small, single-line: product name + version, "Built by KAZCODE" linking to kazcode.net, plus Docs/Support links. Not a promotional block; matches the existing `s3ms-*` CSS naming and visual language. |
| About / product-info panel | New compact panel on the Settings page | **ADDED** | Previously no dedicated "about this plugin" surface existed anywhere. Placed on Settings (the conventional WordPress home for this content) rather than as a new top-level menu item, to avoid adding IA surface for something genuinely secondary. |
| Plugin row meta links (Plugins list screen) | New `plugin_row_meta` filter, both Free and Pro | **ADDED** | Previously absent — WordPress's own convention (every well-behaved plugin has at least a "View details"/docs link here) wasn't followed at all. |
| `README.md` (root) | Consistent product name, links to `kazcode.net`, GitHub repo URL current | Reviewed, already consistent | No stale references found beyond what earlier passes already fixed (repo rename, admin menu label). |
| `docs/RELEASE-NOTES-1.0.0.md` (renamed from `-2.0.0.md`) | Consistent | Renamed as part of the 2.0.0 → 1.0.0 version-number decision (see `docs/LAUNCH-READINESS.md`) | Customer-facing, content otherwise accurate. |
| `docs/WORDPRESS-ORG-SUBMISSION.md` | Rewritten and expanded by the account owner (numbered sections, per-provider external-service disclosure links, a concrete pre-submission checklist) | Reviewed and cross-checked against the actual codebase | Found and fixed 2 real gaps the rewrite itself flagged: `readme.txt`'s short description was 158 characters (over WordPress.org's 150-char limit) and the plugin header still had a dead pre-approval `Plugin URI`. Both fixed; `readme.txt` also gained `== External services ==` and `== Screenshots ==` sections per the doc's own recommended wording. |
| `docs/LAUNCH-READINESS.md` | Consistent | Reviewed, no changes needed | |
| Frontend output (public-facing pages) | No plugin branding appears on the frontend at all | Correct, no change | Confirmed by design — this plugin only affects Media Library URLs; it has no frontend UI of its own to brand. Adding any frontend-visible branding would be out of scope and against WordPress.org norms. |

## Verified real, non-fabricated contact/link info used in this pass

- **Website:** `https://kazcode.net/` — the actual company site.
- **Contact/support:** `mailto:kazmij@gmail.com` — found as the one real contact link on
  `kazcode.net` itself (a `mailto:` link in the page markup), not invented.
- **Documentation:** the GitHub repository itself
  (`https://github.com/kazmij/kazcode-universal-storage`) — genuinely exists and genuinely
  contains the product's documentation (this `docs/` tree); no separate docs site exists to
  link to instead, and inventing one would violate this task's own "do not invent URLs"
  rule.
- **Release notes / changelog:** `https://github.com/kazmij/kazcode-universal-storage/releases`
  — safe to link to now since GitHub's Releases index page works even before any release is
  published (it just shows tags), and a `v1.0.0` tag is expected once the version-number
  decision (see `docs/LAUNCH-READINESS.md`) is finalized.

No support-desk URL, dedicated documentation site, or company logo/icon image file was
found anywhere — the plugin's attribution links reflect exactly what's real today, not
where it might eventually live.
