# Product Website Plan

**Status: approved architecture, not yet built.** This document previously sketched a
*standalone-site* plan (own domain implied, top-level `/pricing`/`/features`/provider
pages, its own `/account`). That plan is superseded — the actual, approved architecture
(from a full audit of the existing `kazcode.net` site + this repo's real behavior/docs)
lives nested under the existing KAZCODE company site instead, per the sitemap below.
Site build/deploy work was explicitly prioritized now, ahead of the WordPress.org
submission / commercial-platform-decision items in `docs/LAUNCH-READINESS.md` — this is a
deliberate choice, not an oversight.

## Why a dedicated product page/docs, separate from WordPress.org

The WordPress.org listing sells Free. It cannot sell Pro (WordPress.org plugins may not
link out to paid upgrades in ways that look like the plugin itself is upselling within the
directory's own UI, and Pro is never submitted there at all per `docs/WORDPRESS-ORG-
SUBMISSION.md`). A product page is where Pro is actually described and explained — the
checkout itself would live on whatever commercial platform is approved (see
`docs/COMMERCIAL-PLATFORM-DECISION.md`; Freemius, for example, hosts its own checkout, so
"the site" mainly needs to link to it rather than build one). This lives as a section of
the existing kazcode.net site, not a separate product domain (see Sitemap below).

## Implementation architecture (approved)

- **Stack:** Astro + Starlight, static output, no database, no backend runtime in
  production.
- **Repo:** lives inside the existing `kazcode.net` company site repo (not a new sibling
  repo) — the Astro/Starlight source sits alongside `index.html`, and its build output is
  served from a `universal-storage/` subdirectory of the same web root.
- **Deploy:** the production server updates via `ssh` + `git pull` on that repo (its
  existing deploy method — no CI/webhook exists). After pulling, the static build is
  produced with a one-shot Docker build container (`docker compose run --rm build` or
  equivalent) run directly on the server — no permanently-running app/container, no Node
  installed on the host outside that container, matching "Docker for build only."
- **Homepage:** `index.html` gets one small hand-written addition (a "Products" section +
  nav link) in its existing style; nothing else about it changes.

## Sitemap (approved, nested under kazcode.net — not a standalone domain)

```
https://kazcode.net/                              existing KAZCODE company one-pager (unchanged
                                                    tech; gets one new "Products" section + nav
                                                    link pointing at Universal Storage)

https://kazcode.net/universal-storage/             product landing page (per §8-style structure:
                                                    hero, what it does, supported storage, designed
                                                    for WordPress, Free, Pro, safety, docs CTA)
https://kazcode.net/universal-storage/docs/        documentation home (Astro + Starlight)
  .../docs/getting-started/
  .../docs/installation/
  .../docs/storage-providers/{amazon-s3,cloudflare-r2,digitalocean-spaces,wasabi,
                               backblaze-b2,minio,custom-s3}/
  .../docs/media-offload/  .../docs/migration/  .../docs/restore/  .../docs/health/
  .../docs/wp-cli/  .../docs/troubleshooting/
  .../docs/pro/{storage-profiles,cross-provider-migration,orphan-scan,advanced-health,
                multisite}/          (Pro pages live in the SAME doc tree, tagged with a
                                      sidebar "Pro" badge — never a second, disconnected
                                      docs site, never gated behind auth)
https://kazcode.net/universal-storage/changelog/    user-facing changelog, reconciled from
                                                    docs/RELEASE-NOTES-*.md (rewritten for
                                                    users, not raw commit logs)
https://kazcode.net/universal-storage/support/     Free: WordPress.org support forum + email
                                                    fallback. Pro: real channel once the
                                                    commercial platform exists — no invented
                                                    ticket system in the meantime.
```

No `/pricing`, `/account`, or per-provider marketing landing pages for now — no approved
Pro pricing exists yet (see `docs/COMMERCIAL-LICENSING.md`/`COMMERCIAL-PLATFORM-DECISION.md`),
and `/account` is realistically the eventual commercial platform's own hosted page (e.g.
Freemius), not something to custom-build. The provider-specific SEO value described below
is served by the `/docs/storage-providers/<provider>/` pages instead of separate marketing
landing pages — same keyword targeting, one less thing to maintain.

## SEO priority targets

In rough priority order, based on what this product actually does (not aspirational
keywords for capabilities it doesn't have):

1. `wordpress s3 media offload` / `wordpress object storage plugin`
2. `wordpress cloudflare r2` / `cloudflare r2 wordpress media`
3. `aws s3 to r2 migration` (Pro's flagship differentiator — cross-provider migration with
   verify-before-switch safety)
4. `restore wordpress media from s3`
5. `wordpress minio` (developer/self-hosted audience — smaller volume, high relevance)

Do not target keywords implying capabilities that don't exist (image optimization,
CDN/transforms, DAM, backups — see the rebrand brief's own explicit non-goals list). A
site that overclaims creates support burden and refund risk the moment a customer tries
the feature that was implied but isn't there.

## Brand positioning

```
KAZCODE Universal Storage
Reliable cloud & object storage for WordPress.
```

Four pillars, matching what the product actually does end-to-end (offload isn't the whole
story — restore and repair are what make it *reliable*, not just "another S3 plugin"):

```
OFFLOAD   — move Media Library binaries to S3/R2/S3-compatible storage
MIGRATE   — move between storage profiles/providers safely (Pro)
RESTORE   — bring media back to WordPress on demand, never locked in
REPAIR    — health checks, verification, orphan detection — catch drift before it's a crash
```

**Accurate scope, stated plainly:** the product talks to S3-compatible storage
specifically (Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, MinIO,
generic S3-compatible endpoints) — not Azure Blob or Google Cloud Storage yet (see
`docs/STORAGE-PROVIDER-ROADMAP.md`). The site should not use "Universal" to imply
provider-agnostic support that doesn't exist; the name describes the product's philosophy
(one plugin, whichever S3-compatible backend you choose) not a claim about non-S3 backends.

## Visual identity

Defer to `docs/WORDPRESS-ORG-ASSET-BRIEF.md` for the actual icon/banner spec — the site
should reuse the same mark rather than commissioning a second, divergent visual identity.
Suggested constraints for the site specifically:

- Professional developer-infrastructure aesthetic — this is a tool site visitors are
  evaluating for a business-critical function (their media), not a consumer app.
  Clean, minimal, cloud/storage motif.
- Do not use AWS's or Cloudflare's own logos/brand marks on the site without checking
  their trademark/brand guidelines first — both companies have specific rules about
  third-party use of their marks in marketing contexts, and this product is not
  affiliated with either.
- Should read sensibly in both light and dark contexts, matching how the plugin's own
  admin UI and any future artifact-style documentation should behave.

## What's explicitly out of scope for this document

- Actual page copy beyond the positioning line above (real copy needs real screenshots and
  a finished Pro pricing page first — writing marketing prose against unbuilt pricing tiers
  produces content that has to be rewritten anyway)
- Concrete SEO markup (sitemap.xml content, schema markup, etc.) — Starlight generates the
  sitemap automatically; there's nothing hand-written to plan here beyond what's already
  decided above.
