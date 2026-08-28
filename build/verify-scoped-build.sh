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

if ! grep -q 'class_alias' "${ROOT}/vendor/kazcode-scoped.php"; then
	fail "vendor/kazcode-scoped.php is missing the Aws\\ -> Kazcode\\WpStorage\\Vendor\\Aws\\ class_alias bridge"
fi

# The definitive check, robust either way PHP-Scoper handled includes/ (real
# per-file rewrite of `use Aws\...`, or left as-is and covered by the
# class_alias bridge in vendor/kazcode-scoped.php): after loading the shipped
# autoloader + bootstrap, the plugin's own `Aws\S3\S3Client` reference must
# resolve to the genuinely scoped class, not a nonexistent/real one.
RESOLVED_CLASS="$(php -r '
if ( ! defined( "ABSPATH" ) ) {
	define( "ABSPATH", "/tmp/" ); // kazcode-scoped.php no-ops outside WordPress without this
}
require $argv[1] . "/vendor/autoload.php";
require $argv[1] . "/vendor/kazcode-scoped.php";
if ( ! class_exists( "Aws\\S3\\S3Client", false ) ) {
	fwrite( STDERR, "Aws\\S3\\S3Client did not resolve at all after loading the scoped bootstrap.\n" );
	exit( 1 );
}
echo ( new ReflectionClass( "Aws\\S3\\S3Client" ) )->getName();
' "${ROOT}" 2>&1)" || fail "class_alias bridge did not make Aws\\S3\\S3Client resolvable: ${RESOLVED_CLASS}"

if [[ "${RESOLVED_CLASS}" != "Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client" ]]; then
	fail "Aws\\S3\\S3Client resolved to '${RESOLVED_CLASS}', not the scoped Kazcode\\WpStorage\\Vendor\\Aws\\S3\\S3Client — release ZIP would run the unscoped/real SDK class if one ever ended up on the load path"
fi

if [[ -d "${ROOT}/tests" ]]; then
	fail "tests/ must not ship in release ZIP"
fi

if [[ -d "${ROOT}/vendor/bin" ]]; then
	fail "vendor/bin must not ship in release ZIP"
fi

echo "OK: scoped release ZIP verified — ${ZIP}"
