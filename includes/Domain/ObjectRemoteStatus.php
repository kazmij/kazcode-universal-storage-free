<?php
/**
 * Remote object status constants.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Persisted remote_status values for s3ms_objects rows.
 */
final class ObjectRemoteStatus {

	public const PENDING   = 'pending';
	public const UPLOADING = 'uploading';
	public const PRESENT   = 'present';
	public const MISSING   = 'missing';
	public const FAILED    = 'failed';
	public const STALE     = 'stale';
	public const DELETED   = 'deleted';
}
