# KAZCODE Brand Extraction

**Source:** `https://kazcode.net/` raw HTML, fetched and parsed directly (not the markdown-summarized
version — that stripped all CSS/SVG). Every value below is copied verbatim from the site's
inline Tailwind config and logo SVG markup, not inferred or guessed. Fetched 2026-08-27;
re-verify if a meaningful amount of time has passed and the site may have redesigned.

## Primary colors (exact, from the site's Tailwind theme config)

```js
brand: {
  50:  '#eff6ff',
  100: '#dbeafe',
  400: '#60a5fa',
  500: '#3b82f6',  // DEFAULT — primary brand blue
  600: '#2563eb',
  900: '#1e3a8a',
}
accent: {
  DEFAULT: '#10b981',  // Emerald — secondary/hover accent
  hover:   '#059669',
}
```

## Neutral / background colors (dark-mode base)

```js
dark: {
  bg:     '#020617',  // Slate 950 — page background
  card:   '#0f172a',  // Slate 900 — card/panel surfaces
  border: '#1e293b',  // Slate 800 — dividers/borders
}
```

Body text runs on the Tailwind slate scale (`text-slate-300`/`400`/`500`) over the dark
background — light gray-blue text, not pure white, for body copy; pure white (`#ffffff`)
is reserved for high-emphasis text like the logo wordmark's "KAZ".

## Typography

- **Sans (body/headings):** Inter, weights 300–900 loaded via Google Fonts
  (`fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900`)
- **Mono (code/labels):** Fira Code, weights 400/500 — used for small uppercase
  eyebrow labels (`text-sm font-mono text-slate-500 uppercase tracking-widest`) and
  presumably code snippets elsewhere on the site
- Wordmark specifically uses **Inter at weight 900 (black)** with `letter-spacing: 0.05em`

## Logo — exact structure

The site's actual logo, copied directly from its SVG markup (`viewBox="0 0 240 40"`):

```
Icon (left of wordmark):
  Path 1: M4 8 L12 16 L4 24   — stroke #3b82f6 (brand-500), stroke-width 4, round caps/joins
  Path 2: M16 24 L24 24       — stroke #10b981 (accent), stroke-width 4, round caps/joins

Wordmark (right of icon), Inter 900, letter-spacing 0.05em:
  "KAZ"  — fill #ffffff
  "CODE" — fill #3b82f6 (brand-500)
```

**Reading the icon:** Path 1 is an open chevron/bracket (`<` shape, like a code angle-bracket
— reinforces "code"). Path 2 is a short horizontal stroke connecting into it at the bottom,
reading like a cursor or an underscore trailing off the bracket. Both elements swap colors
on hover (icon → accent green, wordmark "CODE" → accent green), which signals the brand
treats blue-as-primary/green-as-highlight as an interchangeable, energetic pairing rather
than a strictly fixed hierarchy.

## Visual tone

Dark-mode-first, high-contrast, minimal — no gradients, no drop shadows beyond a subtle
`glass`/blur nav treatment, no illustration or decorative clipart anywhere in the observed
markup. Section labels use uppercase mono type with wide tracking (a very common
"technical/infrastructure product" convention — signals precision/engineering rather than
consumer-friendly warmth). Layout uses generous whitespace and a `max-w-7xl` centered
container — standard modern SaaS/dev-tool site structure, not busy or maximalist.

Page `<title>`: "KAZCODE | Software Engineering & Web Development". Meta description
positions the company around backend architecture, performance, and full-stack delivery —
consistent with the logo's "code bracket" motif and monospace-accented type choices.

## What to reuse for KAZCODE Universal Storage

- **The brand-500 blue (`#3b82f6`) and accent emerald (`#10b981`) as the two-color core** —
  this pairing is the single most identifiable "this is a KAZCODE product" signal short of
  literally reusing the wordmark.
- **Dark slate background family** (`#020617`/`#0f172a`/`#1e293b`) as the default canvas for
  any dark-mode asset (banner, icon on a dark card) — matches the parent site's own default
  mode.
- **Inter for any wordmark/heading text**, at a heavy weight (700–900) with slightly wide
  letter-spacing, if the product name appears as text in an asset.
- **The chevron/bracket-plus-line icon logic** as a *structural* cue, not a literal copy: a
  simple two-stroke geometric mark in the same two brand colors is the right level of
  "visibly related without being a rip-off."

## What NOT to copy literally

- Do not reuse the exact "KAZ" + "CODE" two-tone wordmark treatment for the product name —
  "KAZCODE Universal Storage" is a different, longer string, and forcing the same two-tone
  split onto it (e.g. "KAZ" white + "CODE Universal Storage" blue) reads as awkward, not
  as a company logo appropriately extended to a sub-product.
- Do not reuse the exact same two SVG paths (`M4 8 L12 16 L4 24` / `M16 24 L24 24`) verbatim
  as the product icon — that IS the parent company's specific mark, not a shared family
  motif. A related but distinct construction (see `docs/UNIVERSAL-STORAGE-BRAND-SYSTEM.md`)
  keeps the family relationship without literally being the same logo re-labeled.
- Do not assume the palette continues past what was actually observed — no purple, red,
  yellow, or other hues appeared anywhere in the extracted brand config (the many other hex
  codes found on the page — `#FF9900`, `#232F3E`, `#2496ED`, `#777BB4`, `#F7DF1E`, etc. — are
  third-party technology/service logos shown as skill badges elsewhere on the page, e.g.
  AWS orange/navy, Docker blue, PHP purple, JavaScript yellow; they are not part of the
  KAZCODE brand palette and should not be treated as such).

## Assumptions and gaps (marked explicitly, per this task's own instruction)

- No favicon or standalone logo image file (PNG/SVG asset) was found separately from the
  inline `<svg>` in the page markup — the logo appears to be built directly in HTML/CSS
  rather than shipped as a static asset file. There is nothing to literally extract as a
  "logo file"; the SVG path data above is the closest thing to a source asset.
- No `theme-color` meta tag or explicit favicon color was found to confirm a single
  "canonical" brand color outside the Tailwind config (the config itself is treated as
  authoritative here, since it's the actual source of every rendered color on the page).
- This extraction is current as of one fetch; the source site could change without notice.
  If pursuing production asset design, a final visual sanity-check against the live site
  immediately before finalizing artwork is cheap insurance against drift.
