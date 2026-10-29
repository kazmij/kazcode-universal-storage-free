<?php
/**
 * Regression coverage for the adoption candidate cursor query plan.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests\Unit;

use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Services\AdoptService;
use PHPUnit\Framework\TestCase;

final class AdoptServiceQueryPlanTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new AdoptServiceQueryPlanWpdbFake();
	}

	protected function tearDown(): void {
		if ( $this->original_wpdb === null ) {
			unset( $GLOBALS['wpdb'] );
			return;
		}

		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_legacy_cursor_uses_the_attachment_id_index_without_grouping_inventory_rows(): void {
		$service = new AdoptService( $this->createMock( Settings::class ) );

		$this->assertSame( array( 1001, 1003 ), $service->query_ids( 200, 1000, true ) );

		$query = $GLOBALS['wpdb']->last_query;
		$this->assertStringContainsString( 'LEFT JOIN wp_s3ms_objects o ON o.attachment_id = p.ID', $query );
		$this->assertStringContainsString( 'AND o.attachment_id IS NULL', $query );
		$this->assertStringNotContainsString( 'COUNT(*) AS cnt', $query );
		$this->assertStringNotContainsString( 'GROUP BY attachment_id', $query );
	}
}

final class AdoptServiceQueryPlanWpdbFake {

	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_query = '';

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
		}

		return $query;
	}

	/** @return list<string> */
	public function get_col( string $query ): array {
		$this->last_query = $query;
		return array( '1001', '1003' );
	}
}
