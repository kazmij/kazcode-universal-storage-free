<?php
/**
 * Non-secret detected source configuration.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

defined( 'ABSPATH' ) || exit;

final class ImportDetection {

	/** @param list<string> $warnings */
	public function __construct(
		private string $source_id,
		private string $source_label,
		private ?string $source_version = null,
		private ?string $provider_family = null,
		private ?string $bucket = null,
		private ?string $region = null,
		private ?string $endpoint = null,
		private ?string $object_prefix = null,
		private ?string $object_key_style = null,
		private ?string $delivery_base = null,
		private bool $requires_credential_reentry = false,
		private float $confidence = 0.0,
		private array $warnings = array()
	) {
		self::assert_identifier( $source_id, 'Source id' );
		$this->assert_non_blank( $source_label, 'Source label' );
		if ( $provider_family !== null ) {
			self::assert_identifier( $provider_family, 'Provider family' );
		}
		if ( $object_key_style !== null ) {
			self::assert_identifier( $object_key_style, 'Object key style' );
		}
		if ( $confidence < 0.0 || $confidence > 1.0 ) {
			throw new \InvalidArgumentException( 'Detection confidence must be between 0 and 1.' );
		}
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) ) {
				throw new \InvalidArgumentException( 'Detection warnings must be strings.' );
			}
		}
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'source_id'                   => $this->source_id,
			'source_label'                => $this->source_label,
			'source_version'              => $this->source_version,
			'provider_family'             => $this->provider_family,
			'bucket'                      => $this->bucket,
			'region'                      => $this->region,
			'endpoint'                    => $this->endpoint,
			'object_prefix'               => $this->object_prefix,
			'object_key_style'            => $this->object_key_style,
			'delivery_base'               => $this->delivery_base,
			'requires_credential_reentry' => $this->requires_credential_reentry,
			'confidence'                  => $this->confidence,
			'warnings'                    => $this->warnings,
		);
	}

	public static function assert_identifier( string $value, string $label ): void {
		if ( ! preg_match( '/\A[a-z0-9][a-z0-9_-]*\z/', $value ) ) {
			throw new \InvalidArgumentException( esc_html( $label ) . ' must be a non-empty lowercase id.' );
		}
	}

	private function assert_non_blank( string $value, string $label ): void {
		if ( trim( $value ) === '' ) {
			throw new \InvalidArgumentException( esc_html( $label ) . ' must not be blank.' );
		}
	}
}
