#!/usr/bin/env bash
# Publish the public GitHub Free source mirror and GitHub Release from the canonical tag.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/release-github-free.sh --version VERSION --tag TAG --commit SHA --dry-run
  build/release-github-free.sh --version VERSION --tag TAG --commit SHA --publish
  build/release-github-free.sh --version VERSION --verify

Options:
  --mirror-repo OWNER/REPO     default: kazmij/kazcode-universal-storage-free
  --mirror-url URL             default: git@github.com:OWNER/REPO.git
  --zip PATH                   default: dist/kazcode-universal-storage-VERSION.zip
  --work-dir PATH              reusable temp directory; default: mktemp
  --release-notes PATH         release notes file; generated if omitted

Required for --publish:
  KAZUS_RELEASE_PUBLISH=1
  Git push access to the mirror repository
  GitHub CLI auth or GITHUB_TOKEN for GitHub Release API

Dry-run behavior:
  Builds the mirror tree from the canonical Free source tag, verifies the ZIP,
  prints the mirror source diff and release notes path, and performs no push,
  tag creation, or release mutation.
USAGE
}

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

run() {
	echo "+ $*" >&2
	"$@"
}

json_escape() {
	perl -0777 -pe 's/\\/\\\\/g; s/"/\\"/g; s/\r/\\r/g; s/\n/\\n/g; s/\t/\\t/g; $_ = "\"" . $_ . "\"";' <<<"$1"
}

verify_zip() {
	local zip="$1"
	unzip -t "$zip" >/dev/null
	unzip -p "$zip" kazcode-universal-storage/kazcode-universal-storage.php | grep -Eq "Version:[[:space:]]+${VERSION}"
	unzip -p "$zip" kazcode-universal-storage/kazcode-universal-storage.php | grep -q "KAZUS_VERSION', '${VERSION}'"
}

configure_gh_auth() {
	GH_BIN="$(command -v gh || true)"
	[[ -n "${GH_BIN}" ]] || fail "gh CLI unavailable; install/use project-local gh on PATH before publishing GitHub Free releases"

	if [[ -n "${GH_TOKEN:-}" ]]; then
		return
	fi
	if [[ -n "${GITHUB_TOKEN:-}" ]]; then
		export GH_TOKEN="${GITHUB_TOKEN}"
		return
	fi
	gh auth status --hostname github.com >/dev/null 2>&1 || fail "gh auth required: set GH_TOKEN, set GITHUB_TOKEN, or run gh auth login"
}

gh_release_exists() {
	gh release view "${TAG}" --repo "${MIRROR_REPO}" >/dev/null 2>&1
}

gh_asset_count() {
	gh release view "${TAG}" --repo "${MIRROR_REPO}" --json assets --jq ".assets | map(select(.name == \"${EXPECTED_ASSET}\")) | length"
}

gh_zip_asset_count() {
	gh release view "${TAG}" --repo "${MIRROR_REPO}" --json assets --jq '.assets | map(select(.name | endswith(".zip"))) | length'
}

gh_verify_release_metadata() {
	local title
	local draft
	local prerelease
	local tag_name
	local asset_count
	local zip_asset_count

	tag_name="$(gh release view "${TAG}" --repo "${MIRROR_REPO}" --json tagName --jq '.tagName')"
	title="$(gh release view "${TAG}" --repo "${MIRROR_REPO}" --json name --jq '.name')"
	draft="$(gh release view "${TAG}" --repo "${MIRROR_REPO}" --json isDraft --jq '.isDraft')"
	prerelease="$(gh release view "${TAG}" --repo "${MIRROR_REPO}" --json isPrerelease --jq '.isPrerelease')"
	asset_count="$(gh_asset_count)"
	zip_asset_count="$(gh_zip_asset_count)"

	[[ "${tag_name}" == "${TAG}" ]] || fail "GitHub Release tag ${tag_name} does not match ${TAG}"
	[[ "${title}" == "KAZCODE Universal Storage ${VERSION}" ]] || fail "GitHub Release title '${title}' does not match expected title"
	[[ "${draft}" == "false" ]] || fail "GitHub Release is still draft"
	[[ "${prerelease}" == "false" ]] || fail "GitHub Release is marked prerelease"
	[[ "${asset_count}" == "1" && "${zip_asset_count}" == "1" ]] || fail "expected exactly one asset named ${EXPECTED_ASSET}; found expected=${asset_count}, zip=${zip_asset_count}; do not use --clobber"
}

