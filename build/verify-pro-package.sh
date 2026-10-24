#!/usr/bin/env bash
# Fail closed on the minimal Pro package metadata required by Freemius.
set -euo pipefail

ZIP="${1:?Usage: verify-pro-package.sh /path/to/kazcode-universal-storage-pro-VERSION.zip}"
SLUG="kazcode-universal-storage-pro"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

[[ -f "${ZIP}" ]] || fail "Pro ZIP not found: ${ZIP}"
command -v unzip >/dev/null 2>&1 || fail "unzip is required to verify the Pro ZIP"

MANIFEST="$(unzip -Z1 "${ZIP}")" || fail "unable to read Pro ZIP manifest: ${ZIP}"
# With pipefail, grep -q can close a pipeline early and make printf report a
# broken pipe even when the required entry is present. Feed the captured
# manifest directly so this remains fail-closed without that false failure.
grep -Fxq "${SLUG}/readme.txt" <<< "${MANIFEST}" || fail "Pro ZIP is missing ${SLUG}/readme.txt"

echo "OK: Pro ZIP contains ${SLUG}/readme.txt"
