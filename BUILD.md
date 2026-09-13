# Building the release ZIP

This document describes exactly how `kazcode-universal-storage-$VERSION.zip` — the artifact
distributed for manual download and submitted to WordPress.org — is produced from this
repository's source.

The release source of truth is a clean Git release commit/tag. Do not release from a
working tree that contains source changes not committed in the release tag. SVN is a
publication transport, not the source of truth.

## Requirements

- **PHP 8.3 or 8.4** — the PHP-Scoper step specifically must run under 8.3 or 8.4. Under
  PHP 8.5+, PHP-Scoper silently no-ops (reports success, prefixes nothing, no visible
  error) because `SplObjectStorage::attach()` became deprecated in 8.5 and PHP-Scoper's
  own error handler turns that deprecation into a fatal exception deep inside
  `nikic/php-parser`. See <https://github.com/humbug/php-scoper/issues/1139>.
- **Composer** (any recent version)
- **zip** command-line tool

## Commands

```bash
composer install                # installs aws/aws-sdk-php + dev tools (phpunit, php-scoper, dg/bypass-finals)
composer build:release          # bash build/build-release.sh -> dist/kazcode-universal-storage-$VERSION.zip
composer build:verify-scoped    # bash build/verify-scoped-build.sh (also run automatically at the end of build:release)
```

`composer test` (`phpunit --configuration phpunit.xml.dist`) runs the unit suite against
the unscoped dev `vendor/`.

For public releases, first verify the exact Git tag/commit:

```bash
VERSION=1.1.2
TAG=v1.1.2
COMMIT="$(git rev-parse HEAD)"
php build/release-integrity.php preflight --source . --version "$VERSION" --tag "$TAG" --commit "$COMMIT"
```

Preferred public-release build command:

```bash
bash build/release-build-from-tag.sh "$VERSION" "$TAG" "$COMMIT"
```

That command creates a temporary detached Git worktree from the tag, runs preflight in
that clean source, builds there, generates a manifest, and copies only the final release
artifacts back to `dist/`.

## Why PHP-Scoper

