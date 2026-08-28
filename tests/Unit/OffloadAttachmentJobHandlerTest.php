<?php
/**
 * OffloadAttachmentJobHandler idempotency tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Infrastructure\Queue\Jobs\OffloadAttachmentJobHandler;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class OffloadAttachmentJobHandlerTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_skips_already_offloaded_attachment(): void {
		WpStubs::set_meta( 99, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED );

		$result = ( new OffloadAttachmentJobHandler() )->handle(
			array(
				'attachment_id' => 99,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Already offloaded.', $result['message'] );
	}

	public function test_requires_attachment_id(): void {
		$result = ( new OffloadAttachmentJobHandler() )->handle( array() );
		$this->assertFalse( $result['success'] );
	}
}
