<?php
/**
 * Reservation index metadata compatibility and exclusion regressions.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use Kazcode\WpStorage\Infrastructure\SchemaMigrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservationSchemaIndexContractTest extends TestCase {

	/** @param array<string, mixed> $changes */
	#[DataProvider( 'index_cases' )]
	public function test_required_indexes_are_full_length_and_extra_indexes_are_compatible( bool $expected, array $changes ): void {
		$this->assertSame( $expected, self::inspect_schema( $changes ) );
	}

	/** @return array<string, array{bool, array<string, mixed>}> */
	public static function index_cases(): array {
		return array(
			'complete schema' => array( true, array() ),
			'path hash prefix' => array( false, array( 'index' => 'blog_relative_path_hash', 'sequence' => 2, 'attributes' => array( 'Sub_part' => 1 ) ) ),
			'object hash prefix' => array( false, array( 'index' => 'profile_object_key_hash', 'sequence' => 2, 'attributes' => array( 'Sub_part' => '1' ) ) ),
			'uuid prefix' => array( false, array( 'index' => 'uuid', 'sequence' => 1, 'attributes' => array( 'Sub_part' => 8 ) ) ),
			'owner prefix' => array( false, array( 'index' => 'owner_state', 'sequence' => 1, 'attributes' => array( 'Sub_part' => 8 ) ) ),
			'explicit hash prefix length' => array( false, array( 'index' => 'blog_relative_path_hash', 'sequence' => 2, 'attributes' => array( 'Sub_part' => 64 ) ) ),
			'additional state index' => array( true, array( 'extra_state_index' => null ) ),
			'additional prefixed state index' => array( true, array( 'extra_state_index' => 4 ) ),
			'missing primary despite PRI summary' => array( false, array( 'remove' => 'PRIMARY' ) ),
			'nonunique primary' => array( false, array( 'index' => 'PRIMARY', 'sequence' => 1, 'attributes' => array( 'Non_unique' => 1 ) ) ),
			'primary over wrong column' => array( false, array( 'index' => 'PRIMARY', 'sequence' => 1, 'attributes' => array( 'Column_name' => 'blog_id' ) ) ),
			'missing owner index' => array( false, array( 'remove' => 'owner_state' ) ),
			'missing lease index' => array( false, array( 'remove' => 'lease_expiry' ) ),
			'nonunique path constraint' => array( false, array( 'index' => 'blog_relative_path_hash', 'attributes' => array( 'Non_unique' => 1 ) ) ),
			'reordered composite index' => array( false, array( 'reverse_path_index' => true ) ),
			'prefix plus compatible extra index' => array( false, array( 'extra_state_index' => null, 'index' => 'profile_object_key_hash', 'sequence' => 2, 'attributes' => array( 'Sub_part' => 1 ) ) ),
		);
	}

	/** @param array<string, mixed> $changes */
	public static function inspect_schema( array $changes ): bool {
		$had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		try {
			$GLOBALS['wpdb'] = new ReservationSchemaIndexWpdbFake( $changes );
			$verifier = new \ReflectionMethod( SchemaMigrator::class, 'reservation_schema_is_present' );
			return $verifier->invoke( new SchemaMigrator(), 'wp_s3ms_storage_reservations' );
		} finally {
			if ( $had_wpdb ) {
				$GLOBALS['wpdb'] = $previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}
}

/** Database metadata only: all acceptance decisions use the production verifier. */
final class ReservationSchemaIndexWpdbFake {
	/** @var list<array<string, mixed>> */
	private array $columns = array();
	/** @var list<array<string, mixed>> */
	private array $indexes = array();

	/** @param array<string, mixed> $changes */
	public function __construct( array $changes ) {
		$definitions = array(
			'id' => array( 'bigint(20) unsigned', 'NO', null, 'auto_increment', 'PRI' ),
			'uuid' => array( 'char(36)', 'NO', null, '', 'UNI' ),
			'blog_id' => array( 'bigint(20) unsigned', 'NO', null, '', 'MUL' ),
			'relative_path' => array( 'varchar(512)', 'YES', null, '', '' ),
			'relative_path_hash' => array( 'char(64)', 'YES', null, '', '' ),
			'profile_id' => array( 'bigint(20) unsigned', 'YES', null, '', 'MUL' ),
			'object_key' => array( 'varchar(1024)', 'YES', null, '', '' ),
			'object_key_hash' => array( 'char(64)', 'YES', null, '', '' ),
			'owner_uuid' => array( 'char(36)', 'NO', null, '', 'MUL' ),
			'state' => array( 'varchar(16)', 'NO', null, '', '' ),
			'worker_token' => array( 'char(36)', 'YES', null, '', '' ),
			'fencing_epoch' => array( 'bigint(20) unsigned', 'NO', '0', '', '' ),
			'lease_expires_at' => array( 'datetime', 'YES', null, '', 'MUL' ),
			'settled_at' => array( 'datetime', 'YES', null, '', '' ),
			'settlement_proof' => array( 'varchar(191)', 'YES', null, '', '' ),
			'created_at' => array( 'datetime', 'NO', null, '', '' ),
			'updated_at' => array( 'datetime', 'NO', null, '', '' ),
		);
		foreach ( $definitions as $name => $definition ) {
			$this->columns[] = array(
				'Field' => $name,
				'Type' => $definition[0],
				'Null' => $definition[1],
				'Default' => $definition[2],
				'Extra' => $definition[3],
				'Key' => $name === 'state' && array_key_exists( 'extra_state_index', $changes ) ? 'MUL' : $definition[4],
			);
		}
		$keys = array(
			'PRIMARY' => array( 0, array( 'id' ) ),
			'uuid' => array( 0, array( 'uuid' ) ),
			'blog_relative_path_hash' => array( 0, array( 'blog_id', 'relative_path_hash' ) ),
			'profile_object_key_hash' => array( 0, array( 'profile_id', 'object_key_hash' ) ),
			'owner_state' => array( 1, array( 'owner_uuid', 'state' ) ),
			'lease_expiry' => array( 1, array( 'lease_expires_at' ) ),
		);
		if ( ! empty( $changes['reverse_path_index'] ) ) {
			$keys['blog_relative_path_hash'][1] = array( 'relative_path_hash', 'blog_id' );
		}
		foreach ( $keys as $name => $definition ) {
			if ( $name === ( $changes['remove'] ?? null ) ) {
				continue;
			}
			foreach ( $definition[1] as $offset => $column ) {
				$row = array( 'Key_name' => $name, 'Non_unique' => $definition[0], 'Seq_in_index' => $offset + 1, 'Column_name' => $column, 'Sub_part' => null );
				if ( $name === ( $changes['index'] ?? null ) && ( ! isset( $changes['sequence'] ) || $changes['sequence'] === $offset + 1 ) ) {
					$row = array_replace( $row, $changes['attributes'] );
				}
				$this->indexes[] = $row;
			}
		}
		if ( array_key_exists( 'extra_state_index', $changes ) ) {
			$this->indexes[] = array( 'Key_name' => 'extra_state', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'state', 'Sub_part' => $changes['extra_state_index'] );
		}
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_var( string $query ): ?string {
		return str_contains( $query, 'SHOW TABLES' ) ? 'wp_s3ms_storage_reservations' : null;
	}

	/** @return array<string, string>|null */
	public function get_row( string $query, mixed $output = null ): ?array {
		return str_contains( $query, 'SHOW TABLE STATUS' ) ? array( 'Name' => 'wp_s3ms_storage_reservations', 'Engine' => 'InnoDB' ) : null;
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $query, mixed $output = null ): array {
		if ( str_contains( $query, 'SHOW COLUMNS' ) ) {
			return $this->columns;
		}
		return str_contains( $query, 'SHOW INDEX' ) ? $this->indexes : array();
	}
}
