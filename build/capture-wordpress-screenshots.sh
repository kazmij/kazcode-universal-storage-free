#!/usr/bin/env bash
# Capture WordPress.org/website screenshots from the built Free ZIP.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/capture-wordpress-screenshots.sh --version VERSION --dry-run
  build/capture-wordpress-screenshots.sh --version VERSION --source-dir DIR --output DIR
  build/capture-wordpress-screenshots.sh --version VERSION --wp-url URL --output DIR

Purpose:
  Uses an existing disposable WordPress test site and Playwright-compatible
  browser environment to capture deterministic admin screenshots from the exact
  Free release ZIP. The script intentionally does not install host browsers.

Required for capture:
  --wp-url URL
  KAZUS_WP_ADMIN_USER
  KAZUS_WP_ADMIN_PASSWORD

Output:
  screenshot-1.png ... screenshot-6.png in the chosen output directory.
USAGE
}

VERSION=""
WP_URL=""
SOURCE_DIR=""
OUTPUT=""
DRY_RUN=0
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="${2:-}"; shift 2 ;;
		--wp-url) WP_URL="${2:-}"; shift 2 ;;
		--source-dir) SOURCE_DIR="${2:-}"; shift 2 ;;
		--output) OUTPUT="${2:-}"; shift 2 ;;
		--dry-run) DRY_RUN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) echo "ERROR: unknown argument: $1" >&2; usage >&2; exit 2 ;;
	esac
done

[[ -n "${VERSION}" ]] || { usage >&2; exit 2; }
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="${ROOT}/dist/kazcode-universal-storage-${VERSION}.zip"
[[ -f "${ZIP}" ]] || { echo "ERROR: missing ${ZIP}; build from tag first." >&2; exit 1; }

if [[ "${DRY_RUN}" == "1" ]]; then
	echo "DRY RUN: would capture screenshots for ${ZIP}"
	echo "Requires disposable WP URL plus KAZUS_WP_ADMIN_USER/KAZUS_WP_ADMIN_PASSWORD."
	exit 0
fi

if [[ -n "${SOURCE_DIR}" ]]; then
	[[ -n "${OUTPUT}" ]] || { usage >&2; exit 2; }
	[[ -d "${SOURCE_DIR}" ]] || { echo "ERROR: screenshot source not found: ${SOURCE_DIR}" >&2; exit 1; }
	mkdir -p "${OUTPUT}"
	cp "${SOURCE_DIR}/free-free-dashboard.png" "${OUTPUT}/screenshot-1.png"
	cp "${SOURCE_DIR}/free-free-storage.png" "${OUTPUT}/screenshot-2.png"
	cp "${SOURCE_DIR}/free-free-media-library.png" "${OUTPUT}/screenshot-3.png"
	cp "${SOURCE_DIR}/direct-actions-media-library-before.png" "${OUTPUT}/screenshot-4.png"
	cp "${SOURCE_DIR}/direct-actions-after-restore.png" "${OUTPUT}/screenshot-5.png"
	cp "${SOURCE_DIR}/upgrade-upgrade-pro-wizard.png" "${OUTPUT}/screenshot-6.png"
	if grep -R -I -E 'AKIA|SECRET|PASSWORD|/home/|localhost|127\.0\.0\.1' "${OUTPUT}" >/dev/null; then
		echo "ERROR: staged screenshot files appear to contain private text." >&2
		exit 1
	fi
	echo "OK: staged WordPress.org screenshots in ${OUTPUT}"
	exit 0
fi

[[ -n "${WP_URL}" && -n "${OUTPUT}" ]] || { usage >&2; exit 2; }
[[ -n "${KAZUS_WP_ADMIN_USER:-}" && -n "${KAZUS_WP_ADMIN_PASSWORD:-}" ]] || {
	echo "ERROR: KAZUS_WP_ADMIN_USER and KAZUS_WP_ADMIN_PASSWORD are required." >&2
	exit 1
}

if ! command -v node >/dev/null 2>&1; then
	echo "ERROR: node/playwright runner not found. Use the project Playwright container and mount this worktree." >&2
	exit 1
fi

mkdir -p "${OUTPUT}"
echo "ERROR: screenshot capture routes are environment-specific. Use the existing Playwright QA harness and write final images to ${OUTPUT}." >&2
echo "This script documents the release entry point and validates artifact/admin credentials, but does not invent a browser framework." >&2
exit 1
