# KAZCODE Universal Storage — Brand System

Product-level visual identity, built directly on the palette/typography extracted in
`docs/KAZCODE-BRAND-EXTRACTION.md`. This document is the design brief `docs/WORDPRESS-ORG-
ASSET-BRIEF.md` implements concretely for the actual WordPress.org asset files; read that
doc for exact per-asset copy/layout/dimensions. This one defines the system those assets
draw from.

## Product logo concept

**Recommended direction: a geometric "K" built from stacked storage blocks.**

Three concepts were considered:

1. **A geometric "K" built from stacked storage blocks** *(recommended)* — three or four
   small rounded-rectangle "blocks" of decreasing width, stacked to imply both a capital
   "K" silhouette and a storage stack at the same time. Reads at a glance as "storage" even
   to someone who's never seen the KAZCODE wordmark; reads as "K" (and by extension,
   KAZCODE) to someone who has. Renders cleanly at 16–32px favicon scale because it's pure
   geometry, no fine detail.
2. **A minimal cube/cloud fusion** — a single cube (isometric, 2–3 visible faces) with one
   corner softened into a cloud-like curve. Communicates "cloud object storage" very
   directly, but has no inherent connection to the KAZCODE mark or the letter K — would
   read as a generic cloud-storage icon, indistinguishable from a hundred other plugins'
   icons. Rejected for that reason.
3. **A container/box with a bidirectional data-flow arrow** — a simple box outline with a
   small double-headed arrow crossing through it (representing offload/restore, the
   plugin's two core directions of movement). Strong conceptual fit for the product's
   *behavior* specifically (not just "storage" generically, but *movable* storage), but
   more visually busy than concept 1 at small sizes and has no K/KAZCODE cue at all.

**Why concept 1 wins:** it's the only one of the three that is simultaneously (a) legible
at 16px, (b) recognizable as storage/data without needing the product name next to it, and
(c) visibly a "K" once you know to look for it — satisfying "related to KAZCODE but not a
literal ripoff" better than either alternative, which either abandon the K-cue entirely
(2) or compromise small-size legibility for behavioral specificity (3).

### Icon construction (for whoever builds the actual file)

- Three rounded-rectangle bars, left-aligned, stacked vertically with small consistent
  gaps, each bar shorter than the one above it — reading top-to-bottom as a right-leaning
  staircase. This silhouette doubles as an abstracted "K" (the stepped right edges form the
  diagonal strokes of a K when read against the flush left edge) and as a "stack of
  objects" (storage tiers/layers).
  - Skip a literal serif/stroke "K" letterform entirely — a drawn letter competes for
    attention with the WordPress.org plugin name text right next to it in the directory
    listing, and doesn't survive down to 16px cleanly.
- Bar colors, top to bottom: `#3b82f6` (brand-500), `#60a5fa` (brand-400), `#10b981`
  (accent emerald) — reusing the exact three extracted brand hexes, ordered so the two
  blues dominate (majority of the mark stays "KAZCODE blue") and the emerald appears once,
  at the bottom, as the accent — mirrors how the parent logo uses blue as primary and
  emerald as a secondary highlight, not a 50/50 split.
  - `#dark-bg` (`#020617`) background field behind the mark (Universal Storage's asset
    background should stay in the same dark-mode family as the parent brand rather than
    inventing a light/white version — see Palette below).
- No wordmark text baked into the icon file — WordPress.org renders the plugin name as text
  separately; forcing "KAZCODE Universal Storage" into a 128×128 icon guarantees illegible
  type.

## Wordmark usage

Where the *product name* appears as styled text (banner, any future marketing asset):

```
KAZCODE Universal Storage
```

- Font: Inter, weight 800–900 (Black/Extra Bold) for "KAZCODE Universal Storage" as a
  single-weight lockup — do NOT split it into a two-tone "KAZCODE" (white) + "Universal
  Storage" (blue) the way the parent site's wordmark splits "KAZ"/"CODE". That specific
  two-tone split is the parent company's own signature treatment (see brand-extraction doc
  §"What NOT to copy literally") — reusing it here would read as either sloppily
  confusing this for the company's own name, or as a lazy reskin.
- Instead: full wordmark in a single color — white (`#ffffff`) on the dark background, or
  brand-500 (`#3b82f6`) on a light background — with letter-spacing slightly tighter than
  the parent's `0.05em` (a full product name is much longer than "KAZCODE"; the same wide
  tracking at this length reads as loose rather than confident).
