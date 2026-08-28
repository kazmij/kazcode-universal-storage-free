<?php
/**
 * PHP-Scoper configuration for release builds (v2 Phase 10).
 *
 * Prefixes AWS SDK + transitive deps under Kazcode\WpStorage\Vendor while
 * keeping Kazcode\WpStorage plugin code in the global namespace.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'                  => 'Kazcode\\WpStorage\\Vendor',
	'finders'                 => array(
		Finder::create()->files()->ignoreVCS( true )->in( 'vendor' ),
		Finder::create()
			->files()
			->ignoreVCS( true )
			->in( 'includes' )
			->exclude( array( 'tests' ) ),
	),
	'exclude-namespaces'      => array(
		'Kazcode\WpStorage',
		// Composer's own generated autoload plumbing (ClassLoader,
		// InstalledVersions, ...) must stay unprefixed — vendor/autoload.php
		// and vendor/composer/autoload_real.php reference these classes both
		// as static tokens and via dynamically-built strings (the latter
		// isn't rewritable by static analysis at all), so scoping this
		// namespace breaks the autoload bootstrap chain itself.
		'Composer',
	),
	'exclude-classes'         => array(
		// autoload_real.php's global ComposerAutoloaderInit<hash> class is
		// referenced via runtime string concatenation ('ComposerAutoloaderInit'
		// . $hash) for spl_autoload_unregister(), which static analysis can't
		// see through — excluding the class itself keeps both references
		// (the static one and the dynamic one) pointing at the same name.
		'/^ComposerAutoloaderInit.*$/',
		// WordPress core classes referenced from includes/ (\WP_CLI, \WP_Error,
		// \WP_Post, \WP_Query, \WP_REST_Request, \WP_REST_Response, ...): these
		// are global-namespace classes with no source file under vendor/ or
		// includes/ for PHP-Scoper to find, so — unlike real PHP built-ins,
		// which it already knows about — it otherwise treats any unrecognized
		// global class name as fair game to prefix. WordPress provides these
		// at runtime; prefixing the reference makes it unresolvable.
		'/^WP_.*$/',
		'wpdb',
	),
	'exclude-files'           => array(),
	'expose-global-functions' => true,
	'expose-global-constants' => true,
	'expose-global-classes'   => false,
	'expose-namespaces'       => array(),
	'patchers'                => array(
		// aws/aws-sdk-php's AwsClient::parseClass() builds its per-service
		// exception FQCN by string-interpolating a *hardcoded* "Aws\\" root
		// (`"Aws\\{$service}\\Exception\\{$service}Exception"`) around a
		// service name derived via get_class($this) at runtime — so the
		// service name itself tracks whatever prefix is actually in effect,
		// but the "Aws\\" literal never does. That's invisible to static
		// analysis (it's built purely from a runtime string, not a symbol
		// reference), so every AWS call throwing a service exception
		// (e.g. HeadBucket 403/404) fatals with "Class Aws\S3\Exception\
		// S3Exception not found" in a scoped build. Prepend the real prefix
		// to that one literal.
		static function ( string $filePath, string $prefix, string $contents ): string {
			if ( substr( $filePath, -strlen( '/aws/aws-sdk-php/src/AwsClient.php' ) ) !== '/aws/aws-sdk-php/src/AwsClient.php' ) {
				return $contents;
			}
			return str_replace(
				'"Aws\\\\{$service}\\\\Exception\\\\{$service}Exception"',
				'"' . $prefix . '\\\\Aws\\\\{$service}\\\\Exception\\\\{$service}Exception"',
				$contents
			);
		},
		// PHP-Scoper's string-literal scanner treats ANY backslash-containing
		// string as a possible FQCN reference and prefixes it — including
		// SignatureV4::ISO8601_BASIC's `gmdate()` format string 'Ymd\THis\Z',
		// where the backslashes are date()-format literal-character escapes,
		// not namespace separators. That corrupts every SigV4 request's
		// X-Amz-Date header into garbage, breaking auth against every
		// provider (real AWS included — confirmed live against a real S3
		// bucket, not just MinIO). Reverse the false-positive prefix on this
		// one constant.
		static function ( string $filePath, string $prefix, string $contents ): string {
			if ( substr( $filePath, -strlen( '/aws/aws-sdk-php/src/Signature/SignatureV4.php' ) ) !== '/aws/aws-sdk-php/src/Signature/SignatureV4.php' ) {
				return $contents;
			}
			return str_replace(
				"'" . $prefix . '\\Ymd\\THis\\Z' . "'",
				"'Ymd\\THis\\Z'",
				$contents
			);
		},
	),
);