gh_verify_downloaded_asset() {
	local download_dir="${WORK_DIR}/github-download"
	local downloaded
	local downloaded_sha

	rm -rf "${download_dir}"
	mkdir -p "${download_dir}"
	gh release download "${TAG}" --repo "${MIRROR_REPO}" --pattern "${EXPECTED_ASSET}" --dir "${download_dir}" >/dev/null
	downloaded="${download_dir}/${EXPECTED_ASSET}"
	[[ -f "${downloaded}" ]] || fail "gh release download did not produce ${EXPECTED_ASSET}"
	downloaded_sha="$(sha256sum "${downloaded}" | awk '{print $1}')"
	[[ "${downloaded_sha}" == "${ZIP_SHA}" ]] || fail "downloaded asset sha ${downloaded_sha} does not match ${ZIP_SHA}"
	verify_zip "${downloaded}"
	echo "GitHub Free release verified with gh release download: ${EXPECTED_ASSET} sha256=${downloaded_sha}"
}

VERSION=""
TAG=""
COMMIT=""
MODE=""
MIRROR_REPO="kazmij/kazcode-universal-storage-free"
MIRROR_URL=""
ZIP_PATH=""
WORK_DIR=""
NOTES_FILE=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="${2:-}"; shift 2 ;;
		--tag) TAG="${2:-}"; shift 2 ;;
		--commit) COMMIT="${2:-}"; shift 2 ;;
		--mirror-repo) MIRROR_REPO="${2:-}"; shift 2 ;;
		--mirror-url) MIRROR_URL="${2:-}"; shift 2 ;;
		--zip) ZIP_PATH="${2:-}"; shift 2 ;;
		--work-dir) WORK_DIR="${2:-}"; shift 2 ;;
		--release-notes) NOTES_FILE="${2:-}"; shift 2 ;;
		--dry-run) MODE="dry-run"; shift ;;
		--publish) MODE="publish"; shift ;;
		--verify) MODE="verify"; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage >&2; fail "unknown argument: $1" ;;
	esac
done

