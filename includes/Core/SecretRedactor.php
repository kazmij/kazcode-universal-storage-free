<?php
/**
 * Scrubs credential material from provider/SDK messages before they leave PHP.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

/**
 * AWS SDK / S3-compatible error text can echo the request URL (presigned
 * query strings are bearer credentials), the Authorization header, the access
 * key id and signing material (StringToSign, CanonicalRequest). Everything
 * that is returned over REST/CLI, persisted (inventory rows, postmeta,
 * options, audit log) or written to error_log goes through redact() (#74).
 *
 * Error class names and codes (SignatureDoesNotMatch, AccessDenied, timeouts)
 * are kept: RemoteObservation classifies outcomes from that text.
 */
final class SecretRedactor {

	public const MASK = '[REDACTED]';

	/** Default upper bound for any redacted message. */
	public const MAX_LENGTH = 500;

	/**
	 * Signing/credential elements S3-compatible providers put in XML error bodies.
	 */
	private const XML_ELEMENTS = array(
		'AWSAccessKeyId',
		'StringToSign',
		'StringToSignBytes',
		'CanonicalRequest',
		'CanonicalRequestBytes',
		'SignatureProvided',
		'Authorization',
		'Credential',
		'SecurityToken',
	);

	/**
	 * Redact credential material and bound the length.
	 *
	 * @param string|\Throwable $message Message or exception.
	 * @param int               $max     Maximum length of the result (0 = unbounded).
	 */
	public static function redact( $message, int $max = self::MAX_LENGTH ): string {
		$text = $message instanceof \Throwable ? $message->getMessage() : (string) $message;

		$elements = implode( '|', self::XML_ELEMENTS );
		$text     = preg_replace( '#<(' . $elements . ')>.*?</\1>#is', '<$1>' . self::MASK . '</$1>', $text ) ?? $text;

		// Any URL query string: presigned URLs carry X-Amz-Credential/Signature/Security-Token.
		$text = preg_replace( '#(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*#i', '$1?' . self::MASK, $text ) ?? $text;

		// Authorization header values, including AWS4-HMAC-SHA256 Credential=/Signature=.
		$text = preg_replace( '#(Authorization\s*[:=]\s*)[^\r\n"\'<>]+#i', '$1' . self::MASK, $text ) ?? $text;
		$text = preg_replace( '#\bAWS4-HMAC-SHA256\b[^\r\n"\'<>]*#', 'AWS4-HMAC-SHA256 ' . self::MASK, $text ) ?? $text;

		// Loose key=value / key: value pairs (headers, query params outside URLs, debug dumps).
		$text = preg_replace(
			'#\b((?:X-Amz-)?(?:Credential|Signature|Security-Token|SecurityToken|Session-?Token|secret(?:_access)?_?key|secret|password|passwd|token|access[_-]?key[_-]?id|AWSAccessKeyId))(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;"\'<>]+)#i',
			'$1$2' . self::MASK,
			$text
		) ?? $text;

		// AWS-style access key ids (long-term, temporary, role/user ids).
		$text = preg_replace( '#\b(?:AKIA|ASIA|AIDA|AROA|ANPA|AGPA)[A-Z0-9]{12,}\b#', '[ACCESS_KEY]', $text ) ?? $text;

		if ( $max > 0 && strlen( $text ) > $max ) {
			$text = substr( $text, 0, max( 0, $max - 3 ) ) . '...';
		}
		return $text;
	}
}
