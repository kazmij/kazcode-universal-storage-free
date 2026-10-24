<?php
/**
 * Integrity Center delivery-drift diagnostics (#55): report-only.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Storage\PathGuard;

/**
 * Compares, per offloaded attachment, what WordPress currently references (the
 * metadata-derived manifest and post content), what the inventory records on
 * each row's own profile, what exists locally, and where delivery resolves.
 *
 * Observation only: it makes no storage request and writes nothing. Remote
 * state comes solely from inventory rows, and `missing` there is only ever
 * written from a confirmed 404 — so an unconfirmed (failed, pending, unknown)
 * remote state is reported as "not confirmed", never as missing.
 */
final class DeliveryDriftService {

	public const CATEGORY_STORAGE   = 'storage';
	public const CATEGORY_DELIVERY  = 'delivery';
	public const CATEGORY_REFERENCE = 'reference';

	/** Current artifact absent locally with no verified remote copy. */
	public const ARTIFACT_UNAVAILABLE = 'artifact_unavailable';
	/** Remote copy verified, but delivery resolves to the missing local file. */
	public const LOCAL_404_RISK = 'local_404_risk';
	/** More than one profile holds a current row for the same artifact. */
	public const OWNERSHIP_AMBIGUOUS = 'ownership_ambiguous';
	/** Delivery falls back to a stale/deleted row for a current artifact. */
	public const DELIVERED_FROM_SUPERSEDED = 'delivered_from_superseded';
	/** Post content still references a superseded variant. */
	public const STALE_VARIANT_REFERENCED = 'stale_variant_referenced';
	/** Post content references the local URL of a cloud-only artifact. */
	public const LOCAL_URL_IN_CONTENT = 'local_url_in_content';

	private const SUPERSEDED = array( ObjectRemoteStatus::STALE, ObjectRemoteStatus::DELETED );

	private ObjectRepository $objects;
	private ManifestBuilder $manifests;
	private CleanupDeliveryProbe $delivery;
	/** @var callable(string):bool */
	private $local_exists;
	/** @var callable(list<string>,int,list<string>):array<string,list<int>> */
	private $content_refs;

	/**
	 * @param (callable(string):bool)|null      $local_exists Uploads-relative path → exists locally.
	 * @param (callable(list<string>,int,list<string>):array<string,list<int>>)|null $content_refs Uploads-relative paths, attachment id, current paths → ids of posts whose content mentions each.
	 */
	public function __construct(
		?ObjectRepository $objects = null,
		?ManifestBuilder $manifests = null,
		?CleanupDeliveryProbe $delivery = null,
		?callable $local_exists = null,
		?callable $content_refs = null,
	) {
		$this->objects      = $objects ?? new ObjectRepository();
		$this->manifests    = $manifests ?? new ManifestBuilder();
		$this->delivery     = $delivery ?? new CleanupDeliveryProbe( new Settings() );
		$this->local_exists = $local_exists ?? array( self::class, 'local_file_exists' );
		$this->content_refs = $content_refs ?? array( self::class, 'posts_referencing' );
	}

