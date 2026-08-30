# Storage Provider Roadmap

**Status:** Analysis only — nothing in this document has been implemented. It exists to
answer, honestly, a question the "Universal Storage" name invites: *could this plugin
support a genuinely non-S3 backend (Azure Blob, Google Cloud Storage, SFTP/local mirror)
without a rewrite?* The rebrand changed the product name, namespace, and public surface;
it deliberately did **not** touch the storage engine (see `CLAUDE.md` → "Repo-specific
notes" and the destructive-operation invariants). This doc is the design groundwork for a
future decision, not a commitment to build any of it in 2.0.x.

## Current reality: the engine is S3-shaped, not just S3-named

Every class under `includes/Storage/` is written directly against the AWS SDK's S3
client, not against an internal abstraction:

| Class | S3-specific surface it exposes |
|---|---|
| `S3ClientFactory` / `ProfileS3ClientFactory` | Returns `Aws\S3\S3Client` directly (type-hinted in callers, e.g. `ProfileStorageGateway::client()` has no return type precisely because it's threading through an SDK object). |
| `S3Storage` | Every method (`upload_file`, `head_key`, `delete_keys`, `presigned_url_for_key`, `list_keys_page`) is a thin wrapper around one `S3Client` call (`putObject`, `headObject`, `deleteObjects`, `getCommand('GetObject')->createPresignedRequest()`, `listObjectsV2`). Error handling catches `Aws\S3\Exception\S3Exception` specifically. |
| `ObjectKeyService` / `S3KeyResolver` | "Key" is an S3 object key (prefix + relative path), not a generic storage identifier — the concept assumes a flat, prefix-addressable namespace, which S3/R2/Spaces/B2/Wasabi/MinIO all share (they're S3-API-compatible) but Azure Blob and GCS do not expose the same way natively. |
| `PathGuard` | Local-filesystem-only concern (jails file ops under uploads `basedir`); provider-agnostic already. |
| `PublicUrlResolver` / `ProfileDeliveryUrlResolver` | Builds URLs from `{endpoint}/{bucket}/{key}` or a CDN override — this pattern generalizes to any HTTP-addressable object store, so it's the *least* S3-specific piece. |

**What "S3-compatible" already buys us:** MinIO, Cloudflare R2, DigitalOcean Spaces,
Backblaze B2, and Wasabi are all supported today (per `README.md`/`readme.txt`) precisely
because they implement the S3 wire protocol — `S3ClientFactory` just points the same
`Aws\S3\S3Client` at a different endpoint with `force_path_style`. That's not multi-provider
support in the architectural sense; it's one provider (S3-the-protocol) with configurable
endpoints. A genuinely different protocol (Azure Blob's REST API, GCS's JSON/XML API,
plain SFTP) cannot be reached this way at all.

## What a real non-S3 provider would require

1. **A `StorageProviderInterface` (or similar) that `S3Storage` implements** — today
   `Plugin::storage()` and `ProfileStorageGateway` construct `S3Storage`/`S3ClientFactory`
   concretely; nothing calls through an interface. Introducing one would mean:
   - Extracting the six operations that matter (put, head, get/download, delete, list,
     presign) into an interface with provider-neutral parameter/return shapes (no
     `Aws\S3\Exception\S3Exception` in catch blocks at call sites, no assuming a flat
     key namespace maps 1:1 onto every provider's addressing model).
   - `StorageProfile` (`includes/Domain/StorageProfile.php`) already has a `provider`
     field used today only to select S3-compatible *presets* (endpoint/region defaults)
     — it would need to select an *implementation*, not just a preset.
   - `ProfileS3ClientFactory` would become one of several `Profile{X}ClientFactory`
     implementations selected by `StorageProfile::provider()`.
2. **Per-provider SDKs bundled and PHP-Scoper-prefixed** — `scoper.inc.php` already
   isolates `Aws\` under `Kazcode\WpStorage\Vendor\`; an Azure/GCS SDK would need the
   same treatment (namespace collision risk with other plugins is exactly why the AWS
   SDK is scoped today).
3. **A decision on credential shape** — `ProfileCredentialStore`/`Settings` model
   credentials as `access_key_id`/`secret_access_key`, which is AWS-signature-shaped.
   Azure uses account name + key or a SAS token; GCS uses a service-account JSON key;
   SFTP uses host/user/key-or-password. None of these fit the current two-field model
   without either a generic JSON blob column or per-provider credential subtypes.
4. **Presigned URLs and delivery** — `PublicUrlResolver` generalizes reasonably well;
   presigning (`presigned_url_for_key`) is AWS SigV4-specific and each provider has its
   own signing scheme, so `S3Storage::presigned_url_for_key()` cannot be reused as-is.
5. **Multipart upload semantics** — `scoped-vendor-bootstrap.php` aliases
   `Aws\S3\MultipartUploader`; a non-S3 provider's equivalent (if any) would need its own
   wrapper, and the size threshold that triggers multipart differs per provider.

## Explicitly not being done now

Per the brief's own instruction and this plugin's stated priorities (no media loss > no
AWS/R2 regression > correct Free/Pro split > ... > commercial extensibility), none of the
above is being refactored as part of the 2.0.0 rebrand. The rebrand's job was the product
identity layer (name, namespace, slug, hooks); reshaping `includes/Storage/` into a
provider-neutral abstraction is a separate, materially riskier engineering project that
would touch the exact code this rebrand was instructed **not** to touch (S3 engine,
object-key format, migration/restore/delete safety semantics).

## If this is picked up later

- Start from `S3Storage`'s six real operations (listed above), not from a speculative
  "generic storage" API — over-abstracting before a second real provider exists is how
  this kind of interface ends up leaky.
- Treat MinIO/R2/Spaces/B2/Wasabi as they are today: configuration of the *existing* S3
  provider, not evidence multi-provider abstraction is already solved.
- Any new provider is a Pro-tier candidate by the same logic that put cross-provider
  migration in Pro (`docs/FREE-PRO-CODE-AUDIT.md`) — it's new orchestration complexity,
  not a Free-tier primitive.
- Revisit `StorageProfile::provider()`, `ProfileS3ClientFactory`, and
  `ProfileCredentialStore` first — they're the three places that already assume "provider"
  means "an S3-compatible endpoint," and are the natural seam for a real interface.
