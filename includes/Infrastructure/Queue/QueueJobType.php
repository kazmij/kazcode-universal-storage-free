<?php
/**
 * Known queue job type constants.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Job slugs used by QueueGateway implementations.
 */
final class QueueJobType {

	public const OFFLOAD_ATTACHMENT = 'offload_attachment';
	public const CLEANUP_LOCAL_FILES = 'cleanup_local_files';
	public const ORPHAN_SCAN         = 'orphan_scan';
	public const MIGRATE_OBJECT      = 'migrate_object';
	public const MIGRATE_ATTACHMENT  = 'migrate_attachment';
	public const DELETE_SOURCE_OBJECT = 'delete_source_object';
	public const ADOPT_ATTACHMENT     = 'adopt_attachment';

	public const BULK_MIGRATE = 'migrate';
	public const BULK_VERIFY  = 'verify';
	public const BULK_RETRY   = 'retry';
	public const BULK_RESTORE = 'restore';

	/**
	 * @return list<string>
	 */
	public static function bulk_actions(): array {
		return array(
			self::BULK_MIGRATE,
			self::BULK_VERIFY,
			self::BULK_RETRY,
			self::BULK_RESTORE,
		);
	}
}
