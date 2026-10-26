#!/usr/bin/env bash
# Fail closed when the vendored Pro Freemius SDK is not the latest stable release.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage: build/verify-freemius-sdk.sh [--pro-dir DIR] [--latest-version VERSION]

Without --latest-version, obtains the latest stable WordPress SDK tag from the
official Freemius GitHub releases API. --latest-version exists for deterministic
tests only; production release commands must not pass it.
USAGE
}

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRO_DIR="$(cd "${SCRIPT_DIR}/../../kazcode-universal-storage-pro" && pwd)"
LATEST_VERSION=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--pro-dir) PRO_DIR="${2:-}"; shift 2 ;;
		--latest-version) LATEST_VERSION="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) usage >&2; fail "unknown argument: $1" ;;
	esac
done

START="${PRO_DIR}/vendor/freemius/start.php"
[[ -f "${START}" ]] || fail "vendored Freemius SDK bootstrap not found: ${START}"

LOCAL_VERSION="$(sed -nE "s/^[[:space:]]*\\\$this_sdk_version = '([0-9]+\\.[0-9]+\\.[0-9]+)'.*/\\1/p" "${START}" | head -n 1)"
[[ -n "${LOCAL_VERSION}" ]] || fail "could not read the vendored Freemius SDK version from ${START}"

if [[ -z "${LATEST_VERSION}" ]]; then
	command -v curl >/dev/null 2>&1 || fail "curl is required to verify the latest Freemius SDK release"
	LATEST_VERSION="$(curl -fsSL --retry 2 --connect-timeout 15 https://api.github.com/repos/Freemius/wordpress-sdk/releases/latest | sed -nE 's/^[[:space:]]*"tag_name"[[:space:]]*:[[:space:]]*"([0-9]+\.[0-9]+\.[0-9]+)".*/\1/p' | head -n 1)"
	[[ -n "${LATEST_VERSION}" ]] || fail "could not determine the latest stable Freemius SDK version from the official releases API"
fi

[[ "${LATEST_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "latest Freemius SDK version must be a stable x.y.z tag"

if [[ "${LOCAL_VERSION}" != "${LATEST_VERSION}" ]]; then
	fail "vendored Freemius SDK is ${LOCAL_VERSION}; latest stable is ${LATEST_VERSION}; update the vendored SDK before releasing Pro."
fi

echo "Freemius SDK version verified: ${LOCAL_VERSION}"
