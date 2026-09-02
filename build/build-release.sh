#!/usr/bin/env bash
# Build distributable ZIPs for KAZCODE Universal Storage core and Pro add-on.
# Staging + PHP-Scoper isolation; working tree dev vendor stays untouched.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Both of these are optional context that only exists inside the private
# combined monorepo (the apps/ docker QA rig, and the sibling Pro plugin
# directory) — this same script also has to run standalone from the public
# Free-only source repository, where neither exists. Never let a plain `cd`
# into a nonexistent path abort the whole build under `set -e`.
REPO_ROOT=""
if [[ -d "${ROOT}/../../../.." ]]; then
	REPO_ROOT="$(cd "${ROOT}/../../../.." && pwd)"
fi
PRO_ROOT=""
if [[ -d "${ROOT}/../kazcode-universal-storage-pro" ]]; then
	PRO_ROOT="$(cd "${ROOT}/../kazcode-universal-storage-pro" && pwd)"
fi
DIST="${ROOT}/dist"
STAGE="${DIST}/.stage-build"
VERSION="$(grep -m1 "KAZUS_VERSION" "${ROOT}/kazcode-universal-storage.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")"
SCOPER_BIN="${ROOT}/vendor/bin/php-scoper"

CORE_SLUG="kazcode-universal-storage"
PRO_SLUG="kazcode-universal-storage-pro"
CORE_ZIP="${DIST}/${CORE_SLUG}-${VERSION}.zip"
PRO_ZIP="${DIST}/${PRO_SLUG}-${VERSION}.zip"

cleanup() {
	rm -rf "${STAGE}"
}
trap cleanup EXIT

if ! command -v zip >/dev/null 2>&1; then
	echo "ERROR: zip command is required (install zip package)." >&2
	exit 1
fi

if [[ ! -x "${SCOPER_BIN}" ]]; then
	echo "ERROR: php-scoper not found. Run composer install in the plugin directory." >&2
	exit 1
fi

if command -v php >/dev/null 2>&1; then
	PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
	# PHP-Scoper silently no-ops (reports success, prefixes nothing, no error
	# unless run with -vv) under PHP 8.5+: SplObjectStorage::attach() became
	# deprecated in 8.5 and php-scoper's own error handler turns that
	# deprecation into a fatal exception deep inside nikic/php-parser.
	# https://github.com/humbug/php-scoper/issues/1139 — always run the
	# scoping step itself on PHP 8.3 or 8.4, regardless of what the rest of
	# this script uses for composer/tests.
	case "${PHP_MAJOR_MINOR}" in
		8.3|8.4) ;;
		*)
			echo "ERROR: php-scoper must be run under PHP 8.3 or 8.4 (found PHP ${PHP_MAJOR_MINOR}) — it silently produces an unscoped, broken build under PHP 8.5+ with no visible error. See https://github.com/humbug/php-scoper/issues/1139" >&2
			exit 1
			;;
	esac
fi

container_rel_path() {
	local path="$1"
	if [[ "${path}" == "${REPO_ROOT}/app/"* ]]; then
		echo "${path#${REPO_ROOT}/app/}"
	else
		echo "${path}"
	fi
}

run_php() {
	if command -v php >/dev/null 2>&1; then
		php "$@"
	elif [[ -f "${REPO_ROOT}/docker-compose.yml" ]]; then
		local script="$1"
		shift
		local rel
		rel="$(container_rel_path "${script}")"
		local args=()
		for arg in "$@"; do
			args+=( "$(container_rel_path "${arg}")" )
		done
		( cd "${REPO_ROOT}" && docker compose exec -T php php "${rel}" "${args[@]}" )
	else
		echo "ERROR: php not found." >&2
		exit 1
	fi
}

composer_in_stage() {
	local docker_rel="${STAGE#${REPO_ROOT}/app/}"
	if command -v composer >/dev/null 2>&1; then
		( cd "${STAGE}/${CORE_SLUG}" && composer install --no-dev --optimize-autoloader --no-interaction )
	elif [[ -f "${REPO_ROOT}/docker-compose.yml" ]]; then
		( cd "${REPO_ROOT}" && docker compose exec -T php bash -c "cd ${docker_rel}/${CORE_SLUG} && composer install --no-dev --optimize-autoloader --no-interaction" )
	else
		echo "ERROR: composer not found and docker compose unavailable." >&2
		exit 1
	fi
}

run_php_in_stage() {
	local script="$1"
	run_php "${ROOT}/build/${script}" "${STAGE}/${CORE_SLUG}"
}

scope_in_stage() {
	echo "==> PHP-Scoper prefix (Kazcode\\\\WpStorage\\\\Vendor)"
	local docker_rel
	docker_rel="$(container_rel_path "${STAGE}/${CORE_SLUG}")"
	local container_scoper="/var/www/html/wp-content/plugins/kazcode-universal-storage/vendor/bin/php-scoper"

	(
		cd "${STAGE}/${CORE_SLUG}"
		rm -rf build/scoped
		if command -v php >/dev/null 2>&1; then
			"${SCOPER_BIN}" add-prefix --force --output-dir=build/scoped
		elif [[ -f "${REPO_ROOT}/docker-compose.yml" ]]; then
			( cd "${REPO_ROOT}" && docker compose exec -T php bash -c "cd ${docker_rel} && ${container_scoper} add-prefix --force --output-dir=build/scoped" )
		else
			echo "ERROR: php required to run php-scoper." >&2
			exit 1
		fi
		rm -rf vendor includes
		mv build/scoped/vendor vendor
		mv build/scoped/includes includes
		rm -rf build/scoped
		cp "${ROOT}/build/scoped-vendor-bootstrap.php" vendor/kazcode-scoped.php
	)
}

