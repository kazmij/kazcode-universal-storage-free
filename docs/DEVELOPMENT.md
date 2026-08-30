# KAZCODE Universal Storage — development notes

## This repository

Canonical product source for:

- `kazcode-universal-storage/` — Free core
- `kazcode-universal-storage-pro/` — Pro add-on

GitHub: `git@github.com:kazmij/kazcode-universal-storage.git`

## Requirements

- **PHP 8.3+** (WordPress.org recommended baseline, 2026)
- WordPress **6.7+**
- Composer for unit tests and release builds (`composer install` in `kazcode-universal-storage/`)

**If your machine has no local PHP/Composer** (common — this is a WordPress plugin repo,
not a PHP-first one), use the repo-root `Makefile` instead: every target runs inside the
`apps/` docker-compose QA rig via `docker exec`, so you never need PHP on the host at all.
See "Makefile (no local PHP needed)" below.

## Tests

```bash
cd kazcode-universal-storage
composer install
composer test
# or: ./vendor/bin/phpunit --configuration phpunit.xml.dist
```

WP-CLI / Docker smoke (site must load the plugin):

```bash
wp eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-v2-acceptance.php
wp eval-file wp-content/plugins/kazcode-universal-storage/tests/eval-file/test-phase11-admin-smoke.php
wp eval-file wp-content/plugins/kazcode-universal-storage/test-product-features-local.php
```

Optional MinIO: see repo-root `docker-compose.minio.yml` and `tests/CHARACTERIZATION.md`.

## Release ZIP (PHP-Scoper)

```bash
cd kazcode-universal-storage
bash build/build-release.sh
# → dist/kazcode-universal-storage-X.Y.Z.zip (scoped Aws under S3MS\Vendor)
# → dist/kazcode-universal-storage-pro-X.Y.Z.zip
```

## Makefile (no local PHP needed)

The repo-root `Makefile` wraps the commands above so they run inside the `apps/`
docker-compose QA rig (see `apps/README.md`) instead of requiring PHP/Composer on the
host. Start the rig first (`cd apps && ./setup.sh`, or `docker compose up -d`), then from
the repo root:

```bash
make help        # list targets
make test-all     # core + Pro PHPUnit suites
make build         # release ZIPs → kazcode-universal-storage/dist/
make verify         # verify the scoped release ZIP
```

`make setup` (a dependency of every other target, so you never call it directly) installs
`zip`/`rsync`/`composer.phar` and raises the CLI `memory_limit` inside the running
`apps-wp-71-php84` container the first time they're needed — that container's image
(`apps/Dockerfile.wordpress-wpcli`) doesn't ship those, so the install is container-local
and does not survive `docker compose up -d --build` / `down && up`; `make` just silently
redoes it (fast, idempotent) whenever it's missing. Override the container for
cross-version testing, e.g. `make test CONTAINER=apps-wp-71-php83-1` — but keep `make
build` on the default (php84): PHP-Scoper silently produces an unscoped, broken build
under PHP 8.5+ with no visible error (see the comment in `build/build-release.sh`).

## Architecture plan

See [S3MS-V2-IMPLEMENTATION-PLAN.md](./S3MS-V2-IMPLEMENTATION-PLAN.md) for phases, schema, Free/Pro split, and §36 acceptance criteria. Foundation v2 (through admin IA, profile CRUD) is implemented on `main` of this repo, shipping as the first public release, 1.0.0.

## History note

Work was originally developed inside an APTA CMS working copy on branch `local/kazcode-universal-storage` and was **not** published to that organisation’s remotes. This repository is the intentional product publication target.
