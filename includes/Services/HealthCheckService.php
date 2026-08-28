<?php
/**
 * Plugin health / diagnostics aggregator.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

use Kazcode\WpStorage\Core\Features;
use Kazcode\WpStorage\Core\ProServices;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Plugin;
use Kazcode\WpStorage\Services\ObjectStatsAggregator;

/**
 * One-shot diagnostics payload for admin UI.
 */
final class HealthCheckService {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$stats   = Plugin::instance()->migration_service()->stats();
		$checks  = array();
		$plan    = Features::plan();

		$checks[] = $this->check(
			'php',
			__( 'PHP version', 'kazcode-universal-storage' ),
			version_compare( PHP_VERSION, '8.1', '>=' ),
			PHP_VERSION
		);
		$checks[] = $this->check(
			'aws_sdk',
			__( 'AWS SDK loaded', 'kazcode-universal-storage' ),
			class_exists( \Aws\S3\S3Client::class ),
			class_exists( \Aws\S3\S3Client::class ) ? 'ok' : 'missing vendor'
		);
		$checks[] = $this->check(
			'configured',
			__( 'Connection settings complete', 'kazcode-universal-storage' ),
			$this->settings->is_aws_configured(),
			$this->settings->credential_mode()
		);
		$checks[] = $this->check(
			'enabled',
			__( 'Offload enabled', 'kazcode-universal-storage' ),
			$this->settings->is_enabled(),
			$this->settings->is_enabled() ? 'on' : 'off'
		);
		$checks[] = $this->check(
			'serve',
			__( 'Serve from S3', 'kazcode-universal-storage' ),
			$this->settings->is_serve_enabled(),
			$this->settings->is_serve_enabled() ? 'on' : 'off'
		);

		$upload = wp_upload_dir();
		$checks[] = $this->check(
			'uploads_writable',
			__( 'Uploads directory writable', 'kazcode-universal-storage' ),
			empty( $upload['error'] ) && wp_is_writable( $upload['basedir'] ),
			(string) ( $upload['basedir'] ?? '' )
		);

		$max_exec = (int) ini_get( 'max_execution_time' );
		$checks[] = $this->check(
			'max_execution_time',
			__( 'PHP max_execution_time', 'kazcode-universal-storage' ),
			$max_exec === 0 || $max_exec >= 30,
			(string) $max_exec
		);

		$memory = ini_get( 'memory_limit' );
		$checks[] = $this->check(
			'memory_limit',
			__( 'PHP memory_limit', 'kazcode-universal-storage' ),
			true,
			(string) $memory
		);

		$conn_ok = null;
		$conn_detail = __( 'Not run in this request', 'kazcode-universal-storage' );
		if ( $this->settings->is_aws_configured() ) {
			try {
				$result = Plugin::instance()->connection_test()->run();
				$conn_ok = ! empty( $result['success'] );
				$conn_detail = $conn_ok ? __( 'Put/Head/Delete succeeded', 'kazcode-universal-storage' ) : __( 'See Test connection steps', 'kazcode-universal-storage' );
			} catch ( \Throwable $e ) {
				$conn_ok = false;
				$conn_detail = $e->getMessage();
			}
		}
		$checks[] = $this->check(
			'connection',
			__( 'Live S3 connection', 'kazcode-universal-storage' ),
			(bool) $conn_ok,
			$conn_detail,
			$conn_ok === null ? 'skip' : ( $conn_ok ? 'ok' : 'fail' )
		);

		$payload = array(
			'plan'              => $plan,
			'pro_active'        => Features::is_pro_active(),
			'checks'            => $checks,
			'stats'             => $stats,
			'object_stats'      => ( new ObjectStatsAggregator() )->get(),
			'settings_summary'  => array(
				'bucket'           => (string) $this->settings->get( 'bucket', '' ),
				'region'           => (string) $this->settings->get( 'region', '' ),
				'provider_preset'  => (string) $this->settings->get( 'provider_preset', 'aws' ),
				'credential_mode'  => $this->settings->credential_mode(),
				'private_media'    => $this->settings->is_private_media(),
				'object_prefix'    => (string) $this->settings->get( 'object_prefix', '' ),
			),
			'notes'             => array(
				__( 'Theme and plugin static files are not Media Library attachments and are never rewritten to S3 by this plugin.', 'kazcode-universal-storage' ),
				__( 'Failed items with “No local files” usually mean the binary was already missing before offload — restore from backup or ignore them.', 'kazcode-universal-storage' ),
			),
			'compat'            => array(
				'elementor'  => (bool) $this->settings->get( 'compat_elementor', true ),
				'acf'        => (bool) $this->settings->get( 'compat_acf', true ),
				'gutenberg'  => (bool) $this->settings->get( 'compat_gutenberg', true ),
			),
		);

		if ( Features::enabled( 'advanced_health' ) ) {
			$orphan_scan = ProServices::get( 'orphan_scan', $this->settings, Plugin::instance()->storage() );
			if ( $orphan_scan !== null ) {
				$payload['orphan_scan'] = $orphan_scan->status();
			}
		}

		return $payload;
	}

	/**
	 * @param string      $id ID.
	 * @param string      $label Label.
	 * @param bool        $ok OK flag.
	 * @param string      $detail Detail.
	 * @param string|null $status Override status ok|fail|skip|warn.
	 * @return array{id:string,label:string,ok:bool,detail:string,status:string}
	 */
	private function check( string $id, string $label, bool $ok, string $detail, ?string $status = null ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'ok'     => $ok,
			'detail' => $detail,
			'status' => $status ?? ( $ok ? 'ok' : 'fail' ),
		);
	}
}