mkdir -p "${DIST}"
rm -rf "${STAGE}"
mkdir -p "${STAGE}"

echo "==> Sync readme.txt version"
run_php "${ROOT}/build/sync-readme-version.php"

echo "==> Staging ${CORE_SLUG} for release"
rsync -a \
	--exclude='.phpunit.cache' \
	--exclude='dist' \
	--exclude='.git' \
	"${ROOT}/" "${STAGE}/${CORE_SLUG}/"

echo "==> Composer production install (staging only)"
composer_in_stage

echo "==> Scope vendor + patch includes"
scope_in_stage

echo "==> Repair scoped Composer autoload maps"
run_php_in_stage repair-scoped-composer-autoload.php

echo "==> Collect third-party licenses"
run_php_in_stage collect-licenses.php

# build/ and scoper.inc.php are NOT shipped: Plugin Check flags build/*.sh as
# disallowed "application files" (application_detected) and the standalone
# build/*.php scripts as if they were runtime plugin code (missing ABSPATH
# guard, direct fwrite()/file_put_contents(), unprefixed globals) even though
# they never execute inside WordPress — verified empirically, not assumed.
# Readable-source/build-tool disclosure is instead satisfied by pointing to
# the development location, per docs/FREE-PRO-CODE-AUDIT.md's "Source/build
# tooling disclosure" section.
rm -rf "${STAGE}/${CORE_SLUG}/tests" \
	"${STAGE}/${CORE_SLUG}/docs" \
	"${STAGE}/${CORE_SLUG}/build" \
	"${STAGE}/${CORE_SLUG}/vendor/bin" \
	"${STAGE}/${CORE_SLUG}/phpunit.xml.dist" \
	"${STAGE}/${CORE_SLUG}/composer.lock" \
	"${STAGE}/${CORE_SLUG}/scoper.inc.php" \
	"${STAGE}/${CORE_SLUG}/.gitignore" \
	"${STAGE}/${CORE_SLUG}/BUILD.md" \
	"${STAGE}/${CORE_SLUG}/test-product-features-local.php"
find "${STAGE}/${CORE_SLUG}" -maxdepth 1 -name 'docker-compose*.yml' -delete 2>/dev/null || true

# aws-crt-php ships this Python formatter helper in the upstream package, but
# the WordPress plugin runtime never calls it and WordPress.org does not allow
# Python application files inside distributed plugin ZIPs.
rm -f "${STAGE}/${CORE_SLUG}/vendor/aws/aws-crt-php/format-check.py"

echo "==> Packaging ${CORE_SLUG}-${VERSION}.zip"
(
	cd "${STAGE}"
	rm -f "${CORE_ZIP}"
	zip -rq "${CORE_ZIP}" "${CORE_SLUG}"
)

echo "==> Verify scoped core ZIP"
bash "${ROOT}/build/verify-scoped-build.sh" "${CORE_ZIP}"

if [[ -d "${PRO_ROOT}" ]]; then
	echo "==> Packaging ${PRO_SLUG}-${VERSION}.zip"
	# vendor/ mixes composer's dev-only install (PHPUnit, php-scoper, etc. —
	# excluded below) with the manually-vendored Freemius SDK at
	# vendor/freemius/, which IS runtime-required and must ship. The
	# include rules must precede the vendor exclude for rsync's
	# first-match-wins filter order to keep just that one subdirectory.
	# docs/ and the plugin-root README.md are internal engineering
	# documentation (architecture notes, Freemius secret-handling
	# procedures) — never customer/runtime content. readme.txt is the
	# real customer/package metadata file Freemius (and WordPress) parse,
	# and is NOT excluded here, so it ships normally.
	rsync -a \
		--exclude='.git' \
		--exclude='.gitignore' \
		--exclude='.phpunit.cache' \
		--exclude='dist' \
		--exclude='tests' \
		--exclude='/docs' \
		--exclude='/README.md' \
		--include='vendor/' \
		--include='vendor/freemius/' \
		--include='vendor/freemius/**' \
		--exclude='vendor/*' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='phpunit.xml.dist' \
		"${PRO_ROOT}/" "${STAGE}/${PRO_SLUG}/"
	(
		cd "${STAGE}"
		rm -f "${PRO_ZIP}"
		zip -rq "${PRO_ZIP}" "${PRO_SLUG}"
	)
else
	echo "WARN: Pro plugin directory not found at ${PRO_ROOT}; skipping Pro ZIP."
fi

echo "Done:"
echo "  ${CORE_ZIP}"
if [[ -f "${PRO_ZIP}" ]]; then
	echo "  ${PRO_ZIP}"
fi
