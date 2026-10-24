<?php
/**
 * Restore preflight outcome DTO (§6.4).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Shared CLI/UI/REST contract for restore preflight.
 *
 * Product policies (#6):
 * - Reserve margin: max(ceil(required * 0.10), 64 MiB).
 * - Unknown size among restorable subset → WARNING (not BLOCKED).
 * - SIZE_CONFLICT → BLOCKED.
 */
final class RestorePreflightResult {

	public const OUTCOME_SAFE    = 'SAFE';
	public const OUTCOME_WARNING = 'WARNING';
	public const OUTCOME_BLOCKED = 'BLOCKED';

	public const CLASS_PRESENT           = 'PRESENT';
	public const CLASS_EXISTS_ONLY       = 'EXISTS_ONLY';
	public const CLASS_CONFIRMED_MISSING = 'CONFIRMED_MISSING';
	public const CLASS_SIZE_CONFLICT     = 'SIZE_CONFLICT';
	public const CLASS_TRANSIENT         = 'TRANSIENT';
	public const CLASS_AUTH              = 'AUTH';
	public const CLASS_INVALID_REQUEST   = 'INVALID_REQUEST';
	public const CLASS_UNKNOWN           = 'UNKNOWN';
	public const CLASS_STRUCTURAL        = 'STRUCTURAL';

	/**
	 * @param list<string>                                                                $reasons
	 * @param array<string, int>                                                          $counts
	 * @param list<array{attachment_id:int,relative:string,profile_id:int|null,object_key:string,legacy_fallback:bool,expected_size:int|null,class:string}> $plan
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly int $attachment_count,
		public readonly int $required_download_count,
		public readonly int $restorable_count,
		public readonly int $required_bytes,
		public readonly int|false|null $free_bytes,
		public readonly int $reserve_bytes,
		public readonly array $reasons,
		public readonly array $counts,
		public readonly array $plan,
	) {
	}

	/**
	 * @return array{
	 *   outcome:string,
	 *   attachment_count:int,
	 *   required_download_count:int,
	 *   restorable_count:int,
	 *   required_bytes:int,
	 *   free_bytes:int|false|null,
	 *   reserve_bytes:int,
	 *   reasons:list<string>,
	 *   counts:array<string,int>,
	 *   plan:list<array<string,mixed>>
	 * }
	 */
	public function to_array(): array {
		return array(
			'outcome'                 => $this->outcome,
			'attachment_count'        => $this->attachment_count,
			'required_download_count' => $this->required_download_count,
			'restorable_count'        => $this->restorable_count,
			'required_bytes'          => $this->required_bytes,
			'free_bytes'              => $this->free_bytes,
			'reserve_bytes'           => $this->reserve_bytes,
			'reasons'                 => $this->reasons,
			'counts'                  => $this->counts,
			'plan'                    => $this->plan,
		);
	}

	/**
	 * Restorable plan entries only (excludes CONFIRMED_MISSING + STRUCTURAL).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function restorable_plan(): array {
		$out = array();
		foreach ( $this->plan as $entry ) {
			$class = (string) ( $entry['class'] ?? '' );
			if ( $class === self::CLASS_CONFIRMED_MISSING || $class === self::CLASS_STRUCTURAL ) {
				continue;
			}
			if ( in_array( $class, array( self::CLASS_AUTH, self::CLASS_INVALID_REQUEST, self::CLASS_SIZE_CONFLICT ), true ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}
}
