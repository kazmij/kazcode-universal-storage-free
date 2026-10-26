<?php
/** Active attachment ownership is required for orphan scan known keys. */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\ObjectRepository;
use PHPUnit\Framework\TestCase;

final class ObjectRepositoryKnownKeysTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new ObjectRepositoryKnownKeysWpdbFake();
	}

	public function test_known_keys_join_existing_wordpress_attachments(): void {
		$keys = ( new ObjectRepository() )->list_known_keys( 42 );

		$this->assertSame( array( 'active/a.jpg' ), $keys );
		$this->assertStringContainsString( 'INNER JOIN wp_posts AS attachment ON attachment.ID = object_row.attachment_id', $GLOBALS['wpdb']->last_query );
		$this->assertStringContainsString( "attachment.post_type = 'attachment'", $GLOBALS['wpdb']->last_query );
		$this->assertStringContainsString( "object_row.remote_status NOT IN ('stale', 'deleted')", $GLOBALS['wpdb']->last_query );
		$this->assertStringContainsString( 'object_row.storage_profile_id = 42', $GLOBALS['wpdb']->last_query );
	}
}

final class ObjectRepositoryKnownKeysWpdbFake {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $last_query = '';

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}

	/** @return list<string> */
	public function get_col( string $query ): array {
		$this->last_query = $query;
		return array( 'active/a.jpg', 'active/a.jpg' );
	}
}