[[ -n "${VERSION}" ]] || { usage >&2; fail "--version is required"; }
[[ -n "${MODE}" ]] || { usage >&2; fail "--dry-run, --publish, or --verify is required"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(git -C "${PLUGIN_DIR}" rev-parse --show-toplevel)"
TAG="${TAG:-v${VERSION}}"
MIRROR_URL="${MIRROR_URL:-git@github.com:${MIRROR_REPO}.git}"
ZIP_PATH="${ZIP_PATH:-${PLUGIN_DIR}/dist/kazcode-universal-storage-${VERSION}.zip}"
EXPECTED_ASSET="kazcode-universal-storage-${VERSION}.zip"

tag_commit="$(git -C "${REPO_ROOT}" rev-list -n 1 "${TAG}")"
if [[ -n "${COMMIT}" && "${tag_commit}" != "${COMMIT}" ]]; then
	fail "${TAG} points to ${tag_commit}, not expected ${COMMIT}"
fi
COMMIT="${COMMIT:-${tag_commit}}"

remote_tag="$(git -C "${REPO_ROOT}" ls-remote --tags origin "refs/tags/${TAG}" | awk '{print $1}' || true)"
[[ -n "${remote_tag}" ]] || fail "remote origin tag ${TAG} not found"
remote_tag_commit="$(git -C "${REPO_ROOT}" rev-list -n 1 "${remote_tag}")"
[[ "${remote_tag_commit}" == "${COMMIT}" ]] || fail "remote origin tag ${TAG} resolves to ${remote_tag_commit}, expected ${COMMIT}"

[[ -f "${ZIP_PATH}" ]] || fail "ZIP not found: ${ZIP_PATH}"
verify_zip "${ZIP_PATH}"
ZIP_SHA="$(sha256sum "${ZIP_PATH}" | awk '{print $1}')"
ZIP_SIZE="$(stat -c '%s' "${ZIP_PATH}")"

if [[ -z "${WORK_DIR}" ]]; then
	WORK_DIR="$(mktemp -d)"
	CLEAN_WORK_DIR=1
else
	mkdir -p "${WORK_DIR}"
	CLEAN_WORK_DIR=0
fi
cleanup() {
	if [[ "${CLEAN_WORK_DIR:-0}" == "1" ]]; then
		rm -rf "${WORK_DIR}"
	fi
}
trap cleanup EXIT

SOURCE_DIR="${WORK_DIR}/source"
MIRROR_DIR="${WORK_DIR}/mirror"
mkdir -p "${SOURCE_DIR}"
git -C "${REPO_ROOT}" archive --format=tar "${TAG}:kazcode-universal-storage" | tar -xf - -C "${SOURCE_DIR}"

git clone "${MIRROR_URL}" "${MIRROR_DIR}" >/dev/null 2>&1
git -C "${MIRROR_DIR}" fetch origin --tags >/dev/null 2>&1

TAG_EXISTS=0
if git -C "${MIRROR_DIR}" rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
	TAG_EXISTS=1
	existing_tag_commit="$(git -C "${MIRROR_DIR}" rev-list -n 1 "${TAG}")"
	git -C "${MIRROR_DIR}" checkout -q "${TAG}"
	echo "Mirror tag ${TAG} already exists at ${existing_tag_commit}; source/tag stage will not be changed." >&2
fi

if [[ "${TAG_EXISTS}" == "0" ]]; then
	rsync -a --delete --exclude=.git "${SOURCE_DIR}/" "${MIRROR_DIR}/"

	if [[ -f "${MIRROR_DIR}/README.md" ]]; then
		perl -0pi -e 's/WordPress\.org listing status:[^\n]*(?:\n[^\n]*){0,2}/WordPress.org: <https:\/\/wordpress.org\/plugins\/kazcode-universal-storage\/>.\nProduct: <https:\/\/kazcode.net\/universal-storage\/>.\nDocs: <https:\/\/kazcode.net\/universal-storage\/docs\/>.\nPro\/pricing: <https:\/\/kazcode.net\/universal-storage\/#pricing>./s' "${MIRROR_DIR}/README.md"
		perl -0pi -e 's/Pro \(if installed\) requires Free active, version [0-9.]+ or newer/Pro 1.1.x (if installed) requires Free active, version 1.1.0 or newer/g' "${MIRROR_DIR}/README.md"
	fi

	if [[ -f "${MIRROR_DIR}/BUILD.md" ]]; then
		perl -0pi -e 's/VERSION=1\.0\.[0-9]+/VERSION='"${VERSION}"'/g; s/TAG=v1\.0\.[0-9]+/TAG='"${TAG}"'/g' "${MIRROR_DIR}/BUILD.md"
	fi
fi

if find "${MIRROR_DIR}" -path "${MIRROR_DIR}/.git" -prune -o \( -name '*release.env*' -o -name '.env' -o -name '*secret*' -o -name '*credential*' \) -print | grep -q .; then
	fail "mirror tree contains suspicious secret/credential filenames"
fi
if grep -RIlE 'Authorization: Bearer [A-Za-z0-9._~+/-]{12,}|BEGIN (RSA|OPENSSH) PRIVATE KEY|github_pat_[A-Za-z0-9_]+|ghp_[A-Za-z0-9_]+' "${MIRROR_DIR}" --exclude-dir=.git | grep -q .; then
	fail "mirror tree contains a suspicious secret pattern"
fi
if find "${MIRROR_DIR}" -maxdepth 2 -type d -name 'kazcode-universal-storage-pro' | grep -q .; then
	fail "mirror tree contains Pro source"
fi

if [[ -z "${NOTES_FILE}" ]]; then
	NOTES_FILE="${WORK_DIR}/github-release-${VERSION}.md"
fi
	cat >"${NOTES_FILE}" <<NOTES
# KAZCODE Universal Storage ${VERSION}

A major reliability and migration release for KAZCODE Universal Storage.

## Highlights

* Resumable multipart transfers for large media.
* Safer interrupted-transfer recovery.
* Improved storage verification and fail-safe cleanup behavior.
* Import/adoption support for WP Offload Media, including newer 3.4+ per-file data.
* Advanced Media Offloader adoption/import improvements.
* Stronger profile-aware handling for existing remote media.
* Improved restore/recovery behavior.
* WordPress 7.1 compatibility hardening.

## Reliability & safety

KAZCODE verifies remote content before destructive local cleanup, treats temporary
provider/network failures as unknown rather than missing, fences concurrent stale
operations, preserves long object-key identity, and keeps retries recoverable.

## Pro ${VERSION}

KAZCODE Universal Storage Pro adds multiple storage profiles, resumable
provider-to-provider migration, verified destination cutover, source-authoritative
migration until verification succeeds, and optional safe source cleanup.

## Upgrade

Free 1.0.2 to ${VERSION} is supported. The database schema upgrades
automatically, existing settings/profiles/media state are preserved, and Pro
${VERSION} requires Free/Core ${VERSION} or newer.

## Requirements

* WordPress >= 6.7
* PHP >= 8.3

## Download integrity

Asset: \`kazcode-universal-storage-${VERSION}.zip\`

SHA-256: \`${ZIP_SHA}\`

The WordPress.org ZIP may have a different binary SHA because WordPress.org
repackages from SVN. Runtime contents are verified equivalent.

## Links

* WordPress.org: https://wordpress.org/plugins/kazcode-universal-storage/
* Product: https://kazcode.net/universal-storage/
* Documentation: https://kazcode.net/universal-storage/docs/
* Pro/pricing: https://kazcode.net/universal-storage/#pricing
* Release article: https://kazcode.net/universal-storage/changelog/
NOTES

echo "Canonical tag: ${TAG} -> ${COMMIT}"
echo "Mirror repo: ${MIRROR_REPO}"
echo "ZIP: ${ZIP_PATH}"
echo "ZIP size: ${ZIP_SIZE}"
echo "ZIP sha256: ${ZIP_SHA}"
echo "Mirror diff:"
git -C "${MIRROR_DIR}" status --short --untracked-files=all
git -C "${MIRROR_DIR}" diff --stat
echo "Release notes: ${NOTES_FILE}"

if [[ "${MODE}" == "dry-run" ]]; then
	echo "DRY RUN: no mirror push, tag, or GitHub Release mutation performed."
	exit 0
fi

if [[ "${MODE}" == "verify" ]]; then
	configure_gh_auth
	gh_verify_release_metadata
	gh_verify_downloaded_asset
	exit 0
fi

[[ "${KAZUS_RELEASE_PUBLISH:-0}" == "1" ]] || fail "set KAZUS_RELEASE_PUBLISH=1 for --publish"

if [[ "${TAG_EXISTS}" == "0" ]] && { ! git -C "${MIRROR_DIR}" diff --quiet || [[ -n "$(git -C "${MIRROR_DIR}" status --short --untracked-files=all)" ]]; }; then
	git -C "${MIRROR_DIR}" add -A
	git -C "${MIRROR_DIR}" commit -m "release: sync Free ${VERSION} source"
	git -C "${MIRROR_DIR}" push origin HEAD:main
fi
mirror_commit="$(git -C "${MIRROR_DIR}" rev-parse HEAD)"

if git -C "${MIRROR_DIR}" rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
	existing_tag_commit="$(git -C "${MIRROR_DIR}" rev-list -n 1 "${TAG}")"
	[[ "${existing_tag_commit}" == "${mirror_commit}" ]] || fail "mirror tag ${TAG} exists at ${existing_tag_commit}, expected ${mirror_commit}"
else
	git -C "${MIRROR_DIR}" tag "${TAG}" "${mirror_commit}"
	git -C "${MIRROR_DIR}" push origin "${TAG}"
fi

configure_gh_auth
if gh_release_exists; then
	asset_count="$(gh_asset_count)"
	zip_asset_count="$(gh_zip_asset_count)"
	if [[ "${asset_count}" == "1" && "${zip_asset_count}" == "1" ]]; then
		echo "GitHub Release ${TAG} already has the expected asset; verifying only."
	elif [[ "${asset_count}" == "0" && "${zip_asset_count}" == "0" ]]; then
		gh release upload "${TAG}" "${ZIP_PATH}" --repo "${MIRROR_REPO}"
	else
		fail "expected exactly one asset named ${EXPECTED_ASSET}; found expected=${asset_count}, zip=${zip_asset_count}; do not use --clobber"
	fi
else
	gh release create "${TAG}" "${ZIP_PATH}" \
		--repo "${MIRROR_REPO}" \
		--verify-tag \
		--title "KAZCODE Universal Storage ${VERSION}" \
		--notes-file "${NOTES_FILE}"
fi

gh_verify_release_metadata
gh_verify_downloaded_asset
