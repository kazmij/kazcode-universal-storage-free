<?php
/**
 * CleanupLocalFilesJobHandler — requires full verify.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\CleanupLocalFilesJobHandler;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class CleanupLocalFilesJobHandlerTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_requires_attachment_id(): void {
		$result = ( new CleanupLocalFilesJobHandler() )->handle( array() );
		$this->assertFalse( $result['success'] );
	}
}
