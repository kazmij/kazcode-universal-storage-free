<?php
/**
 * AdoptAttachmentService — HEAD only, no Put (P8-T04).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Domain\ManifestBuilder;
use Kazcode\WpStorage\Domain\MediaManifest;
use Kazcode\WpStorage\Domain\ObjectRemoteStatus;
use Kazcode\WpStorage\Domain\RemoteObservation;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use Kazcode\WpStorage\Infrastructure\WpdbStorageProfileRepository;
use Kazcode\WpStorage\Services\AdoptAttachmentService;
use Kazcode\WpStorage\Storage\ProfileStorageGateway;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class AdoptAttachmentServiceTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	protected function tearDown(): void {
		WpStubs::reset();
	}

	public function test_dry_run_heads_without_persisting_rows(): void {
		WpStubs::$posts[5] = (object) array(
			'ID'        => 5,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->expects( $this->once() )
			->method( 'head_key' )
			->with( 'uploads/2026/08/photo.jpg' )
			->willReturn( array( 'exists' => true, 'content_length' => 100 ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'upsert' );

		$result = $this->service( $gateway, $objects )->adopt( 5, 1, true );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['adopted'] );
	}

	public function test_adopt_persists_present_rows_without_upload(): void {
		WpStubs::$posts[7] = (object) array(
			'ID'        => 7,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => true, 'content_length' => 256 ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return ( $data['remote_status'] ?? '' ) === ObjectRemoteStatus::PRESENT
							&& ! empty( $data['verified_at'] );
					}
				)
			);
		$objects->method( 'find_by_attachment' )->willReturn(
			array(
				array(
					'attachment_id'      => 7,
					'storage_profile_id' => 1,
					'object_key'         => 'uploads/2026/08/photo.jpg',
					'remote_status'      => ObjectRemoteStatus::PRESENT,
					'variant_type'       => 'original',
					'verified_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);

		$result = $this->service( $gateway, $objects )->adopt( 7, 1, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 0, $result['missing'] );
	}

	public function test_confirmed_missing_records_missing_row(): void {
		WpStubs::$posts[9] = (object) array(
			'ID'        => 9,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn( array( 'exists' => false, 'confirmed_missing' => true ) );

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->once() )
			->method( 'upsert' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return ( $data['remote_status'] ?? '' ) === ObjectRemoteStatus::MISSING;
					}
				)
			);
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$result = $this->service( $gateway, $objects )->adopt( 9, 1, false );

		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( 0, $result['errors'] );
	}

	public function test_transient_head_error_does_not_record_missing_row(): void {
		WpStubs::$posts[11] = (object) array(
			'ID'        => 11,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'Connection timed out.' )
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'upsert' );
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$result = $this->service( $gateway, $objects )->adopt( 11, 1, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['adopted'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( 'Connection timed out.', $result['results'][0]['error'] );
	}

	/**
	 * @dataProvider uncertain_head_provider
	 */
	public function test_uncertain_head_results_do_not_record_missing_rows(string $error, string $error_class): void {
		WpStubs::$posts[13] = (object) array(
			'ID'        => 13,
			'post_type' => 'attachment',
		);

		$gateway = $this->createMock( ProfileStorageGateway::class );
		$gateway->method( 'head_key' )->willReturn(
			array(
				'exists'            => false,
				'confirmed_missing' => false,
				'error'             => $error,
				'error_class'       => $error_class,
			)
		);

		$objects = $this->createMock( ObjectRepository::class );
		$objects->expects( $this->never() )->method( 'upsert' );
		$objects->method( 'find_by_attachment' )->willReturn( array() );

		$result = $this->service( $gateway, $objects )->adopt( 13, 1, false );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 1, $result['errors'] );
	}

	/**
	 * @return iterable<string, array{string,string}>
	 */
	public static function uncertain_head_provider(): iterable {
		yield '403' => array( 'Access Denied', RemoteObservation::ERROR_AUTH );
		yield '429' => array( 'Too Many Requests', RemoteObservation::ERROR_THROTTLED );
		yield '503' => array( 'Service Unavailable', RemoteObservation::ERROR_PROVIDER );
		yield 'timeout' => array( 'Connection timed out.', RemoteObservation::ERROR_TIMEOUT );
	}

	private function service( ProfileStorageGateway $gateway, ObjectRepository $objects ): AdoptAttachmentService {
		$profile = $this->profile( 1 );
		$builder = $this->createMock( ManifestBuilder::class );
		$builder->method( 'build' )->willReturn(
			new MediaManifest(
				5,
				array(
					array(
						'relative'     => '2026/08/photo.jpg',
						'variant_type' => 'original',
					),
				)
			)
		);

		$profiles = $this->createMock( WpdbStorageProfileRepository::class );
		$profiles->method( 'find' )->willReturn( $profile );

		$settings = $this->createMock( Settings::class );
		$factory  = static fn( StorageProfile $p ): ProfileStorageGateway => $gateway;

		return new AdoptAttachmentService( $settings, $builder, $objects, $profiles, $factory );
	}

	private function profile( int $id ): StorageProfile {
		$now = gmdate( 'Y-m-d H:i:s' );
		return new StorageProfile(
			$id,
			'uuid-' . $id,
			'Legacy',
			'aws',
			'bucket',
			'us-east-1',
			'',
			false,
			'uploads/',
			'storage',
			'',
			false,
			'keys',
			'legacy_default',
			true,
			false,
			false,
			$now,
			$now
		);
	}
}
