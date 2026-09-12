# Commercial Licensing Architecture (design only — not implemented)

**Status:** Design proposal. Nothing in this document has been built. Do not implement payment infrastructure or license validation until this design is explicitly approved, per the same "no scope creep past what was asked" rule the rest of this release-readiness engagement followed.

**Governing rule (non-negotiable):** licensing must never be able to break media. See §5.

---

## 1. Scope boundary

This is deliberately kept separate from `kazcode-universal-storage`/`kazcode-universal-storage-pro`'s storage correctness code:

- **Free (`kazcode-universal-storage`)**: distributed on WordPress.org, no licensing code at all.
- **Pro (`kazcode-universal-storage-pro`)**: gains a `License/` subtree. Nothing else in Pro (or anywhere in core) should ever import from `License/`.
- **Billing, VAT, invoices, Stripe/WooCommerce order logic**: lives entirely on the licensing server, never in the plugin. The plugin only ever asks "is this license valid, what plan, what expiry" and gets a yes/no-shaped answer back.

## 2. Target commercial model (assumption, confirm before building)

- Free tier via WordPress.org.
- Pro sold from your own site (WooCommerce or a merchant-of-record — see §9), license-key activated.
- Annual, site-based license. Suggested plans (pricing itself lives outside the plugin, configurable server-side, not hardcoded in `kazcode-universal-storage-pro`):

  | Plan | Sites | Indicative price |
  |---|---:|---:|
  | Pro | 1 | $79/yr |
  | Business | 5 | $149/yr |
  | Agency | 25 | $249/yr |

## 3. License data model (conceptual — server-side, not this plugin's DB)

```
License
  key
  plan
  status            (active | expired | revoked)
  expires_at
  max_activations
  activations[]      (installation_uuid, site_url, activated_at)
```

Each Pro install generates and persists its own `installation_uuid` (once, on first activation) — **not** derived from `site_url`, since site URLs change (staging→prod, domain migration) and must not silently invalidate a legitimate activation. Activation request:

```json
{ "license_key": "...", "installation_uuid": "...", "site_url": "..." }
```

## 4. Plugin-side architecture (Pro only)

```
kazcode-universal-storage-pro/includes/License/
├── LicenseClient.php        // HTTP calls to the license/update API
├── LicenseState.php         // cached validation result + expiry, persisted in one wp_option
├── LicenseSettings.php      // admin-entered license key, activation state
├── LicenseAdmin.php         // settings-page UI: enter key, activate, status display
└── ProUpdateChecker.php     // wires WordPress's own update-check transient to the license API
```

`ModuleRegistry` (already the integration point Pro uses to plug into core, per `CLAUDE.md`) is the only place `License/` classes get referenced from outside `License/` itself — e.g. `ProModule::boot()` may check `LicenseState::is_valid_for_migration()` before allowing `StorageMigrationService` to run, but `StorageMigrationService` itself must stay licensing-unaware (it already gates on `ProFeatureGate::require('storage_profile_migration')`, which is a **capability** check, not a **license** check — see §6 for how these two composed).

## 5. The one rule that matters: license failure must never break media

Expired license, invalid license, or license-server unavailable must **never** cause:

- existing images to stop loading
- restore to become unavailable
- migration to corrupt mid-flight
- a storage profile to be deleted
- remote files to be deleted

