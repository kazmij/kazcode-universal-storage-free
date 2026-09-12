#!/usr/bin/env bash
# Publish KAZCODE Universal Storage Free to WordPress.org SVN from a Git tag.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/release-wordpress-org.sh --version VERSION --tag TAG --commit SHA --dry-run
  build/release-wordpress-org.sh --version VERSION --tag TAG --commit SHA --publish

Purpose:
  Build from an exact Git tag, prepare WordPress.org SVN trunk/tag from that
  package, compare runtime manifests, and optionally commit.

Required env for publish:
  KAZUS_WPORG_USERNAME
  KAZUS_WPORG_PASSWORD
  KAZUS_RELEASE_PUBLISH=1

Optional env:
  KAZUS_WPORG_SVN_URL       default: https://plugins.svn.wordpress.org/kazcode-universal-storage
  KAZUS_WPORG_ASSETS_DIR    default: ../../kazcode-universal-storage-wporg-assets
  KAZUS_RELEASE_SKIP_FETCH  forwarded to release-build-from-tag.sh

Dry-run behavior:
  Creates a temporary SVN checkout, syncs trunk/assets/tags locally, verifies
  manifests, prints svn status, and exits without svn commit.
USAGE
}

VERSION=""
TAG=""
COMMIT=""
MODE=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="${2:-}"; shift 2 ;;
		--tag) TAG="${2:-}"; shift 2 ;;
		--commit) COMMIT="${2:-}"; shift 2 ;;
		--dry-run) MODE="dry-run"; shift ;;
		--publish) MODE="publish"; shift ;;
		--help|-h) usage; exit 0 ;;
		*) echo "ERROR: unknown argument: $1" >&2; usage >&2; exit 2 ;;
	esac
done

[[ -n "${VERSION}" && -n "${TAG}" && -n "${COMMIT}" && -n "${MODE}" ]] || { usage >&2; exit 2; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(git -C "${ROOT}" rev-parse --show-toplevel)"
SVN_URL="${KAZUS_WPORG_SVN_URL:-https://plugins.svn.wordpress.org/kazcode-universal-storage}"
ASSETS_DIR="${KAZUS_WPORG_ASSETS_DIR:-${REPO_ROOT}/kazcode-universal-storage-wporg-assets}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/kazus-wporg-${VERSION}.XXXXXX")"
trap 'rm -rf "${TMP}"' EXIT

if [[ "${MODE}" == "publish" ]]; then
	[[ "${KAZUS_RELEASE_PUBLISH:-0}" == "1" ]] || { echo "ERROR: set KAZUS_RELEASE_PUBLISH=1 for publish." >&2; exit 1; }
	[[ -n "${KAZUS_WPORG_USERNAME:-}" && -n "${KAZUS_WPORG_PASSWORD:-}" ]] || { echo "ERROR: KAZUS_WPORG_USERNAME and KAZUS_WPORG_PASSWORD are required." >&2; exit 1; }
fi

bash "${ROOT}/build/release-build-from-tag.sh" "${VERSION}" "${TAG}" "${COMMIT}"
ZIP="${ROOT}/dist/kazcode-universal-storage-${VERSION}.zip"
php "${ROOT}/build/release-integrity.php" package --package "${ZIP}" --version "${VERSION}"

svn checkout --depth immediates "${SVN_URL}" "${TMP}/svn" >/dev/null
svn update "${TMP}/svn/trunk" "${TMP}/svn/tags" "${TMP}/svn/assets" >/dev/null || true
if [[ -e "${TMP}/svn/tags/${VERSION}" ]]; then
	echo "ERROR: WordPress.org tag ${VERSION} already exists; refusing overwrite." >&2
	exit 1
fi

unzip -q "${ZIP}" -d "${TMP}/package"
rsync -a --delete --exclude='.svn' "${TMP}/package/kazcode-universal-storage/" "${TMP}/svn/trunk/"

mkdir -p "${TMP}/svn/assets"
if [[ -d "${ASSETS_DIR}" ]]; then
	find "${TMP}/svn/assets" -maxdepth 1 -type f \( -name 'icon-*.png' -o -name 'banner-*.png' -o -name 'screenshot-*.*' \) -delete
	find "${ASSETS_DIR}" -maxdepth 2 -type f \( -name 'icon-*.png' -o -name 'banner-*.png' -o -name 'screenshot-*.*' \) -print0 |
		while IFS= read -r -d '' asset; do
			cp "$asset" "${TMP}/svn/assets/$(basename "$asset")"
		done
fi

svn add --force "${TMP}/svn/trunk" "${TMP}/svn/assets" >/dev/null
svn status "${TMP}/svn/trunk" "${TMP}/svn/assets" | awk '/^!/ {print $2}' | while read -r missing; do
	[[ -n "${missing}" ]] && svn rm "$missing" >/dev/null
done
svn copy "${TMP}/svn/trunk" "${TMP}/svn/tags/${VERSION}" >/dev/null

find "${TMP}/svn/assets" -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' \) -print0 |
	while IFS= read -r -d '' image; do
		case "${image}" in
			*.png) svn propset svn:mime-type image/png "$image" >/dev/null ;;
			*.jpg|*.jpeg) svn propset svn:mime-type image/jpeg "$image" >/dev/null ;;
		esac
	done

php "${ROOT}/build/release-integrity.php" compare --expected "${ZIP}" --actual "${TMP}/svn/trunk"
php "${ROOT}/build/release-integrity.php" compare --expected "${ZIP}" --actual "${TMP}/svn/tags/${VERSION}"

echo "SVN planned status:"
svn status "${TMP}/svn"
echo "SVN planned diff summary:"
svn diff --summarize "${TMP}/svn" || true

if [[ "${MODE}" == "dry-run" ]]; then
	echo "DRY RUN: no svn commit performed."
	exit 0
fi

printf '%s\n' "${KAZUS_WPORG_PASSWORD}" | svn commit "${TMP}/svn" \
	-m "Release ${VERSION}: safe media offload and provider migration" \
	--username "${KAZUS_WPORG_USERNAME}" \
	--password-from-stdin \
	--no-auth-cache

PUBLIC_ZIP="${TMP}/kazcode-universal-storage.${VERSION}.zip"
curl -fsSL "https://downloads.wordpress.org/plugin/kazcode-universal-storage.${VERSION}.zip" -o "${PUBLIC_ZIP}"
php "${ROOT}/build/release-integrity.php" post-publish --local-zip "${ZIP}" --public-zip "${PUBLIC_ZIP}" --version "${VERSION}"
sha256sum "${PUBLIC_ZIP}"
