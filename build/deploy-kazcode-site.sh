#!/usr/bin/env bash
# Build/verify/deploy kazcode.net Universal Storage site through the existing static-site architecture.
set -euo pipefail

usage() {
	cat <<'USAGE'
Usage:
  build/deploy-kazcode-site.sh --version VERSION --dry-run
  build/deploy-kazcode-site.sh --version VERSION --deploy
  build/deploy-kazcode-site.sh --version VERSION --verify URL

Required env for deploy:
  KAZUS_SITE_ROOT        default: /home/kazmij/www/kazcode/company
  KAZUS_SITE_SSH_TARGET  production SSH host
  KAZUS_SITE_REMOTE_PATH production company repo path
  KAZUS_RELEASE_PUBLISH=1

Dry-run behavior:
  Verifies site source status, builds locally with existing npm/Docker flow when
  available, runs link checks, and prints the remote deploy command.
USAGE
}

VERSION=""
MODE=""
VERIFY_URL=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--version) VERSION="${2:-}"; shift 2 ;;
		--dry-run) MODE="dry-run"; shift ;;
		--deploy) MODE="deploy"; shift ;;
		--verify) MODE="verify"; VERIFY_URL="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "ERROR: unknown argument: $1" >&2; usage >&2; exit 2 ;;
	esac
done
[[ -n "${VERSION}" && -n "${MODE}" ]] || { usage >&2; exit 2; }

SITE_ROOT="${KAZUS_SITE_ROOT:-/home/kazmij/www/kazcode/company}"
SITE_SRC="${SITE_ROOT}/universal-storage-src"
[[ -d "${SITE_SRC}" ]] || { echo "ERROR: site source not found: ${SITE_SRC}" >&2; exit 1; }

if [[ "${MODE}" == "verify" ]]; then
	[[ -n "${VERIFY_URL}" ]] || { usage >&2; exit 2; }
	for path in "/" "/universal-storage/" "/universal-storage/docs/" "/universal-storage/changelog/" "/universal-storage/support/"; do
		curl -fsSL -o /dev/null "${VERIFY_URL%/}${path}"
		echo "OK ${VERIFY_URL%/}${path}"
	done
	exit 0
fi

status="$(git -C "${SITE_ROOT}" status --short --untracked-files=all)"
if [[ -n "${status}" ]]; then
	echo "ERROR: kazcode.net site repo is not clean:" >&2
	echo "${status}" >&2
	exit 1
fi

if command -v npm >/dev/null 2>&1; then
	( cd "${SITE_SRC}" && npm run build && npm run check-links )
else
	( cd "${SITE_ROOT}" && docker compose build && docker compose run --rm build )
	( cd "${SITE_SRC}" && docker run --rm -v "${SITE_ROOT}:/company" -w /company/universal-storage-src node:22-alpine node scripts/check-links.mjs )
fi

if [[ "${MODE}" == "dry-run" ]]; then
	echo "DRY RUN: site build/link verification passed for ${VERSION}."
	echo "Would deploy with: ssh \$KAZUS_SITE_SSH_TARGET 'cd \$KAZUS_SITE_REMOTE_PATH && git pull --ff-only && docker compose build && docker compose run --rm build'"
	exit 0
fi

[[ "${KAZUS_RELEASE_PUBLISH:-0}" == "1" ]] || { echo "ERROR: set KAZUS_RELEASE_PUBLISH=1 for deploy." >&2; exit 1; }
[[ -n "${KAZUS_SITE_SSH_TARGET:-}" && -n "${KAZUS_SITE_REMOTE_PATH:-}" ]] || {
	echo "ERROR: KAZUS_SITE_SSH_TARGET and KAZUS_SITE_REMOTE_PATH are required." >&2
	exit 1
}
[[ "${KAZUS_SITE_REMOTE_PATH}" != *"'"* ]] || { echo "ERROR: KAZUS_SITE_REMOTE_PATH must not contain single quotes." >&2; exit 1; }

ssh "${KAZUS_SITE_SSH_TARGET}" "cd '${KAZUS_SITE_REMOTE_PATH}' && git pull --ff-only && docker compose build && docker compose run --rm build"
