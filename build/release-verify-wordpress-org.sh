#!/usr/bin/env bash
# Verify a public WordPress.org ZIP against the local release ZIP.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/release-verify-wordpress-org.sh --version VERSION [--zip ZIP]
  build/release-verify-wordpress-org.sh --version VERSION [--zip ZIP] --container CONTAINER

Purpose:
  Download the versioned WordPress.org ZIP and run the canonical
  release-integrity.php post-publish verifier against the local release ZIP.

Options:
  --zip ZIP             default: dist/kazcode-universal-storage-VERSION.zip
  --container NAME      run verifier inside an explicit Docker/Podman container
  --runtime docker|podman
                        default: docker

Container mode:
  Use this only when the host cannot run PHP locally. The script copies the
  local ZIP, public ZIP, and release-integrity.php into /tmp inside the named
  container, then runs the same canonical verifier there. The container must
  have php and unzip available.

Dry-run behavior:
  Verification is read-only and performs no remote mutation; no separate
  dry-run mode is required.
USAGE
}

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

VERSION=""
ZIP_PATH=""
CONTAINER=""
RUNTIME="docker"

while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="${2:-}"; shift 2 ;;
		--zip) ZIP_PATH="${2:-}"; shift 2 ;;
		--container) CONTAINER="${2:-}"; shift 2 ;;
		--runtime) RUNTIME="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) usage >&2; fail "unknown argument: $1" ;;
	esac
done

[[ -n "${VERSION}" ]] || { usage >&2; fail "--version is required"; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_PATH="${ZIP_PATH:-${ROOT}/dist/kazcode-universal-storage-${VERSION}.zip}"
INTEGRITY_SCRIPT="${ROOT}/build/release-integrity.php"
PUBLIC_URL="https://downloads.wordpress.org/plugin/kazcode-universal-storage.${VERSION}.zip"
PUBLIC_ZIP="${TMPDIR:-/tmp}/kazcode-universal-storage.${VERSION}.zip"

[[ -f "${ZIP_PATH}" ]] || fail "local ZIP not found: ${ZIP_PATH}"
[[ -f "${INTEGRITY_SCRIPT}" ]] || fail "release-integrity.php not found: ${INTEGRITY_SCRIPT}"
command -v curl >/dev/null 2>&1 || fail "curl is required to download ${PUBLIC_URL}"

curl -fsSL "${PUBLIC_URL}" -o "${PUBLIC_ZIP}"

if [[ -n "${CONTAINER}" ]]; then
	command -v "${RUNTIME}" >/dev/null 2>&1 || fail "${RUNTIME} is required for --container"
	"${RUNTIME}" inspect "${CONTAINER}" >/dev/null 2>&1 || fail "container not found or not running: ${CONTAINER}"
	"${RUNTIME}" exec "${CONTAINER}" sh -c 'command -v php >/dev/null 2>&1 && command -v unzip >/dev/null 2>&1' \
		|| fail "container ${CONTAINER} must provide php and unzip"

	remote_dir="/tmp/kazus-release-verify-${VERSION}-$$"
	"${RUNTIME}" exec "${CONTAINER}" sh -c "rm -rf '${remote_dir}' && mkdir -p '${remote_dir}'"
	"${RUNTIME}" cp "${ZIP_PATH}" "${CONTAINER}:${remote_dir}/local.zip"
	"${RUNTIME}" cp "${PUBLIC_ZIP}" "${CONTAINER}:${remote_dir}/public.zip"
	"${RUNTIME}" cp "${INTEGRITY_SCRIPT}" "${CONTAINER}:${remote_dir}/release-integrity.php"
	"${RUNTIME}" exec "${CONTAINER}" php "${remote_dir}/release-integrity.php" post-publish \
		--local-zip "${remote_dir}/local.zip" \
		--public-zip "${remote_dir}/public.zip" \
		--version "${VERSION}"
	"${RUNTIME}" exec "${CONTAINER}" sh -c "rm -rf '${remote_dir}'" >/dev/null 2>&1 || true
else
	command -v php >/dev/null 2>&1 || fail "php is required; rerun through make release-verify-wp or pass --container"
	command -v unzip >/dev/null 2>&1 || fail "unzip is required"
	php "${INTEGRITY_SCRIPT}" post-publish \
		--local-zip "${ZIP_PATH}" \
		--public-zip "${PUBLIC_ZIP}" \
		--version "${VERSION}"
fi

sha256sum "${PUBLIC_ZIP}"
