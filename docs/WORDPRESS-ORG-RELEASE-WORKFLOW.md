# WordPress.org Release Workflow

This document is the release-source-of-truth contract for KAZCODE Universal
Storage Free.

## Source Of Truth

The source of truth for a public WordPress.org release is a clean Git release
commit/tag.

SVN is a publication transport, not the source of truth.

## Operator Commands

Use the repository wrapper rather than exporting SVN credentials by hand. The
credentials file must be local and untracked, with exactly two non-empty lines:
the WordPress.org SVN username and then its password. The wrapper reads it only
for the child command; it does not print it, add it to Git, or pass the password
as an argument.

```bash
# First inspect the exact release-to-SVN change; this makes no remote mutation.
bash kazcode-universal-storage/build/wordpress-org.sh dry-run \
  --version 1.2.0 --tag v1.2.0 --commit <release-commit>

# Publish the exact tagged release after its release gates are green.
bash kazcode-universal-storage/build/wordpress-org.sh release \
  --credentials-file /secure/local/wp-svn \
  --version 1.2.0 --tag v1.2.0 --commit <release-commit>

# Update only the public listing text after reviewing readme.txt. This cannot
# create or alter a versioned SVN tag.
bash kazcode-universal-storage/build/wordpress-org.sh readme \
  --credentials-file /secure/local/wp-svn --dry-run
bash kazcode-universal-storage/build/wordpress-org.sh readme \
  --credentials-file /secure/local/wp-svn
```

The `readme` command changes only `trunk/readme.txt`, which controls the
WordPress.org description, FAQ, changelog, and screenshot captions. Review the
dry-run diff before publishing it. Listing images are maintained separately in
`kazcode-universal-storage-wporg-assets/screenshots/` and travel in SVN's
top-level `assets/` directory during a versioned release.

### Required listing refresh for every public release

Before each WordPress.org release, do all of the following as part of the
release checklist:

1. Review `readme.txt` against the actual shipped features and update the
   Description, Free/Pro boundary, FAQ, changelog, upgrade notice, and
   screenshot captions. Never promise an untested workflow.
2. Capture current, sanitized screenshots from the exact release artifact on
   a disposable WordPress install. Use the numbered `screenshot-N.png` files
   in `kazcode-universal-storage-wporg-assets/screenshots/`; do not use mockups
   or screens containing credentials, bucket names, local paths, or customer
   content.
3. Replace listing assets in SVN's top-level `assets/` directory together with
   the release. Keep `readme.txt` captions synchronized with their sequence.
4. Run the public-package verification and an end-to-end browser smoke against
   a real disposable WordPress installation using the downloaded public ZIP.
   Record the result in the release report before declaring the release done.

Do not release from:

- a dirty working tree;
- untracked runtime files;
- a manually edited SVN trunk;
- a random local directory;
- a branch that is not the release commit;
- an old ZIP with unknown provenance.

Every release must know and record:

- release version;
- source commit SHA;
- source tag;
- local release ZIP SHA-256;
- normalized local manifest SHA-256;
- SVN revision after publication;
- public WordPress.org ZIP SHA-256;
- normalized public manifest SHA-256;
- runtime match result.

## Current Flow Review

`build/build-release.sh` stages the current plugin working tree with `rsync`,
installs production Composer dependencies in staging, runs PHP-Scoper, repairs
the scoped Composer autoload maps, removes development-only files, and writes
the ZIP to `dist/`.

Before the release-integrity gate, this meant:

- the build source was the current local working tree;
- a dirty worktree could be packaged accidentally;
- untracked runtime files could exist outside Git history;
- Composer/scoper-generated vendor and autoload files were required in the
  package but were not tied to a release manifest;
- SVN preparation could copy a package into `trunk/` without proving that
  `trunk/`, `tags/<version>/`, and the local package were runtime-identical;
- public WordPress.org ZIP verification was manual.

The 1.0.1 incident came from violating the invariant that public runtime must be
reproducible from the Git release source. 1.0.2 restored the public runtime and
this workflow prevents a repeat.

## Release Integrity Tool

Use:

