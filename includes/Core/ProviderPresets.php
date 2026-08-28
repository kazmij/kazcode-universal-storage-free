<?php
/**
 * S3-compatible provider presets.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint / path-style presets for common providers.
 */
final class ProviderPresets {

	/**
	 * Preset definitions.
	 *
	 * @return array<string, array{label:string,endpoint:string,force_path_style:bool,region_hint:string,help:string}>
	 */
	public static function all(): array {
		return array(
			'aws'       => array(
				'label'             => __( 'Amazon S3', 'kazcode-universal-storage' ),
				'endpoint'          => '',
				'force_path_style'  => false,
				'region_hint'       => 'us-east-1',
				'help'              => __( 'Leave endpoint empty. Use the bucket region.', 'kazcode-universal-storage' ),
			),
			'r2'        => array(
				'label'             => __( 'Cloudflare R2', 'kazcode-universal-storage' ),
				'endpoint'          => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
				'force_path_style'  => true,
				'region_hint'       => 'auto',
				'help'              => __( 'Replace ACCOUNT_ID. Region is usually auto. Prefer a custom CDN/public domain for delivery.', 'kazcode-universal-storage' ),
			),
			'spaces'    => array(
				'label'             => __( 'DigitalOcean Spaces', 'kazcode-universal-storage' ),
				'endpoint'          => 'https://<REGION>.digitaloceanspaces.com',
				'force_path_style'  => false,
				'region_hint'       => 'nyc3',
				'help'              => __( 'Replace REGION (e.g. nyc3). Public base URL can be https://bucket.region.digitaloceanspaces.com.', 'kazcode-universal-storage' ),
			),
			'minio'     => array(
				'label'             => __( 'MinIO', 'kazcode-universal-storage' ),
				'endpoint'          => 'https://minio.example.com',
				'force_path_style'  => true,
				'region_hint'       => 'us-east-1',
				'help'              => __( 'Use your MinIO API URL. Path-style is typically required.', 'kazcode-universal-storage' ),
			),
			'wasabi'    => array(
				'label'             => __( 'Wasabi', 'kazcode-universal-storage' ),
				'endpoint'          => 'https://s3.<REGION>.wasabisys.com',
				'force_path_style'  => false,
				'region_hint'       => 'us-east-1',
				'help'              => __( 'Replace REGION to match the Wasabi region of the bucket.', 'kazcode-universal-storage' ),
			),
			'b2'        => array(
				'label'             => __( 'Backblaze B2 (S3 API)', 'kazcode-universal-storage' ),
				'endpoint'          => 'https://s3.<REGION>.backblazeb2.com',
				'force_path_style'  => false,
				'region_hint'       => 'us-west-004',
				'help'              => __( 'Use the S3-compatible endpoint for your B2 region. Create an Application Key with read/write to the bucket.', 'kazcode-universal-storage' ),
			),
			'custom'    => array(
				'label'             => __( 'Custom / other S3-compatible', 'kazcode-universal-storage' ),
				'endpoint'          => '',
				'force_path_style'  => false,
				'region_hint'       => 'us-east-1',
				'help'              => __( 'Enter endpoint and path-style manually per your provider docs.', 'kazcode-universal-storage' ),
			),
		);
	}

	/**
	 * @param string $slug Preset slug.
	 * @return array{label:string,endpoint:string,force_path_style:bool,region_hint:string,help:string}|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}
}
