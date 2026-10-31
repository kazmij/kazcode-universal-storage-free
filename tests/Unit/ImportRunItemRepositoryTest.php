<?php
/**
 * Durable competitor-import ledger tests.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Import\ImportedObjectDescriptor;
use Kazcode\WpStorage\Infrastructure\ImportRunItemRepository;
use PHPUnit\Framework\TestCase;

final class ImportRunItemRepositoryTest extends TestCase {

	private ImportRunItemWpdbFake $wpdb;

	protected function setUp(): void {
		$this->wpdb         = new ImportRunItemWpdbFake();
		$GLOBALS['wpdb']    = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_record_replaces_the_same_run_descriptor_identity(): void {
		$repository = new ImportRunItemRepository();
		$descriptor = $this->descriptor( 101, '2026/10/photo.jpg', 'legacy/photo.jpg' );

		$repository->record( 7, $descriptor, 'confirmed_missing', 'Not found.', 'uploads/2026/10/photo.jpg' );
		$repository->record( 7, $descriptor, 'size_conflict', 'Length differs.', 'uploads/2026/10/photo.jpg' );

		$this->assertCount( 1, $this->wpdb->rows );
		$this->assertSame( 'size_conflict', $this->wpdb->rows[0]['classification'] );
		$this->assertSame( 'Length differs.', $this->wpdb->rows[0]['detail'] );
	}

	public function test_eligible_page_returns_each_eligible_row_once_in_ascending_id_order(): void {
		$repository = new ImportRunItemRepository();
		$repository->record( 7, $this->descriptor( 101, '2026/10/a.jpg', 'legacy/a.jpg' ), 'verified_present', null, 'uploads/2026/10/a.jpg' );
		$repository->record( 7, $this->descriptor( 102, '2026/10/b.jpg', 'legacy/b.jpg' ), 'confirmed_missing', null, 'uploads/2026/10/b.jpg' );
		$repository->record( 7, $this->descriptor( 103, '2026/10/c.jpg', 'legacy/c.jpg' ), 'size_conflict', null, 'uploads/2026/10/c.jpg' );
		$repository->record( 8, $this->descriptor( 104, '2026/10/d.jpg', 'legacy/d.jpg' ), 'confirmed_missing', null, 'uploads/2026/10/d.jpg' );

		$first = $repository->eligible_page( 7, 0, 1 );
		$next  = $repository->eligible_page( 7, (int) $first[0]['id'], 10 );

		$this->assertSame( array( 2 ), array_column( $first, 'id' ) );
		$this->assertSame( array( 3 ), array_column( $next, 'id' ) );
	}

	private function descriptor( int $attachment_id, string $path, string $source_key ): ImportedObjectDescriptor {
		return new ImportedObjectDescriptor( $attachment_id, $path, 'original', $source_key, 123, 'image/jpeg', 'source-fingerprint' );
	}
}

final class ImportRunItemWpdbFake {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	/** @var list<array<string,mixed>> */
	public array $rows = array();

	public function replace( string $table, array $data ): int {
		unset( $table );
		foreach ( $this->rows as $index => $row ) {
			if ( $row['import_run_id'] === $data['import_run_id'] && $row['attachment_id'] === $data['attachment_id'] && $row['local_relative_path'] === $data['local_relative_path'] && $row['source_object_key'] === $data['source_object_key'] ) {
				$data['id']          = $row['id'];
				$this->rows[ $index ] = $data;
				return 1;
			}
		}
		$data['id']     = ++$this->insert_id;
		$this->rows[]   = $data;
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string {
		return json_encode( array( $query, $args ), JSON_THROW_ON_ERROR );
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $query, mixed $output = null ): array {
		unset( $output );
		/** @var array{1:list<mixed>} $prepared */
		$prepared = json_decode( $query, true, 512, JSON_THROW_ON_ERROR );
		$run_id   = (int) $prepared[1][0];
		$after_id = (int) $prepared[1][1];
		$limit    = (int) $prepared[1][4];
		$rows     = array_values( array_filter( $this->rows, static fn( array $row ): bool => (int) $row['import_run_id'] === $run_id && (int) $row['id'] > $after_id && in_array( $row['classification'], array( 'confirmed_missing', 'size_conflict' ), true ) ) );
		usort( $rows, static fn( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id'] );
		return array_slice( $rows, 0, $limit );
	}
}
