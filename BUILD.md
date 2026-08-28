# Building the release ZIP

This document describes exactly how `kazcode-universal-storage-1.0.0.zip` — the artifact
distributed for manual download and submitted to WordPress.org — is produced from this
repository's source.

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
composer build:release          # bash build/build-release.sh -> dist/kazcode-universal-storage-1.0.0.zip
composer build:verify-scoped    # bash build/verify-scoped-build.sh (also run automatically at the end of build:release)
```

`composer test` (`phpunit --configuration phpunit.xml.dist`) runs the unit suite against
the unscoped dev `vendor/` — 174 tests, 429 assertions, all passing as of 1.0.0.

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
7. Zips the result as `dist/kazcode-universal-storage-1.0.0.zip`.
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
unzip -l dist/kazcode-universal-storage-1.0.0.zip | head -30   # inspect contents
unzip -d /tmp/kazus-check dist/kazcode-universal-storage-1.0.0.zip
grep -rl "^namespace Aws\\\\" /tmp/kazus-check/kazcode-universal-storage/vendor/ && echo "UNSCOPED REFERENCE FOUND (bad)" || echo "OK: no unscoped Aws\\ namespace found"
```

`build/verify-scoped-build.sh` does a more thorough version of this same check and is run
automatically at the end of `composer build:release`.

## Installing the result

```bash
wp plugin install dist/kazcode-universal-storage-1.0.0.zip --activate
```

or upload the ZIP through **Plugins → Add New → Upload Plugin** in wp-admin.
