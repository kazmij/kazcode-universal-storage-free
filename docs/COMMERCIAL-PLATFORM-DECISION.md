# Commercial Platform Decision — KAZCODE Universal Storage Pro

Follow-on from `docs/COMMERCIAL-LICENSING.md` (architecture, design-only, §10 already sketched a preliminary A/B/C split). This document makes that comparison concrete with current pricing and gives one recommendation. **No billing code has been written — this is a decision document only, per the engagement's own rule not to implement billing before the platform is approved.**

## Options compared

| | WooCommerce + Software Add-on | WooCommerce + own license server | Freemius | Lemon Squeezy | Paddle |
|---|---|---|---|---|---|
| **What it is** | Self-hosted store + a $129/yr WooCommerce extension that issues/validates license keys | Self-hosted store + a custom-built license API (no such extension) | WordPress-native plugin monetization platform — checkout, licensing, and update delivery in one SDK | Generic digital-product merchant of record | Generic SaaS-oriented merchant of record |
| **WordPress-specific?** | Partial (extension is WP-aware, but you still build the update-delivery + activation UI) | No | **Yes** — purpose-built for WP plugin/theme sellers, ships a WP update-checker integration | No | No |
| **Fee** | ~$129/yr flat + normal card-processor fees (~2.9%+30¢ via Stripe) | Processor fees only (~2.9%+30¢) + your engineering time | 7.0% combined at low volume (4.7% base + a 2.3% WordPress-specific surcharge, confirmed current as of this doc's last pricing refresh), tapering with the base tier down toward ~2.8% (0.5% base + the 2.3% surcharge) past $100k/mo gross; gateway fees (~3.5% avg) are separate and additional | 5% + $0.50/transaction (+1.5% intl, +0.5% subscriptions, etc.) | 5% + $0.50/transaction |
| **Who handles VAT/sales tax** | **You** (WooCommerce doesn't do this; you register and remit yourself, or bolt on a separate tax tool) | **You** | Freemius (merchant of record) | Lemon Squeezy (merchant of record) | Paddle (merchant of record) |
| **License activation/deactivation, per-site limits** | Extension provides the API; you wire it up | You build it | Built in, WP-native | You build it on top (no WP concept, generic API) | You build it on top (no WP concept, generic API) |
| **Pro auto-update delivery** | You build it (standard `Plugin_Upgrader` filter pattern) | You build it | Built in — this is what Freemius is best known for | You build it | You build it |
| **Engineering effort to first sale** | Medium-high | Highest | **Lowest** for a WP plugin specifically | Medium (generic checkout, but you still build all WP-specific licensing/update logic) | Medium (same as Lemon Squeezy) |
| **Vendor lock-in** | Low (it's your WooCommerce store; the extension is swappable) | None (fully yours) | Medium (their SDK is woven into your update/licensing flow) | Medium | Medium |
| **Fit for this product** | OK, but duplicates work Freemius gives for free | Most control, most work, most risk under time pressure | **Best fit** — Free-on-WordPress.org + Pro-sold-separately is exactly Freemius's core use case | Fine generically, but you re-build the WP-specific parts Freemius already solved | Same as Lemon Squeezy |

Sources: [Freemius pricing](https://freemius.com/pricing/), [Lemon Squeezy fees](https://www.swell.is/content/lemon-squeezy-pricing), [Paddle fees](https://dodopayments.com/blogs/paddle-fees-explained), [WooCommerce Software Add-on](https://woocommerce.com/products/software-add-on/).

## RECOMMENDED: Freemius

**WHY:**

1. **This product's shape is Freemius's exact target case** — a plugin with a genuinely complete Free tier on WordPress.org and a separately-sold Pro add-on with per-site license activation. That's not incidental to Freemius's design; it's what the SDK is built around, including the update-checker wiring that `docs/COMMERCIAL-LICENSING.md §9` (`ProUpdateChecker`) would otherwise have to be hand-built against a generic API.
2. **Lowest engineering effort to first paid sale** among all five options for a WordPress plugin specifically — checkout, license issuance, activation limits, and Pro update delivery come from one SDK instead of being assembled from a generic payments API (Lemon Squeezy/Paddle) or hand-rolled entirely (WooCommerce+own server).
3. **It is a merchant of record** — VAT/sales-tax registration and remittance across jurisdictions is Freemius's problem, not this project's. Per `COMMERCIAL-LICENSING.md §5`/§8, the priority for a first release is *reliability of "is this license valid" under real-world network conditions*, not lowest possible fee — a mature, WP-specific SDK is more likely to have already hardened the activation/deactivation/grace-period edge cases this project would otherwise have to discover itself.
4. **Fee is the highest of the three MoR options at low volume** (7.0% combined for a WordPress product vs. ~5.5% effective for Lemon Squeezy/Paddle) but the base component tapers with revenue, bringing the combined rate down toward ~2.8% past $100k/month gross — the fee curve favors exactly the "small now, hopefully bigger later" trajectory of a first commercial release, and the WP-specific tooling saved is worth more than the ~1.5-point fee difference at launch volume. (Freemius's pricing was refreshed industry-wide during 2025 — verified current via `freemius.com/pricing/` and `freemius.com/help/documentation/getting-started/our-pricing/` as of this doc's last update; reconfirm at actual account setup since public pricing pages can change.)
5. **Revisit later, not now:** if Pro revenue scales enough that Freemius's fee becomes material, migrating to Paddle/Lemon Squeezy or an owned WooCommerce setup is a "when we have the problem of too much revenue" decision, not a launch blocker — `LicenseClient`'s design (one narrow API surface: activate/deactivate/validate/update-metadata, per `COMMERCIAL-LICENSING.md §11`) is intentionally scoped to make that swap contained to one class if it's ever needed.

**USER ACTION REQUIRED (business decision, not engineering):**

1. Approve Freemius as the platform (or pick an alternative from the table above).
2. Create a Freemius account and a product entry for KAZCODE Universal Storage Pro.
3. Decide final plan/pricing (Commercial-Licensing.md §2 sketches $79/$149/$249 per year as a starting point — confirm or adjust).
4. Provide the resulting API credentials once the account exists — `License/LicenseClient.php` (not yet built, per this document's own scope limit) will be the only place they're consumed.

No billing/licensing code has been implemented as part of this decision. Implementation starts only after this recommendation is approved, per `docs/COMMERCIAL-LICENSING.md`'s own governing rule and this engagement's "do not implement billing until the platform decision is presented" instruction.
