<?php
/** Free admin mutation authorization regressions. */
declare(strict_types=1);

namespace {
	final class KazusCoreAdminDenied extends \RuntimeException {
		/** @param array<string,mixed> $args */
		public function __construct( string $message, public array $args ) {
			parent::__construct( $message );
		}
	}

	if ( ! function_exists( 'wp_die' ) ) {
		/** @param array<string,mixed>|int|string $args */
		function wp_die( $message = '', $title = '', $args = array() ): never {
			throw new KazusCoreAdminDenied( (string) $message, is_array( $args ) ? $args : array( 'response' => $args ) );
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return (string) $text;
		}
	}
}

namespace Kazcode\WpStorage\Tests {

	use Kazcode\WpStorage\Admin\MediaLibraryColumn;
	use Kazcode\WpStorage\Admin\SetupWizardPage;
	use Kazcode\WpStorage\Core\Settings;
	use Kazcode\WpStorage\Tests\Support\WpStubs;
	use PHPUnit\Framework\TestCase;

	final class AdminAuthorizationTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
		$GLOBALS['s3ms_test_current_user_caps'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['s3ms_test_current_user_caps'], $_POST, $_GET );
		WpStubs::reset();
	}

	/** @dataProvider free_admin_mutations */
	public function test_unauthorized_admin_post_mutations_return_403_without_changing_options( object $handler, string $method ): void {
		WpStubs::$options[ Settings::OPTION_KEY ] = array( 'bucket' => 'before' );
		$_POST[ Settings::OPTION_KEY ] = array( 'bucket' => 'attacker-controlled' );

		try {
			$handler->{$method}();
			$this->fail( 'An unauthenticated request must not reach an admin mutation handler.' );
		} catch ( \KazusCoreAdminDenied $denied ) {
			$this->assertSame( 403, $denied->args['response'] ?? null );
		}

		$this->assertSame( array( 'bucket' => 'before' ), WpStubs::$options[ Settings::OPTION_KEY ] );
	}

	/** @return array<string,array{object,string}> */
	public static function free_admin_mutations(): array {
		return array(
			'setup wizard save' => array( new SetupWizardPage( new Settings() ), 'save' ),
			'media library row action' => array( new MediaLibraryColumn(), 'handle_row_action' ),
		);
	}
}
}