	/**
	 * Scan one page of attachments that have inventory rows.
	 *
	 * @return array{scanned:int,next_after_id:int,has_more:bool,counts:array<string,int>,findings:list<array<string,mixed>>}
	 */
	public function scan_page( int $limit = 50, int $after_attachment_id = 0 ): array {
		$ids      = $this->objects->attachment_ids_page( $limit, $after_attachment_id );
		$findings = array();
		foreach ( $ids as $attachment_id ) {
			foreach ( $this->analyze( $attachment_id ) as $finding ) {
				$findings[] = $finding;
			}
		}
		$counts = array_fill_keys( array( self::CATEGORY_STORAGE, self::CATEGORY_DELIVERY, self::CATEGORY_REFERENCE ), 0 );
		foreach ( $findings as $finding ) {
			++$counts[ $finding['category'] ];
		}

		return array(
			'scanned'       => count( $ids ),
			'next_after_id' => $ids === array() ? $after_attachment_id : max( $ids ),
			'has_more'      => count( $ids ) >= max( 1, min( 500, $limit ) ),
			'counts'        => $counts,
			'findings'      => $findings,
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function analyze( int $attachment_id ): array {
		$current  = $this->manifests->build( $attachment_id )->relative_paths();
		$by_path  = array();
		foreach ( $this->objects->find_by_attachment( $attachment_id ) as $row ) {
			$by_path[ trim( str_replace( '\\', '/', (string) ( $row['local_relative_path'] ?? '' ) ), '/' ) ][] = $row;
		}

		$candidates = array();
		foreach ( $by_path as $relative => $rows ) {
			if ( $relative !== '' && ! in_array( $relative, $current, true ) ) {
				$candidates[] = (string) $relative;
			}
		}
		$local_of = array();
		foreach ( $current as $relative ) {
			$local_of[ $relative ] = ( $this->local_exists )( $relative );
			if ( ! $local_of[ $relative ] ) {
				$candidates[] = $relative;
			}
		}
		$refs = $candidates === array() ? array() : ( $this->content_refs )( $candidates, $attachment_id, $current );

		$backups = self::backup_paths( $attachment_id );

		$findings = array();
		foreach ( $current as $relative ) {
			$rows       = $by_path[ $relative ] ?? array();
			$live       = array_values( array_filter( $rows, static fn( array $r ): bool => ! in_array( (string) ( $r['remote_status'] ?? '' ), self::SUPERSEDED, true ) ) );
			$verified   = array_values( array_filter( $live, static fn( array $r ): bool => ( $r['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT && ! empty( $r['verified_at'] ) ) );
			$local      = $local_of[ $relative ];
			$remote_url = $this->delivery->resolves_remotely( $attachment_id, $relative );
			$row        = $verified[0] ?? $live[0] ?? $rows[0] ?? null;

			if ( ! $local && $verified === array() ) {
				$confirmed_missing = $live !== array() && array_filter( $live, static fn( array $r ): bool => ( $r['remote_status'] ?? '' ) === ObjectRemoteStatus::MISSING ) !== array();
				$findings[]        = $this->finding(
					$attachment_id,
					$relative,
					$row,
					self::ARTIFACT_UNAVAILABLE,
					self::CATEGORY_STORAGE,
					$confirmed_missing
						? 'Not on this server and the remote object was confirmed missing (404).'
						: 'Not on this server and the remote copy is not confirmed (' . ( $live === array() ? 'no inventory row' : (string) ( $live[0]['remote_status'] ?? '' ) ) . ').',
					$confirmed_missing
						? 'Restore the file from a backup; Repair cannot help without a local copy.'
						: 'Run Integrity → Verify to confirm the remote state before acting.'
				);
			}

			if ( ! $local && $verified !== array() && ! $remote_url ) {
				$findings[] = $this->finding( $attachment_id, $relative, $verified[0], self::LOCAL_404_RISK, self::CATEGORY_DELIVERY, 'The remote copy is verified, but WordPress delivers the local URL and the local file is gone.', 'Check Serve from storage and the delivery URL in Settings, or restore the local copy.' );
			}

			$profiles = array_unique( array_map( static fn( array $r ): int => (int) ( $r['storage_profile_id'] ?? 0 ), $verified ) );
			if ( count( $profiles ) > 1 ) {
				sort( $profiles );
				$findings[] = $this->finding( $attachment_id, $relative, $verified[0], self::OWNERSHIP_AMBIGUOUS, self::CATEGORY_DELIVERY, 'Verified rows on profiles ' . implode( ', ', $profiles ) . ' both claim this file; delivery uses the first.', 'Finish or re-run the storage migration for this attachment.' );
			}

			// Image Editor backups ("Restore original image") keep their superseded
			// rows until the next offload revives them; their objects are kept.
			if ( $remote_url && $live === array() && $rows !== array() && ! in_array( $relative, $backups, true ) ) {
				$findings[] = $this->finding( $attachment_id, $relative, $rows[0], self::DELIVERED_FROM_SUPERSEDED, self::CATEGORY_DELIVERY, 'This current file is delivered from a superseded (' . (string) ( $rows[0]['remote_status'] ?? '' ) . ') inventory row.', 'Run Integrity → Verify, then Repair if a local copy exists.' );
			}

			if ( ! $local ) {
				$posts = $refs[ $relative ] ?? array();
				if ( $posts !== array() ) {
					$findings[] = $this->finding( $attachment_id, $relative, $row, self::LOCAL_URL_IN_CONTENT, self::CATEGORY_REFERENCE, 'Post content mentions this file\'s uploads path. Images inserted with the editor are rewritten when rendered; other references (links, background images, custom HTML) request the missing local file.', 'Re-insert the media in those posts. Content is never rewritten automatically.', $posts );
				}
			}
		}

		foreach ( $by_path as $relative => $rows ) {
			if ( $relative === '' || in_array( $relative, $current, true ) ) {
				continue;
			}
			$superseded = array_values( array_filter( $rows, static fn( array $r ): bool => in_array( (string) ( $r['remote_status'] ?? '' ), self::SUPERSEDED, true ) ) );
			if ( $superseded === array() ) {
				continue;
			}
			$posts = $refs[ (string) $relative ] ?? array();
			if ( $posts !== array() ) {
				$findings[] = $this->finding( $attachment_id, (string) $relative, $superseded[0], self::STALE_VARIANT_REFERENCED, self::CATEGORY_REFERENCE, 'Post content still references a superseded variant (for example from before an image edit or regenerate).', 'Re-insert the current image in those posts. Content is never rewritten automatically.', $posts );
			}
		}

		return $findings;
	}

	/**
	 * @param array<string,mixed>|null $row
	 * @param list<int>                $posts
	 * @return array<string,mixed>
	 */
	private function finding( int $attachment_id, string $relative, ?array $row, string $type, string $category, string $detail, string $next, array $posts = array() ): array {
		return array(
			'attachment_id'      => $attachment_id,
			'relative'           => $relative,
			'finding'            => $type,
			'category'           => $category,
			'storage_profile_id' => $row !== null ? (int) ( $row['storage_profile_id'] ?? 0 ) : null,
			'object_key'         => $row !== null ? (string) ( $row['object_key'] ?? '' ) : null,
			'remote_status'      => $row !== null ? (string) ( $row['remote_status'] ?? '' ) : null,
			'detail'             => $detail,
			'next_action'        => $next,
			'post_ids'           => $posts,
		);
	}

	public static function local_file_exists( string $relative ): bool {
		try {
			$absolute = PathGuard::absolute_under_uploads( $relative );
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}
		return $absolute !== null && is_file( $absolute );
	}

	/**
	 * Non-trashed posts whose content mentions each uploads-relative path (≤5 per path).
	 *
	 * One bounded query per attachment: variants share the directory and file
	 * stem, so their longest common prefix selects every candidate post.
	 *
	 * A current file referenced from this attachment's own editor image tag
	 * (`<img class="wp-image-ID">`) is not a risk: that tag is rewritten to the
	 * delivery URL at render time (#65). Superseded variants are not rewritten,
	 * so they are matched anywhere in the content.
	 *
	 * @param list<string> $relatives
	 * @param list<string> $current   Paths in the attachment's current manifest.
	 * @return array<string,list<int>>
	 */
	public static function posts_referencing( array $relatives, int $attachment_id = 0, array $current = array() ): array {
		global $wpdb;
		$relatives = array_values( array_filter( array_unique( $relatives ), static fn( string $r ): bool => $r !== '' ) );
		if ( $relatives === array() ) {
			return array();
		}
		$prefix = $relatives[0];
		foreach ( $relatives as $relative ) {
			while ( $prefix !== '' && ! str_starts_with( $relative, $prefix ) ) {
				$prefix = substr( $prefix, 0, -1 );
			}
		}
		$slash = strrpos( $prefix, '/' );
		if ( strlen( $prefix ) - ( $slash === false ? 0 : $slash + 1 ) < 3 ) {
			// Too generic to be selective; keep the scan bounded instead.
			$prefix = $relatives[0];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only, bounded diagnostic lookup; no WordPress API searches post_content by substring.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY ID ASC LIMIT 50",
				'%' . $wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			$content  = (string) $post['post_content'];
			$stripped = $attachment_id > 0 ? self::without_rewritten_image_tags( $content, $attachment_id ) : $content;
			foreach ( $relatives as $relative ) {
				$haystack = in_array( $relative, $current, true ) ? $stripped : $content;
				if ( count( $out[ $relative ] ?? array() ) < 5 && preg_match( '~(?<![\w.-])' . preg_quote( $relative, '~' ) . '(?![\w.-])~', $haystack ) === 1 ) {
					$out[ $relative ][] = (int) $post['ID'];
				}
			}
		}
		return $out;
	}

	/**
	 * Remove this attachment's editor image tags, which render rewrites to storage.
	 */
	public static function without_rewritten_image_tags( string $content, int $attachment_id ): string {
		return (string) preg_replace( '~<img\b[^>]*\bwp-image-' . $attachment_id . '\b[^>]*>~i', '', $content );
	}

	/**
	 * Uploads-relative paths of the Image Editor backups WordPress keeps for an attachment.
	 *
	 * @return list<string>
	 */
	private static function backup_paths( int $attachment_id ): array {
		$backups  = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_array( $backups ) || $attached === '' ) {
			return array();
		}
		$dir = dirname( $attached );
		$out = array();
		foreach ( $backups as $backup ) {
			if ( is_array( $backup ) && ! empty( $backup['file'] ) ) {
				$out[] = ( $dir === '.' ? '' : $dir . '/' ) . basename( (string) $backup['file'] );
			}
		}
		return $out;
	}
}
