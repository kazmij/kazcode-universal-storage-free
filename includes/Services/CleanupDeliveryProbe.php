<?php
/**
 * Answers whether WordPress would currently deliver a variant from remote storage.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Storage\ProfileDeliveryUrlResolver;
use Kazcode\WpStorage\Storage\PublicUrlResolver;

/**
 * Mirrors AttachmentUrlFilter's serve decision without rendering anything.
 *
 * A local file may only be removed when the active delivery path no longer
 * points at it — otherwise cleanup turns a working page into a broken image.
 */
final class CleanupDeliveryProbe {

	private ?ProfileDeliveryUrlResolver $urls;

	public function __construct(
		private Settings $settings,
		?ProfileDeliveryUrlResolver $urls = null,
	) {
		$this->urls = $urls;
	}

	/**
	 * Whether the variant currently resolves to a non-local delivery URL.
	 */
	public function resolves_remotely( int $attachment_id, string $relative ): bool {
		// Same gates AttachmentUrlFilter::should_serve() / filter_attachment_url() apply.
		if ( ! $this->settings->is_serve_enabled() || ! $this->settings->has_public_url_config() ) {
			return false;
		}
		if ( (string) get_post_meta( $attachment_id, '_s3ms_status', true ) !== AttachmentOffloader::STATUS_OFFLOADED ) {
			return false;
		}
		if ( $this->settings->is_private_media() ) {
			// Signed URLs are minted per request from configured credentials.
			return true;
		}

		$url = $this->urls()->url_for_attachment_relative( $attachment_id, $relative );
		if ( $url === '' ) {
			return false;
		}

		$uploads = wp_upload_dir();
		$baseurl = rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' );
		return $baseurl === '' || ! str_starts_with( $url, $baseurl . '/' );
	}

	private function urls(): ProfileDeliveryUrlResolver {
		return $this->urls ??= new ProfileDeliveryUrlResolver( null, null, new PublicUrlResolver( $this->settings ) );
	}
}
