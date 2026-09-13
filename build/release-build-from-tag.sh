#!/usr/bin/env bash
# Build a release ZIP from an exact Git tag/commit in a temporary detached
# worktree. This prevents accidental releases from dirty or ad hoc source.
set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
	echo "Usage: $0 VERSION TAG [EXPECTED_COMMIT]" >&2
	exit 2
fi

VERSION="$1"
TAG="$2"
EXPECTED_COMMIT="${3:-}"
export COMPOSER_ROOT_VERSION="${KAZUS_COMPOSER_ROOT_VERSION:-1.0.0+no-version-set}"

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(git -C "${PLUGIN_ROOT}" rev-parse --show-toplevel)"
PLUGIN_PREFIX="$(git -C "${PLUGIN_ROOT}" rev-parse --show-prefix)"
PLUGIN_PREFIX="${PLUGIN_PREFIX%/}"
TMP_PARENT="${TMPDIR:-/tmp}"
TMP_WORKTREE="$(mktemp -d "${TMP_PARENT}/kazus-release-${VERSION}.XXXXXX")"

cleanup() {
	git -C "${REPO_ROOT}" worktree remove --force "${TMP_WORKTREE}" >/dev/null 2>&1 || rm -rf "${TMP_WORKTREE}"
}
trap cleanup EXIT

if [[ "${KAZUS_RELEASE_SKIP_FETCH:-0}" != "1" ]]; then
	git -C "${REPO_ROOT}" fetch --tags --quiet
fi
git -C "${REPO_ROOT}" worktree add --detach "${TMP_WORKTREE}" "${TAG}" >/dev/null

TAG_COMMIT="$(git -C "${TMP_WORKTREE}" rev-parse HEAD)"
if [[ -n "${EXPECTED_COMMIT}" && "${TAG_COMMIT}" != "${EXPECTED_COMMIT}" ]]; then
	echo "ERROR: ${TAG} resolves to ${TAG_COMMIT}, expected ${EXPECTED_COMMIT}" >&2
	exit 1
fi

if [[ -n "${PLUGIN_PREFIX}" ]]; then
	BUILD_ROOT="${TMP_WORKTREE}/${PLUGIN_PREFIX}"
else
	BUILD_ROOT="${TMP_WORKTREE}"
fi

php "${PLUGIN_ROOT}/build/release-integrity.php" preflight \
	--source "${BUILD_ROOT}" \
	--version "${VERSION}" \
	--tag "${TAG}" \
	--commit "${TAG_COMMIT}"

# Release source comes from the tag, but release tooling comes from the
# operator checkout. build/ is excluded from the production package, so this
# lets improved verifiers/build safeguards reproduce historical tags without
# changing shipped runtime bytes.
rsync -a --delete "${PLUGIN_ROOT}/build/" "${BUILD_ROOT}/build/"

(
	cd "${BUILD_ROOT}"
	composer install --prefer-dist --no-interaction
	bash build/build-release.sh
	CORE_ZIP="dist/kazcode-universal-storage-${VERSION}.zip"
	php "${PLUGIN_ROOT}/build/release-integrity.php" package --package "${CORE_ZIP}" --version "${VERSION}"
	php "${PLUGIN_ROOT}/build/release-integrity.php" manifest --path "${CORE_ZIP}" --output "dist/kazcode-universal-storage-${VERSION}.manifest.sha256"
	PRO_ZIP="$(find dist -maxdepth 1 -name 'kazcode-universal-storage-pro-*.zip' -print | sort | head -n 1)"
	if [[ -n "${PRO_ZIP}" ]]; then
		php "${PLUGIN_ROOT}/build/release-integrity.php" manifest --path "${PRO_ZIP}" --output "${PRO_ZIP%.zip}.manifest.sha256"
	fi
)

mkdir -p "${PLUGIN_ROOT}/dist"
cp "${BUILD_ROOT}/dist/kazcode-universal-storage-${VERSION}.zip" "${PLUGIN_ROOT}/dist/"
cp "${BUILD_ROOT}/dist/kazcode-universal-storage-${VERSION}.manifest.sha256" "${PLUGIN_ROOT}/dist/"
PRO_ZIP="$(find "${BUILD_ROOT}/dist" -maxdepth 1 -name 'kazcode-universal-storage-pro-*.zip' -print | sort | head -n 1)"
if [[ -n "${PRO_ZIP}" ]]; then
	cp "${PRO_ZIP}" "${PLUGIN_ROOT}/dist/"
	cp "${PRO_ZIP%.zip}.manifest.sha256" "${PLUGIN_ROOT}/dist/"
fi

echo "Built from ${TAG} (${TAG_COMMIT}):"
sha256sum "${PLUGIN_ROOT}/dist/kazcode-universal-storage-${VERSION}.zip"
sha256sum "${PLUGIN_ROOT}/dist/kazcode-universal-storage-${VERSION}.manifest.sha256"
PRO_ZIP="$(find "${PLUGIN_ROOT}/dist" -maxdepth 1 -name 'kazcode-universal-storage-pro-*.zip' -print | sort | head -n 1)"
if [[ -n "${PRO_ZIP}" ]]; then
	sha256sum "${PRO_ZIP}"
	sha256sum "${PRO_ZIP%.zip}.manifest.sha256"
fi
