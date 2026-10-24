<?php
/**
 * CleanupEligibility: current-manifest, inventory, exact-key and delivery gate (#53).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\StorageProfile;
use Kazcode\WpStorage\Services\CleanupDeliveryProbe;
use Kazcode\WpStorage\Services\CleanupEligibility;
use Kazcode\WpStorage\Services\ProfileAwareObjectOperations;
use Kazcode\WpStorage\Services\ProfileObjectLocation;
use Kazcode\WpStorage\Services\ProfileObjectLocator;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class CleanupEligibilityTest extends TestCase {

	private const ORIGINAL = '2026/08/photo.jpg';
	private const THUMB    = '2026/08/photo-150x150.jpg';

	private string $uploads;

	/** @var array<string, ProfileObjectLocation> */
	private array $locations = array();

	/** @var array<string, array<string, mixed>> */
	private array $heads = array();

	/** @var array<string, bool> */
	private array $delivery = array();

	/** @var list<string> */
	private array $headed = array();

	private int $forgotten = 0;

	protected function setUp(): void {
		WpStubs::reset();
		$this->uploads = sys_get_temp_dir() . '/s3ms-eligibility-' . uniqid( '', true );
		wp_mkdir_p( $this->uploads . '/2026/08' );
		WpStubs::$uploads_basedir = $this->uploads;

		foreach ( array( self::ORIGINAL, self::THUMB ) as $relative ) {
			$this->locations[ $relative ] = $this->found( $relative, 1 );
			$this->heads[ $relative ]     = array(
				'exists'         => true,
				'content_length' => 1,
			);
			$this->delivery[ $relative ]  = true;
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->uploads . '/2026/08/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		@rmdir( $this->uploads . '/2026/08' );
		@rmdir( $this->uploads . '/2026' );
		@rmdir( $this->uploads );
		WpStubs::reset();
	}

	public function test_all_current_artifacts_verified_inventoried_and_remotely_delivered_is_eligible(): void {
		$locals = $this->locals();

		$decision = $this->evaluate( $locals, $locals );

		$this->assertTrue( $decision['eligible'] );
		$this->assertEqualsCanonicalizing( array( self::ORIGINAL, self::THUMB ), $this->headed );
		$this->assertSame( 1, $this->forgotten, 'inventory must be re-read, not served from a stale cache' );
	}

	public function test_original_present_but_current_thumbnail_confirmed_missing_blocks(): void {
		$this->heads[ self::THUMB ] = array(
			'exists'            => false,
			'confirmed_missing' => true,
		);
		$locals = $this->locals();

		// Even a policy that would only delete the original must not proceed.
		$decision = $this->evaluate( $locals, array( self::ORIGINAL => $locals[ self::ORIGINAL ] ) );

		$this->assertFalse( $decision['eligible'] );
		$this->assertSame( CleanupEligibility::REMOTE_MISSING, $decision['reason'] );
		$this->assertSame( self::THUMB, $decision['relative'] );
	}

	/**
	 * @dataProvider uncertain_heads
	 * @param array<string, mixed> $head
	 */
	public function test_transient_auth_or_unknown_head_never_authorizes( array $head ): void {
		$this->heads[ self::THUMB ] = $head;
		$locals                     = $this->locals();

		$decision = $this->evaluate( $locals, $locals );

		$this->assertFalse( $decision['eligible'] );
		$this->assertSame( CleanupEligibility::REMOTE_NOT_VERIFIED, $decision['reason'] );
	}

	/**
	 * @return array<string, array{0:array<string, mixed>}>
	 */
	public static function uncertain_heads(): array {
		return array(
			'timeout'          => array( array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout' ) ),
			'auth'             => array( array( 'exists' => false, 'error' => 'AccessDenied', 'error_code' => 'AccessDenied', 'status_code' => 403 ) ),
			'empty result'     => array( array() ),
			'exists no length' => array( array( 'exists' => true ) ),
			'size mismatch'    => array( array( 'exists' => true, 'content_length' => 999 ) ),
		);
	}

	public function test_artifact_missing_from_durable_inventory_blocks(): void {
		$this->locations[ self::THUMB ] = ProfileObjectLocation::not_in_inventory( self::THUMB );
		$locals                         = $this->locals();

		$decision = $this->evaluate( $locals, $locals );

		$this->assertFalse( $decision['eligible'] );
		$this->assertSame( CleanupEligibility::INVENTORY_NOT_CONFIRMED, $decision['reason'] );
		$this->assertNotContains( self::THUMB, $this->headed, 'no HEAD may substitute for missing inventory' );
	}

	public function test_ambiguous_inventory_location_blocks(): void {
		$this->locations[ self::ORIGINAL ] = ProfileObjectLocation::ambiguous( self::ORIGINAL );
		$locals                            = $this->locals();

		$decision = $this->evaluate( $locals, $locals );

		$this->assertSame( CleanupEligibility::INVENTORY_NOT_CONFIRMED, $decision['reason'] );
	}

	public function test_remote_present_but_delivery_still_local_blocks(): void {
		$this->delivery[ self::ORIGINAL ] = false;
		$locals                           = $this->locals();

		$decision = $this->evaluate( $locals, $locals );

		$this->assertFalse( $decision['eligible'] );
		$this->assertSame( CleanupEligibility::DELIVERY_NOT_REMOTE, $decision['reason'] );
		$this->assertSame( self::ORIGINAL, $decision['relative'] );
	}

	/**
	 * WordPress 7.1 client-side sizes can use a different filename than a
	 * plugin/server-generated variant. Only the name in current metadata counts.
	 */
	public function test_browser_generated_filename_in_current_metadata_is_authoritative(): void {
		$browser_thumb = '2026/08/photo-150x150-1.jpg';
		// Remote/inventory only know the server-style name.
		$this->locations[ $browser_thumb ] = ProfileObjectLocation::not_in_inventory( $browser_thumb );
		$locals                            = array(
			self::ORIGINAL => $this->touch( self::ORIGINAL ),
			$browser_thumb => $this->touch( $browser_thumb ),
		);
		$manifest = array(
			array( 'relative' => self::ORIGINAL, 'variant_type' => 'original' ),
			array( 'relative' => $browser_thumb, 'variant_type' => 'size' ),
		);

		$decision = $this->eligibility()->evaluate( 7, $manifest, $locals, $locals );

		$this->assertFalse( $decision['eligible'] );
		$this->assertSame( CleanupEligibility::INVENTORY_NOT_CONFIRMED, $decision['reason'] );
		$this->assertSame( $browser_thumb, $decision['relative'] );
		$this->assertNotContains( self::THUMB, $this->headed, 'a similarly named variant must not stand in for the current one' );
	}

	public function test_required_profile_mismatch_blocks(): void {
		$this->locations[ self::THUMB ] = $this->found( self::THUMB, 2 );
		$locals                         = $this->locals();

		$decision = $this->evaluate( $locals, $locals, 1 );

		$this->assertSame( CleanupEligibility::PROFILE_MISMATCH, $decision['reason'] );
	}

	public function test_migrated_attachment_uses_recorded_profile_binding_when_no_profile_is_required(): void {
		foreach ( array( self::ORIGINAL, self::THUMB ) as $relative ) {
			$this->locations[ $relative ] = $this->found( $relative, 2 );
		}
		$locals = $this->locals();

		$decision = $this->evaluate( $locals, $locals, null );

		$this->assertTrue( $decision['eligible'] );
	}

	public function test_already_cleaned_variant_is_checked_against_recorded_size(): void {
		$locals = array( self::ORIGINAL => $this->touch( self::ORIGINAL ) );
		$this->locations[ self::THUMB ] = $this->found( self::THUMB, 1, 5 );
		$this->heads[ self::THUMB ]     = array(
			'exists'         => true,
			'content_length' => 5,
		);

		$decision = $this->evaluate( $locals, $locals );

		$this->assertTrue( $decision['eligible'], 'retry after an interrupted cleanup must stay possible' );
	}

	public function test_already_cleaned_variant_without_recorded_size_blocks(): void {
		$locals = array( self::ORIGINAL => $this->touch( self::ORIGINAL ) );

		$decision = $this->evaluate( $locals, $locals );

		$this->assertSame( CleanupEligibility::EXPECTED_SIZE_UNKNOWN, $decision['reason'] );
		$this->assertSame( self::THUMB, $decision['relative'] );
	}

	public function test_target_outside_current_manifest_blocks(): void {
		$locals            = $this->locals();
		$stray             = '2026/08/other.jpg';
		$targets           = $locals;
		$targets[ $stray ] = $this->touch( $stray );

		$decision = $this->evaluate( $locals, $targets );

		$this->assertSame( CleanupEligibility::TARGET_OUTSIDE_MANIFEST, $decision['reason'] );
	}

	public function test_empty_manifest_blocks(): void {
		$decision = $this->eligibility()->evaluate( 7, array(), array(), array( self::ORIGINAL => '/x' ) );

		$this->assertSame( CleanupEligibility::MANIFEST_EMPTY, $decision['reason'] );
	}

	public function test_unexpected_error_fails_closed(): void {
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->willThrowException( new \RuntimeException( 'db gone' ) );
		$eligibility = new CleanupEligibility(
			$this->createMock( CleanupDeliveryProbe::class ),
			$locator,
			$this->createMock( ProfileAwareObjectOperations::class )
		);
		$locals = $this->locals();

		$decision = $eligibility->evaluate( 7, $this->manifest(), $locals, $locals );

		$this->assertFalse( $decision['eligible'] );
		$this->assertSame( CleanupEligibility::EVALUATION_FAILED, $decision['reason'] );
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * @param array<string, string> $locals
	 * @param array<string, string> $targets
	 * @return array{eligible:bool,reason:string,relative:string}
	 */
	private function evaluate( array $locals, array $targets, ?int $profile_id = 1 ): array {
		return $this->eligibility()->evaluate( 7, $this->manifest(), $locals, $targets, $profile_id );
	}

	private function eligibility(): CleanupEligibility {
		$locator = $this->createMock( ProfileObjectLocator::class );
		$locator->method( 'locate' )->willReturnCallback(
			fn( int $id, string $relative ): ProfileObjectLocation => $this->locations[ $relative ]
				?? ProfileObjectLocation::not_in_inventory( $relative )
		);
		$locator->method( 'forget_attachment' )->willReturnCallback(
			function (): void {
				++$this->forgotten;
			}
		);

		$objects = $this->createMock( ProfileAwareObjectOperations::class );
		$objects->method( 'head_attachment_relative' )->willReturnCallback(
			function ( int $id, string $relative, bool $legacy ): array {
				$this->assertFalse( $legacy, 'cleanup must never fall back to the legacy default-profile HEAD' );
				$this->headed[] = $relative;
				return $this->heads[ $relative ] ?? array();
			}
		);

		$delivery = $this->createMock( CleanupDeliveryProbe::class );
		$delivery->method( 'resolves_remotely' )->willReturnCallback(
			fn( int $id, string $relative ): bool => $this->delivery[ $relative ] ?? false
		);

		return new CleanupEligibility( $delivery, $locator, $objects );
	}

	/**
	 * @return list<array{relative:string,variant_type:string}>
	 */
	private function manifest(): array {
		return array(
			array( 'relative' => self::ORIGINAL, 'variant_type' => 'original' ),
			array( 'relative' => self::THUMB, 'variant_type' => 'size' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function locals(): array {
		return array(
			self::ORIGINAL => $this->touch( self::ORIGINAL ),
			self::THUMB    => $this->touch( self::THUMB ),
		);
	}

	private function found( string $relative, int $profile_id, ?int $size = null ): ProfileObjectLocation {
		$profile = new StorageProfile(
			$profile_id,
			'uuid-' . $profile_id,
			'Profile ' . $profile_id,
			'aws',
			'bucket-' . $profile_id,
			'us-east-1',
			'',
			false,
			'p' . $profile_id . '/',
			'storage',
			'',
			false,
			'keys',
			'',
			$profile_id === 1,
			false,
			false,
			'',
			''
		);
		return ProfileObjectLocation::found(
			array(
				'storage_profile_id' => $profile_id,
				'object_key'         => 'p' . $profile_id . '/' . $relative,
				'size_bytes'         => $size,
			),
			$profile,
			'p' . $profile_id . '/' . $relative,
			$relative
		);
	}

	private function touch( string $relative ): string {
		$absolute = $this->uploads . '/' . $relative;
		file_put_contents( $absolute, 'x' );
		return $absolute;
	}
}
