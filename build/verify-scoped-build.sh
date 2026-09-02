#!/usr/bin/env bash
# Verify a release ZIP contains scoped vendor + patched includes.
set -euo pipefail

ZIP="${1:-}"
if [[ -z "${ZIP}" || ! -f "${ZIP}" ]]; then
	echo "Usage: $0 path/to/kazcode-universal-storage-VERSION.zip" >&2
	exit 1
fi

TMP="$(mktemp -d)"
cleanup() { rm -rf "${TMP}"; }
trap cleanup EXIT

unzip -q "${ZIP}" -d "${TMP}"
ROOT="${TMP}/kazcode-universal-storage"

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

[[ -f "${ROOT}/vendor/autoload.php" ]] || fail "missing vendor/autoload.php"
[[ -f "${ROOT}/vendor/kazcode-scoped.php" ]] || fail "missing vendor/kazcode-scoped.php"
[[ -f "${ROOT}/THIRD-PARTY-LICENSES.txt" ]] || fail "missing THIRD-PARTY-LICENSES.txt"
[[ -f "${ROOT}/readme.txt" ]] || fail "missing readme.txt"

# vendor/ must genuinely be prefixed — the SDK's own namespace declarations
# must be rewritten, not just copied through.
if grep -rq 'namespace Aws;' "${ROOT}/vendor/aws/aws-sdk-php/src/" 2>/dev/null; then
	fail "vendor/aws-sdk-php still declares the unscoped Aws namespace — vendor/ was not actually prefixed (PHP-Scoper silently no-ops under PHP 8.5+: https://github.com/humbug/php-scoper/issues/1139 — build with PHP 8.3 or 8.4)"
fi

# Composer autoload maps must not route global dependency symbols to KAZCODE's
# scoped files. Otherwise a site that also loads global Aws/Guzzle packages can
# trip duplicate class/trait declarations or receive KAZCODE's private SDK.
AUTOLOAD_RESULT="$(php -r '
if ( ! defined( "ABSPATH" ) ) {
	define( "ABSPATH", "/tmp/" ); // kazcode-scoped.php no-ops outside WordPress without this
}
require $argv[1] . "/vendor/autoload.php";
require $argv[1] . "/vendor/kazcode-scoped.php";
if ( class_exists( "Aws\\S3\\S3Client", false ) ) {
	fwrite( STDERR, "KAZCODE release bootstrap exposed global Aws\\S3\\S3Client.\n" );
	exit( 1 );
}
if ( ! class_exists( "Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client" ) ) {
	fwrite( STDERR, "Scoped Aws\\S3\\S3Client did not resolve.\n" );
	exit( 1 );
}
if ( ! function_exists( "Kazcode\\WpStorage\\Vendor\\Aws\\manifest" ) ) {
	fwrite( STDERR, "Scoped Aws functions.php was not loaded.\n" );
	exit( 1 );
}
$loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
$loader = $loaders[$argv[1] . "/vendor"] ?? null;
if ( ! $loader ) {
	fwrite( STDERR, "Could not find KAZCODE Composer loader.\n" );
	exit( 1 );
}
foreach ( array( "Aws\\S3\\S3Client", "GuzzleHttp\\Client", "Psr\\Http\\Message\\RequestInterface" ) as $class ) {
	if ( $loader->findFile( $class ) ) {
		fwrite( STDERR, "KAZCODE loader still maps global dependency class {$class}.\n" );
		exit( 1 );
	}
}
foreach ( array( "Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client", "Kazcode\\WpStorage\\Vendor\\GuzzleHttp\\Client", "Kazcode\\WpStorage\\Vendor\\Psr\\Http\\Message\\RequestInterface" ) as $class ) {
	if ( ! $loader->findFile( $class ) ) {
		fwrite( STDERR, "KAZCODE loader does not map scoped dependency class {$class}.\n" );
		exit( 1 );
	}
}
echo "ok";
' "${ROOT}" 2>&1)" || fail "scoped Composer autoload isolation check failed: ${AUTOLOAD_RESULT}"

if [[ "${AUTOLOAD_RESULT}" != "ok" ]]; then
	fail "scoped Composer autoload isolation failed: ${AUTOLOAD_RESULT}"
fi

AUTOLOAD_FILE_COLLISION_CHECK="$(php -r '
if ( ! defined( "ABSPATH" ) ) {
	define( "ABSPATH", "/tmp/" );
}
$autoloadFiles = require $argv[1] . "/vendor/composer/autoload_files.php";
foreach ( array_keys( $autoloadFiles ) as $identifier ) {
	$GLOBALS["__composer_autoload_files"][ $identifier ] = true;
}
require $argv[1] . "/vendor/autoload.php";
require $argv[1] . "/vendor/kazcode-scoped.php";
if ( ! function_exists( "Kazcode\\WpStorage\\Vendor\\Aws\\manifest" ) ) {
	fwrite( STDERR, "Kazcode\\WpStorage\\Vendor\\Aws\\manifest is missing when Composer files autoload identifiers collide.\n" );
	exit( 1 );
}
if ( ! function_exists( "Kazcode\\WpStorage\\Vendor\\JmesPath\\search" ) ) {
	fwrite( STDERR, "Kazcode\\WpStorage\\Vendor\\JmesPath\\search is missing when Composer files autoload identifiers collide.\n" );
	exit( 1 );
}
' "${ROOT}" 2>&1)" || fail "Composer files autoload collision guard failed: ${AUTOLOAD_FILE_COLLISION_CHECK}"

if [[ -d "${ROOT}/tests" ]]; then
	fail "tests/ must not ship in release ZIP"
fi

if [[ -d "${ROOT}/vendor/bin" ]]; then
	fail "vendor/bin must not ship in release ZIP"
fi

PY_FILES="$(find "${ROOT}" -type f -name '*.py' -print | sed "s#^${ROOT}/##" | sort)"
if [[ -n "${PY_FILES}" ]]; then
	fail "Python files must not ship in release ZIP: ${PY_FILES}"
fi

echo "OK: scoped release ZIP verified — ${ZIP}"
