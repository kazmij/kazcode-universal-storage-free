#!/usr/bin/env bash
# Update WordPress.org listing text and optionally reviewed listing assets.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/update-wordpress-org-readme.sh --readme FILE [--assets-dir DIR] --dry-run
  build/update-wordpress-org-readme.sh --readme FILE [--assets-dir DIR] --publish

The script updates trunk/readme.txt and, when supplied, files inside SVN /assets/.
It never creates or changes a release tag. Publish requires KAZUS_RELEASE_PUBLISH=1 plus
KAZUS_WPORG_USERNAME and KAZUS_WPORG_PASSWORD.
USAGE
}

fail() { echo "ERROR: $*" >&2; exit 1; }

README_FILE=""
ASSETS_DIR=""
MODE=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--readme) README_FILE="${2:-}"; shift 2 ;;
		--assets-dir) ASSETS_DIR="${2:-}"; shift 2 ;;
		--dry-run) MODE="dry-run"; shift ;;
		--publish) MODE="publish"; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage >&2; fail "unknown argument: $1" ;;
	esac
done

[[ -n "${README_FILE}" && -n "${MODE}" ]] || { usage >&2; exit 2; }
[[ -f "${README_FILE}" ]] || fail "readme file not found: ${README_FILE}"
[[ -z "${ASSETS_DIR}" || -d "${ASSETS_DIR}" ]] || fail "assets directory not found: ${ASSETS_DIR}"

if [[ "${MODE}" == "publish" ]]; then
	[[ "${KAZUS_RELEASE_PUBLISH:-0}" == "1" ]] || fail "set KAZUS_RELEASE_PUBLISH=1 for publish"
	[[ -n "${KAZUS_WPORG_USERNAME:-}" && -n "${KAZUS_WPORG_PASSWORD:-}" ]] || fail "WordPress.org SVN credentials are required"
fi

SVN_URL="${KAZUS_WPORG_SVN_URL:-https://plugins.svn.wordpress.org/kazcode-universal-storage}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/kazus-wporg-readme.XXXXXX")"
trap 'rm -rf "${TMP}"' EXIT

svn checkout --depth immediates "${SVN_URL}" "${TMP}/svn" >/dev/null
svn update --set-depth infinity "${TMP}/svn/trunk" >/dev/null
install -m 644 "${README_FILE}" "${TMP}/svn/trunk/readme.txt"
svn add --force "${TMP}/svn/trunk/readme.txt" >/dev/null

paths=("${TMP}/svn/trunk/readme.txt")
if [[ -n "${ASSETS_DIR}" ]]; then
	svn update --set-depth infinity "${TMP}/svn/assets" >/dev/null
	rsync -rt --exclude '.svn' "${ASSETS_DIR}/" "${TMP}/svn/assets/"
	svn add --force "${TMP}/svn/assets" >/dev/null
	paths+=("${TMP}/svn/assets")
fi

if [[ -z "$(svn status "${paths[@]}")" ]]; then
	echo "No WordPress.org listing change to publish."
	exit 0
fi

echo "SVN planned listing diff:"
svn diff --summarize "${paths[@]}"

if [[ "${MODE}" == "dry-run" ]]; then
	echo "DRY RUN: no svn commit performed."
	exit 0
fi

printf '%s\n' "${KAZUS_WPORG_PASSWORD}" | svn commit "${paths[@]}" \
	-m "Update WordPress.org listing description and assets" \
	--username "${KAZUS_WPORG_USERNAME}" \
	--password-from-stdin \
	--no-auth-cache
