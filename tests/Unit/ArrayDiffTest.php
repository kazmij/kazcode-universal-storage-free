<?php
/**
 * ArrayDiff::changed_keys() — shared by settings_saved / network_settings_saved audit logging.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use Kazcode\WpStorage\Domain\ArrayDiff;

final class ArrayDiffTest extends TestCase {

	public function test_detects_changed_value_for_existing_key(): void {
		$changed = ArrayDiff::changed_keys(
			array( 'bucket' => 'old-bucket', 'region' => 'us-east-1' ),
			array( 'bucket' => 'new-bucket', 'region' => 'us-east-1' )
		);

		$this->assertSame( array( 'bucket' ), $changed );
	}

	public function test_detects_key_added_or_removed(): void {
		$added = ArrayDiff::changed_keys(
			array( 'a' => '1' ),
			array( 'a' => '1', 'b' => '2' )
		);
		$this->assertSame( array( 'b' ), $added );

		$removed = ArrayDiff::changed_keys(
			array( 'a' => '1', 'b' => '2' ),
			array( 'a' => '1' )
		);
		$this->assertSame( array( 'b' ), $removed );
	}

	public function test_no_changes_returns_empty_list(): void {
		$same = array( 'a' => '1', 'b' => true, 'c' => 3 );

		$this->assertSame( array(), ArrayDiff::changed_keys( $same, $same ) );
	}

	public function test_never_returns_the_actual_values_only_key_names(): void {
		$changed = ArrayDiff::changed_keys(
			array( 'secret_access_key' => 'old-value-should-never-appear' ),
			array( 'secret_access_key' => 'new-value-should-never-appear' )
		);

		$this->assertSame( array( 'secret_access_key' ), $changed );
		foreach ( $changed as $key ) {
			$this->assertStringNotContainsString( 'value-should-never-appear', $key );
		}
	}
}
