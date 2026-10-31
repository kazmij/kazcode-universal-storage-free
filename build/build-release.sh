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
SCOPER_MEMORY_LIMIT="${SCOPER_MEMORY_LIMIT:-1024M}"
COMPOSER_AUTOLOADER_SUFFIX="${KAZUS_COMPOSER_AUTOLOADER_SUFFIX:-8cdd760b077ab5b8842437bb000793cb}"
# A release ZIP is a public artifact, not merely an installation transport.
# Derive its timestamp from immutable source history so two builds of the same
# tag cannot silently create differently hashed artifacts due to staging,
# Composer or PHP-Scoper write times.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT}" log -1 --format=%ct HEAD)}"
[[ "${SOURCE_DATE_EPOCH}" =~ ^[0-9]+$ ]] || { echo "ERROR: SOURCE_DATE_EPOCH must be a Unix timestamp." >&2; exit 1; }

CORE_SLUG="kazcode-universal-storage"
PRO_SLUG="kazcode-universal-storage-pro"
CORE_ZIP="${DIST}/${CORE_SLUG}-${VERSION}.zip"
PRO_VERSION=""
PRO_ZIP=""
if [[ -n "${PRO_ROOT}" ]]; then
	PRO_VERSION="$(grep -m1 "KAZUS_PRO_VERSION" "${PRO_ROOT}/kazcode-universal-storage-pro.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")"
	PRO_ZIP="${DIST}/${PRO_SLUG}-${PRO_VERSION}.zip"
fi

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
	local composer_json="${STAGE}/${CORE_SLUG}/composer.json"
	local composer_json_backup="${STAGE}/${CORE_SLUG}/composer.json.release-build.bak"
	cp "${composer_json}" "${composer_json_backup}"
	php -r '$file = $argv[1]; $suffix = $argv[2]; $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); $json["config"]["autoloader-suffix"] = $suffix; file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);' "${composer_json}" "${COMPOSER_AUTOLOADER_SUFFIX}"
	if command -v composer >/dev/null 2>&1; then
		( cd "${STAGE}/${CORE_SLUG}" && composer install --no-dev --optimize-autoloader --no-interaction )
	elif [[ -f "${REPO_ROOT}/docker-compose.yml" ]]; then
		( cd "${REPO_ROOT}" && docker compose exec -T php bash -c "cd ${docker_rel}/${CORE_SLUG} && composer install --no-dev --optimize-autoloader --no-interaction" )
	else
		echo "ERROR: composer not found and docker compose unavailable." >&2
		exit 1
	fi
	mv "${composer_json_backup}" "${composer_json}"
}

run_php_in_stage() {
	local script="$1"
	run_php "${ROOT}/build/${script}" "${STAGE}/${CORE_SLUG}"
}

normalize_archive_metadata() {
	local directory="$1"
	find "${directory}" -exec touch -h -d "@${SOURCE_DATE_EPOCH}" {} +
}

scope_in_stage() {
	echo "==> PHP-Scoper prefix (Kazcode\\\\WpStorage\\\\Vendor)"
	local docker_rel
	docker_rel="$(container_rel_path "${STAGE}/${CORE_SLUG}")"
	local container_scoper="/var/www/html/wp-content/plugins/kazcode-universal-storage/vendor/bin/php-scoper"

	(
		cd "${STAGE}/${CORE_SLUG}"
		rm -rf build/scoped
		# PHP-Scoper 0.18's transitive Safe 2.x dependency emits a PHP 8.4
		# nullable-parameter deprecation while Composer autoloads it. PHP-Scoper
		# promotes every reported deprecation to an exception before its command
		# can run. This is build-tool noise, not a plugin/runtime diagnostic, so
		# suppress only E_DEPRECATED for this one third-party scoping invocation.
		# The generated package and its verification still run normally.
		SCOPER_PHP_ARGS=( -d "memory_limit=${SCOPER_MEMORY_LIMIT}" -d 'error_reporting=E_ALL & ~E_DEPRECATED' )
		if command -v php >/dev/null 2>&1; then
			php "${SCOPER_PHP_ARGS[@]}" "${SCOPER_BIN}" add-prefix --force --output-dir=build/scoped
		elif [[ -f "${REPO_ROOT}/docker-compose.yml" ]]; then
			( cd "${REPO_ROOT}" && docker compose exec -T php bash -c "cd ${docker_rel} && php -d memory_limit=${SCOPER_MEMORY_LIMIT} -d 'error_reporting=E_ALL & ~E_DEPRECATED' ${container_scoper} add-prefix --force --output-dir=build/scoped" )
		else
			echo "ERROR: php required to run php-scoper." >&2
			exit 1
		fi
		rm -rf vendor includes
		mv build/scoped/vendor vendor
		mv build/scoped/includes includes
		rm -rf build/scoped
		cp "${ROOT}/build/scoped-vendor-bootstrap.php" vendor/kazcode-scoped.php
		run_php "${ROOT}/build/prefix-scoped-autoload-file-ids.php" "${STAGE}/${CORE_SLUG}"
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
rm -f "${STAGE}/${CORE_SLUG}/vendor/aws/aws-crt-php/format-check.py"
normalize_archive_metadata "${STAGE}/${CORE_SLUG}"

echo "==> Packaging ${CORE_SLUG}-${VERSION}.zip"
(
	cd "${STAGE}"
	rm -f "${CORE_ZIP}"
	LC_ALL=C find "${CORE_SLUG}" -print | LC_ALL=C sort | zip -X -q "${CORE_ZIP}" -@
)

echo "==> Verify scoped core ZIP"
bash "${ROOT}/build/verify-scoped-build.sh" "${CORE_ZIP}"

if [[ -d "${PRO_ROOT}" ]]; then
	echo "==> Packaging ${PRO_SLUG}-${PRO_VERSION}.zip"
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
	normalize_archive_metadata "${STAGE}/${PRO_SLUG}"
	(
		cd "${STAGE}"
		rm -f "${PRO_ZIP}"
		LC_ALL=C find "${PRO_SLUG}" -print | LC_ALL=C sort | zip -X -q "${PRO_ZIP}" -@
	)
	# Keep the Freemius-required package metadata check with the packager, not
	# only in CI: a manually run release build must fail before its ZIP can be
	# mistaken for a valid Pro artifact.
	bash "${ROOT}/build/verify-pro-package.sh" "${PRO_ZIP}"
else
	echo "WARN: Pro plugin directory not found at ${PRO_ROOT}; skipping Pro ZIP."
fi

echo "Done:"
echo "  ${CORE_ZIP}"
if [[ -f "${PRO_ZIP}" ]]; then
	echo "  ${PRO_ZIP}"
fi
