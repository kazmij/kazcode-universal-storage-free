<?php
/**
 * Registry of supported competitor importers.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Import;

defined( 'ABSPATH' ) || exit;

final class ImportRegistry {

	/** @var array<string, ImporterInterface> */
	private array $importers = array();

	/** @param list<ImporterInterface> $importers */
	public function __construct( array $importers = array() ) {
		foreach ( $importers as $importer ) {
			if ( ! $importer instanceof ImporterInterface ) {
				throw new \InvalidArgumentException( 'Import registry entries must implement ImporterInterface.' );
			}
			$id = $importer->id();
			ImportDetection::assert_identifier( $id, 'Importer id' );
			if ( isset( $this->importers[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Duplicate importer id: ' . esc_html( $id ) );
			}
			$this->importers[ $id ] = $importer;
		}
	}

	public static function defaults(): self {
		return new self(
			array(
				new WpOffloadMediaImporter(),
				new AdvancedMediaOffloaderImporter(),
			)
		);
	}

	public function get( string $id ): ?ImporterInterface {
		return $this->importers[ $id ] ?? null;
	}

	/** @return array<string, ImporterInterface> */
	public function all(): array {
		return $this->importers;
	}
}