The plugin bundles the AWS SDK for PHP (`aws/aws-sdk-php`) and its transitive
dependencies. Because many WordPress sites run several plugins that each bundle their own
copy of common libraries (Guzzle, PSR interfaces, etc.), shipping them under their
original namespaces risks class/version collisions with whatever another active plugin
bundled. [PHP-Scoper](https://github.com/humbug/php-scoper) mechanically rewrites every
third-party namespace under `vendor/` to a private prefix —
**`Kazcode\WpStorage\Vendor\`** — so this plugin's copy can never collide with anyone
else's. `scoper.inc.php` is the exact prefixing configuration used; it explicitly excludes
this plugin's own `Kazcode\WpStorage\` namespace, so **none of this plugin's own code is
ever rewritten, minified, or obfuscated** — only third-party library namespaces are
prefixed. The unmodified original AWS SDK source is public:
<https://github.com/aws/aws-sdk-php> (Apache-2.0).

## What `build/build-release.sh` actually does

1. Syncs `readme.txt`'s `Stable tag`/`Version` header to `KAZUS_VERSION` in the plugin's
   main file (`build/sync-readme-version.php`).
2. Copies this whole tree into a staging directory (`dist/.stage-build/`), excluding
   `.git`, `.phpunit.cache`, and `dist` itself.
3. Runs `composer install --no-dev --optimize-autoloader` in the staging copy —
   production-only dependencies, dev tooling (PHPUnit, PHP-Scoper itself) excluded.
4. Runs PHP-Scoper (`add-prefix --force --output-dir=build/scoped`) against the staged
   copy, then replaces `vendor/` and `includes/` with the scoped output and drops in
   `vendor/kazcode-scoped.php` (`build/scoped-vendor-bootstrap.php`), which the plugin's
   bootstrap requires to make the scoped autoloader work correctly.
5. Regenerates `THIRD-PARTY-LICENSES.txt` from the production `vendor/` tree
   (`build/collect-licenses.php`).
6. Strips development-only material that has no place in a distributed WordPress plugin:
   `tests/`, `docs/`, `build/`, `vendor/bin/`, `phpunit.xml.dist`, `composer.lock`,
   `scoper.inc.php`, `.gitignore`, `test-product-features-local.php`, any
   `docker-compose*.yml`.
7. Zips the result as `dist/kazcode-universal-storage-$VERSION.zip`.
8. Runs `build/verify-scoped-build.sh` against that ZIP — checks that no unscoped
   third-party class reference leaked through.

`build/build-release.sh` also knows how to package the separately-sold Pro add-on when
its source happens to be present as a sibling directory (`kazcode-universal-storage-pro/`
next to this repository) — irrelevant here, since this repository never contains that
directory; the script detects its absence and simply skips that step.

## Why `tests/`, `build/`, `scoper.inc.php`, etc. are excluded from the ZIP but present in this repository

This repository is the **development source** — the readable, reproducible starting
point a reviewer or contributor needs. The **WordPress.org release ZIP** is the minimal,
production-ready artifact actually installed on end-user sites. Shipping the build
tooling *inside* the distributed ZIP was tried and reverted: WordPress.org's Plugin Check
tool flags shell scripts under a plugin's own files as disallowed "application files"
(`application_detected`), and scans the standalone PHP build scripts as if they were
runtime plugin code (flagging a missing `ABSPATH` guard, direct filesystem calls, etc.)
even though they never execute inside WordPress. Keeping the development material here,
in the public repository, and keeping the distributed ZIP minimal, is what actually
satisfies WordPress.org's guideline on public access to source code and build tools
without tripping its separate rule against bundling application files.

## Verifying the scoped build yourself

```bash
VERSION=1.1.2
ZIP="dist/kazcode-universal-storage-${VERSION}.zip"
unzip -l "$ZIP" | head -30   # inspect contents
unzip -d /tmp/kazus-check "$ZIP"
grep -rl "^namespace Aws\\\\" /tmp/kazus-check/kazcode-universal-storage/vendor/ && echo "UNSCOPED REFERENCE FOUND (bad)" || echo "OK: no unscoped Aws\\ namespace found"
php build/release-integrity.php package --package "$ZIP" --version "$VERSION"
php build/release-integrity.php manifest --path "$ZIP" --output "dist/kazcode-universal-storage-${VERSION}.manifest.sha256"
```

`build/verify-scoped-build.sh` does a more thorough version of this same check and is run
automatically at the end of `composer build:release`.

Before publishing to WordPress.org SVN, compare the exact local package to prepared SVN
`trunk/` and `tags/$VERSION/`:

```bash
php build/release-integrity.php compare --expected "$ZIP" --actual /path/to/svn/kazcode-universal-storage/trunk
php build/release-integrity.php compare --expected "$ZIP" --actual "/path/to/svn/kazcode-universal-storage/tags/${VERSION}"
```

Both comparisons must report `local-only=0`, `package-only=0`, and `different=0`.

After SVN publication, download the public WordPress.org ZIP and compare runtime
content. The ZIP file SHA-256 may differ because WordPress.org repackages from SVN; the
extracted runtime manifest must match:

```bash
curl -L "https://downloads.wordpress.org/plugin/kazcode-universal-storage.${VERSION}.zip" -o "/tmp/kazcode-universal-storage.${VERSION}.zip"
php build/release-integrity.php post-publish --local-zip "$ZIP" --public-zip "/tmp/kazcode-universal-storage.${VERSION}.zip" --version "$VERSION"
```

Detailed operator steps live in `docs/WORDPRESS-ORG-RELEASE-WORKFLOW.md`.

## Installing the result

```bash
wp plugin install "dist/kazcode-universal-storage-${VERSION}.zip" --activate
```

or upload the ZIP through **Plugins → Add New → Upload Plugin** in wp-admin.
