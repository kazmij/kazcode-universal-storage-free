<?php
/**
 * Builds the active QueueGateway for this site.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Infrastructure\Queue\Jobs\AdoptAttachmentJobHandler;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\CleanupLocalFilesJobHandler;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\DeleteSourceObjectJobHandler;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\OffloadAttachmentJobHandler;
use Kazcode\WpStorage\Services\BackgroundMigrator;

/**
 * Shared registry + driver selection (AS when present, else cron).
 */
final class QueueFactory {

	public static function create( BackgroundMigrator $migrator ): QueueGateway {
		$registry = new JobHandlerRegistry();
		$registry->register( new OffloadAttachmentJobHandler() );
		$registry->register( new CleanupLocalFilesJobHandler() );
		$registry->register( new DeleteSourceObjectJobHandler() );
		$registry->register( new AdoptAttachmentJobHandler() );

		/**
		 * Pro-only job handlers (orphan scan, provider migration) register
		 * themselves here — Core never references those classes directly, so a
		 * Free-only install has no compile-time dependency on Pro.
		 *
		 * @param JobHandlerRegistry $registry Registry to register into.
		 */
		do_action( 'kazus_register_job_handlers', $registry );

		$cron = new CronQueueAdapter( $migrator, $registry );
		return new ActionSchedulerGateway( $cron, $registry );
	}
}
