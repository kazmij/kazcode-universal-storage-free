<?php
/**
 * Optional storage info on attachment edit screen.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Admin;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Attachment\AttachmentOffloader;

/**
 * Adds Storage / Status fields without replacing core layout.
 */
final class AttachmentDetailsPanel {

	/**
	 * Register fields.
	 */
	public function register(): void {
		add_filter('attachment_fields_to_edit', array($this, 'fields'), 10, 2);
	}

	/**
	 * Append read-only fields.
	 *
	 * @param array<string, array<string, mixed>> $form_fields Fields.
	 * @param \WP_Post                            $post Attachment.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(array $form_fields, \WP_Post $post): array {
		if (!current_user_can('upload_files')) {
			return $form_fields;
		}

		$id     = (int) $post->ID;
		$status = (string) get_post_meta($id, '_s3ms_status', true);
		$key    = (string) get_post_meta($id, '_s3ms_original_key', true);
		$synced = (string) get_post_meta($id, '_s3ms_offloaded_at', true);
		$error  = (string) get_post_meta($id, '_s3ms_last_error', true);

		$storage_label = $status === AttachmentOffloader::STATUS_OFFLOADED
			? __('Amazon S3', 'kazcode-universal-storage')
			: __('Local', 'kazcode-universal-storage');

		$form_fields['s3ms_storage'] = array(
			'label' => __('Storage', 'kazcode-universal-storage'),
			'input' => 'html',
			'html'  => '<span>' . esc_html($storage_label) . '</span>',
		);

		$form_fields['s3ms_status'] = array(
			'label' => __('Status', 'kazcode-universal-storage'),
			'input' => 'html',
			'html'  => '<span>' . esc_html($status !== '' ? ucfirst($status) : __('Not offloaded', 'kazcode-universal-storage')) . '</span>',
		);

		if ($key !== '' && current_user_can('manage_options')) {
			$form_fields['s3ms_key'] = array(
				'label' => __('S3 Key', 'kazcode-universal-storage'),
				'input' => 'html',
				'html'  => '<code style="word-break:break-all;">' . esc_html($key) . '</code>',
			);
		}

		if ($synced !== '') {
			$form_fields['s3ms_synced'] = array(
				'label' => __('Last Sync', 'kazcode-universal-storage'),
				'input' => 'html',
				'html'  => '<span>' . esc_html($synced) . '</span>',
			);
		}

		if ($error !== '' && $status === AttachmentOffloader::STATUS_FAILED && current_user_can('manage_options')) {
			$form_fields['s3ms_error'] = array(
				'label' => __('Last Error', 'kazcode-universal-storage'),
				'input' => 'html',
				'html'  => '<span class="s3ms-error">' . esc_html($error) . '</span>',
			);
		}

		return $form_fields;
	}
}
