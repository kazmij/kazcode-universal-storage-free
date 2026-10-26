#!/usr/bin/env bash
# Operator entry point for WordPress.org releases and listing-text updates.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/wordpress-org.sh dry-run --version VERSION --tag TAG --commit SHA
  build/wordpress-org.sh release --credentials-file FILE --version VERSION --tag TAG --commit SHA
  build/wordpress-org.sh readme --credentials-file FILE [--readme FILE] [--assets-dir DIR] [--dry-run]

Commands:
  dry-run  Build the exact Git tag and prepare the WordPress.org SVN change without committing.
  release  Publish a versioned WordPress.org SVN release from the exact Git tag.
  readme   Update listing text and optional reviewed assets; it never creates a release tag.

Credentials file:
  A local, untracked file containing exactly two non-empty lines: WordPress.org
  SVN username then password. The file is read only by this wrapper; neither
  value is printed, written to Git, or passed as a command-line argument.
USAGE
}

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

COMMAND="${1:-}"
case "${COMMAND}" in
	dry-run|release|readme) shift ;;
	--help|-h|'') usage; exit 0 ;;
	*) usage >&2; fail "unknown command: ${COMMAND}" ;;
esac

CREDENTIALS_FILE=""
VERSION=""
TAG=""
COMMIT=""
README_FILE=""
ASSETS_DIR=""
README_DRY_RUN=0
while [[ $# -gt 0 ]]; do
	case "$1" in
		--credentials-file) CREDENTIALS_FILE="${2:-}"; shift 2 ;;
		--version) VERSION="${2:-}"; shift 2 ;;
		--tag) TAG="${2:-}"; shift 2 ;;
		--commit) COMMIT="${2:-}"; shift 2 ;;
		--readme) README_FILE="${2:-}"; shift 2 ;;
		--assets-dir) ASSETS_DIR="${2:-}"; shift 2 ;;
		--dry-run) README_DRY_RUN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage >&2; fail "unknown argument: $1" ;;
	esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(dirname "${ROOT}")"

require_source_repository() {
	[[ -f "${REPO_ROOT}/Makefile" ]] || fail "repository Makefile not found; run from the source repository"
}

load_credentials() {
	[[ -n "${CREDENTIALS_FILE}" ]] || fail "--credentials-file is required for ${COMMAND}"
	[[ -f "${CREDENTIALS_FILE}" ]] || fail "credentials file not found"
	[[ ! -L "${CREDENTIALS_FILE}" ]] || fail "credentials file must not be a symlink"

	local -a lines=()
	mapfile -t lines < "${CREDENTIALS_FILE}"
	[[ "${#lines[@]}" -eq 2 && -n "${lines[0]}" && -n "${lines[1]}" ]] || \
		fail "credentials file must contain exactly two non-empty lines"
	KAZUS_WPORG_USERNAME="${lines[0]}"
	KAZUS_WPORG_PASSWORD="${lines[1]}"
}

case "${COMMAND}" in
	dry-run)
		[[ -n "${VERSION}" && -n "${TAG}" && -n "${COMMIT}" ]] || fail "--version, --tag, and --commit are required for dry-run"
		require_source_repository
		exec make -C "${REPO_ROOT}" release-wp-dry-run VERSION="${VERSION}" TAG="${TAG}" COMMIT="${COMMIT}"
		;;
	release)
		[[ -n "${VERSION}" && -n "${TAG}" && -n "${COMMIT}" ]] || fail "--version, --tag, and --commit are required for release"
		load_credentials
		require_source_repository
		exec env KAZUS_WPORG_USERNAME="${KAZUS_WPORG_USERNAME}" KAZUS_WPORG_PASSWORD="${KAZUS_WPORG_PASSWORD}" KAZUS_RELEASE_PUBLISH=1 \
			make -C "${REPO_ROOT}" release-wp VERSION="${VERSION}" TAG="${TAG}" COMMIT="${COMMIT}"
		;;
	readme)
		load_credentials
		require_source_repository
		README_FILE="${README_FILE:-${ROOT}/readme.txt}"
		[[ -f "${README_FILE}" ]] || fail "readme file not found"
		[[ -z "${ASSETS_DIR}" || -d "${ASSETS_DIR}" ]] || fail "assets directory not found"
		README_FILE="$(cd "$(dirname "${README_FILE}")" && pwd)/$(basename "${README_FILE}")"
		if [[ -n "${ASSETS_DIR}" ]]; then
			ASSETS_DIR="$(cd "${ASSETS_DIR}" && pwd)"
		fi
		mode="release-wp-readme"
		if [[ "${README_DRY_RUN}" == "1" ]]; then
			mode="release-wp-readme-dry-run"
		fi
		exec env KAZUS_WPORG_USERNAME="${KAZUS_WPORG_USERNAME}" KAZUS_WPORG_PASSWORD="${KAZUS_WPORG_PASSWORD}" KAZUS_RELEASE_PUBLISH=1 \
			make -C "${REPO_ROOT}" "${mode}" README_FILE="${README_FILE}" WPORG_ASSETS_DIR="${ASSETS_DIR}"
		;;
esac