This is not a nice-to-have — it's the same "no media loss" priority that governed every fix in this session's `S3MS-2.0-RELEASE-READINESS.md`. Concretely: `AttachmentUrlFilter`, `AttachmentRestorer`, `S3Storage::delete_keys` etc. must **never** gain a licensing check. If a `License\` import ever appears in `Storage/`, `Attachment/`, or `Services/*ObjectOffloadService|VerificationService|CleanupLocalFiles*`, that's a design violation — reject it in review.

## 6. Two-tier gating: capability vs. license

Keep these conceptually and code-wise separate, composing rather than merging:

- **`ProFeatureGate`** (exists today) answers "does this WordPress install have the Pro module active" — a **capability** question, already correctly used throughout (`StorageMigrationService`, `MigrateObjectService`, profile `insert()`, etc.).
- **`LicenseState`** (new) answers "is the currently-entered license key valid right now" — a **commercial** question.

`ProModule::boot()` is the one place these compose: if `LicenseState` says invalid/expired, `ProModule` can choose to *not register* the Pro module with `ModuleRegistry` for *new-work-initiating* actions (starting a new migration, activating a 2nd profile) — but existing data, existing profiles, existing offloaded media, and restore/repair must keep working exactly as if Pro were still fully licensed. See the capability matrix in §7.

## 7. Capability matrix under an expired license

| Capability | Expired license |
|---|---|
| Existing image delivery | **MUST work** |
| Restore | **MUST work** |
| Repair (re-upload from local) | **MUST work** |
| An in-progress migration | **MUST finish safely** (don't abandon mid-flight) |
| **Starting** a new cross-provider migration | may be blocked |
| **Adding** a 2nd+ storage profile | may be blocked |
| Plugin/license auto-updates | blocked |
| Priority support | unavailable |
| Reading existing Pro settings/profiles | available (read-only is safe; never destructive on expiry) |
| Any destructive cleanup (delete-source after migration, etc.) | **must never be auto-triggered by license state** — only by explicit user action, exactly as today |

## 8. Outage tolerance

The plugin must never make wp-admin or the frontend depend synchronously on the license API.

- Successful validation → cache result + timestamp in one `wp_option` (`LicenseState`), TTL ~24h.
- On re-validation failure (network error, 5xx, timeout): **keep serving the last-known-good cached state** for a grace period (suggest 7–14 days) before treating it as invalid — mirrors how `Settings::get_secret_access_key()` already fails closed-but-non-destructively (returns `''` rather than fataling) elsewhere in this codebase.
- Validation runs on a WP-Cron schedule (daily), never inline on a page load a real visitor or admin is waiting on.

## 9. Pro update delivery

```
WordPress update check
      ↓
ProUpdateChecker (hooks WP's own update transient, standard WP pattern)
      ↓
license/update API  (same server as LicenseClient, different endpoint)
      ↓
version metadata: { version, requires, requires_php, tested, download_url, package_checksum? }
      ↓
short-lived signed download URL (expires, not a permanent public Pro ZIP link)
```

Never expose a permanent, unauthenticated public URL for the Pro ZIP — the download URL should be a signed, time-limited link issued per-request against a valid license, generated server-side.

## 10. Billing platform: three options, no decision made here

| | Effort | Fees | Handles VAT/subs | Notes |
|---|---|---|---|---|
| **A — WooCommerce + a self-hosted API Manager (e.g. WooCommerce Software Add-on style)** | Highest | Lowest (just payment processor fees) | You build/maintain it | Full control, most work, you own PCI/VAT exposure |
| **B — WooCommerce + custom licensing server** | High | Low | You build it | Middle ground; still your infra to run and secure |
| **C — Merchant-of-record (Freemius, Lemon Squeezy, Paddle)** | Lowest | Highest (they take a cut, often handle VAT for you) | They handle it | Fastest to ship; some vendor lock-in; often has a WP-plugin-licensing SDK ready-made |

**Recommendation for a first commercial release:** start with **C**, specifically because §5's "must never break media" constraint is easier to guarantee when you're not also building and hardening your own license server under time pressure — a mature merchant-of-record SDK already handles activation/deactivation/grace-period edge cases. Revisit A/B once volume justifies owning that infrastructure. This is a recommendation, not a decision — confirm before committing engineering time.

## 11. What the plugin explicitly does NOT need to know

- VAT rates, invoice line items, tax jurisdiction
- Stripe/PayPal transaction objects
- WooCommerce order internals

`LicenseClient` talks to one API surface: activate / deactivate / validate / (get update metadata). Everything upstream of that is the license server's problem, not the plugin's.

---

## Non-goals for this design (explicitly out of scope, per the release-readiness engagement's own instructions)

- Image optimization SaaS, on-the-fly transforms, theme/plugin CDN, video transcoding, DAM, agency SaaS dashboard, full WP backups, generic S3 file manager, third-party optimizer integrations. None of these belong in a licensing design doc or should delay launch.

## Next steps (not started)

1. Confirm the billing platform choice (§10) — a product/business decision, not an engineering one.
2. Confirm plan/pricing (§2) — same.
3. Once confirmed: implement `License/` in Pro only, with `LicenseState`'s fail-safe behavior (§5, §8) as the first thing built and the first thing covered by tests — before any UI.