- Optional smaller "by KAZCODE" line beneath/beside the main wordmark, in Inter Regular,
  slate-400 (`#94a3b8`-ish, i.e. one step lighter than `dark-border`) — this is the
  family-relationship cue, not a repeated logo.

## Icon concept (small-format summary)

See "Product logo concept" above — the three-bar stacked-K mark, used identically for
`icon-128x128.png`/`icon-256x256.png` and (if produced later) a favicon.

## Palette

| Token | Hex | Use |
|---|---|---|
| Brand primary | `#3b82f6` | Icon top bar, primary CTA-style accents, link color in plugin UI (already applied to `assets/admin.css` footer/about links this pass) |
| Brand primary (light) | `#60a5fa` | Icon middle bar, secondary highlights |
| Accent | `#10b981` | Icon bottom bar, one deliberate highlight per asset — not a co-equal second primary color |
| Dark background | `#020617` | Default canvas for icon/banner backgrounds |
| Dark card | `#0f172a` | Secondary surface within a banner (e.g. a card-like panel behind the tagline) |
| Dark border | `#1e293b` | Hairlines/dividers if any asset needs internal structure |
| Text on dark | `#ffffff` | Primary heading text (wordmark, banner headline) |
| Muted text on dark | `#94a3b8` (Tailwind slate-400) | Secondary/supporting text (taglines, "by KAZCODE") |

No colors outside this table. In particular: no orange, no red, no purple — those only
appeared on the parent site as *third-party technology logos* (AWS orange, PHP purple,
etc.), never as part of the KAZCODE brand itself (see brand-extraction doc). Introducing
them into Universal Storage's identity would wrongly suggest a formal partnership/
endorsement from those companies.

## Background style

Dark-mode-first, matching the parent site's actual default rendering (no light-mode
version was observed on kazcode.net, so there's no "light" precedent to match against).
Flat color or a very subtle version of the parent's dark gradient family — avoid literal
gradients as a design crutch; the parent site's actual UI is flat-colored dark panels, not
gradients (the one gradient found in this repo's own `assets/admin.css` predates this
brand-extraction and is a plugin-UI decision from before this identity existed, not
something to propagate into new marketing assets).

## Typography direction

- **Inter** for all text in any asset (headline, tagline, "by KAZCODE" line) — matches the
  parent site exactly; do not substitute a different sans-serif.
- **Fira Code** reserved for anything genuinely code/technical (e.g. if a future asset shows
  a CLI snippet like `wp universal-storage migrate`) — not for general body text.
- Weight discipline: 900/800 for the product name, 400–500 for supporting text. Avoid the
  full 300–900 range in one asset — that's appropriate for a whole website's type scale,
  not a single banner.

## UI accent treatment (in-product, not just marketing assets)

The plugin's own admin CSS (`assets/admin.css`) already uses a dark navy/teal header
gradient that predates this brand system and is *not* being re-themed in this pass (out of
scope for a branding/attribution/asset task — see the non-goals in the originating brief).
What *was* applied this pass: the new footer and About-panel links use brand-500
(`#3b82f6`) as their link color, hover brand-600 (`#2563eb`) — the one place brand-accurate
color was introduced into the live UI. A fuller admin-UI re-theme toward this palette is a
reasonable *future* task, not part of this one.

## Do / Don't

**Do:**
- Use the three-bar stacked-K icon concept consistently across icon, favicon, and any
  future avatar/social-card use.
- Keep blue as the dominant color, emerald as a single deliberate accent — matches how the
  parent site itself weights the two colors (dominant blue, occasional green highlight).
- Keep taglines short and factual ("Reliable cloud & object storage for WordPress") —
  matches the parent site's plain, technical tone; no marketing hyperbole.

**Don't:**
- Don't recreate the parent company's exact wordmark two-tone split for the product name.
- Don't introduce gradients, drop shadows, or illustration style not present in the source
  brand.
- Don't use AWS orange, Cloudflare orange, or any other provider's brand color anywhere in
  the product identity — see the branding brief's own explicit rule against looking
  AWS-only or Cloudflare-only.
- Don't make the icon legible only at large size — every use of it on WordPress.org is
  small (128px in the best case, often rendered down to ~40px in list views).