```bash
build/release-build-from-tag.sh 1.0.2 v1.0.2 <sha>
php build/release-integrity.php preflight --source . --version 1.0.2 --tag v1.0.2 --commit <sha>
php build/release-integrity.php package --package dist/kazcode-universal-storage-1.0.2.zip --version 1.0.2
php build/release-integrity.php manifest --path dist/kazcode-universal-storage-1.0.2.zip --output dist/kazcode-universal-storage-1.0.2.manifest.sha256
php build/release-integrity.php compare --expected dist/kazcode-universal-storage-1.0.2.zip --actual /path/to/svn/trunk
php build/release-integrity.php compare --expected dist/kazcode-universal-storage-1.0.2.zip --actual /path/to/svn/tags/1.0.2
php build/release-integrity.php post-publish --local-zip dist/kazcode-universal-storage-1.0.2.zip --public-zip /tmp/kazcode-universal-storage.1.0.2.zip --version 1.0.2
```

The `build/release-verify.sh` wrapper forwards to the same verifier.

The normalized manifest format is sorted by relative package path:

```text
<sha256>  <size>  <relative-path>
```

Only VCS metadata is excluded. Vendor files are included.

## Required Release Order

1. Start from clean source.
2. Bump version and changelog.
3. Run tests.
4. Commit the release source.
5. Create the release tag on the exact commit.
6. Build from that tag/commit, preferably in a clean temporary worktree or
   archive.
7. Generate the normalized manifest.
8. Run local package verification.
9. Prepare WordPress.org SVN `trunk/` from the final package/runtime content.
10. Create `tags/<version>/` from the prepared `trunk/`.
11. Compare local package vs SVN `trunk/` and SVN `tags/<version>/`.
12. Publish via SVN.
13. Download the public WordPress.org ZIP.
14. Compare public runtime manifest vs local final release package.
15. Archive the release report.

Do not release from a working tree that contains source changes not committed in
the release tag.

## Git Tag To Build

The release build must be tied to an exact source commit/tag.

Recommended local pattern:

```bash
git fetch origin --tags
bash build/release-build-from-tag.sh 1.0.2 v1.0.2 34a44bb3d548d9fe59e426c8a8e8b4f8ad152833
```

For offline verification of an already-fetched local tag, set
`KAZUS_RELEASE_SKIP_FETCH=1`. Do not use that override for an actual publication unless
the release operator has independently verified the tag provenance.

If the build environment requires Docker, the same source commit/tag rule still
applies. The container must mount the clean release worktree, not a dirty
checkout.

## SVN Pre-Publish Gate

After preparing SVN:

```bash
php build/release-integrity.php compare --expected dist/kazcode-universal-storage-1.0.2.zip --actual /path/to/svn/kazcode-universal-storage/trunk
php build/release-integrity.php compare --expected dist/kazcode-universal-storage-1.0.2.zip --actual /path/to/svn/kazcode-universal-storage/tags/1.0.2
```

Both must report:

```text
local-only=0
package-only=0
different=0
```

If SVN wants to remove, add, or change runtime files outside the final package,
stop the release.

## Public Post-Publish Gate

After SVN publication:

```bash
curl -L https://downloads.wordpress.org/plugin/kazcode-universal-storage.1.0.2.zip -o /tmp/kazcode-universal-storage.1.0.2.zip
sha256sum /tmp/kazcode-universal-storage.1.0.2.zip
unzip -t /tmp/kazcode-universal-storage.1.0.2.zip
php build/release-integrity.php post-publish --local-zip dist/kazcode-universal-storage-1.0.2.zip --public-zip /tmp/kazcode-universal-storage.1.0.2.zip --version 1.0.2
```

WordPress.org ZIP binary SHA-256 may differ from the local ZIP because
WordPress.org repackages from SVN. The extracted runtime manifest must match.

## Release Record

Create or update a release record:

```bash
php build/release-integrity.php record \
  --version 1.0.2 \
  --source-commit <sha> \
  --source-tag v1.0.2 \
  --local-zip dist/kazcode-universal-storage-1.0.2.zip \
  --local-manifest dist/kazcode-universal-storage-1.0.2.manifest.sha256 \
  --svn-revision <revision> \
  --public-zip /tmp/kazcode-universal-storage.1.0.2.zip \
  --public-manifest /tmp/kazcode-universal-storage.1.0.2.manifest.sha256 \
  --output dist/release-1.0.2.json
```

`runtime_match` is computed by the verifier from the supplied ZIPs or manifests; do not
enter it manually.

Never store credentials, cookies, signed URLs, or private dashboard links in the
release record.
