<?php
/**
 * Focused S3-compatible storage primitives.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

interface StorageGatewayInterface {
	public function profile_id(): int;
	public function bucket(): string;
	public function head_key( string $key ): array;
	public function put_object_from_file( string $key, string $local_path, string $content_type, string $cache_control ): void;
	public function create_multipart_upload( string $key, string $content_type, string $cache_control ): string;
	public function upload_part( string $key, string $upload_id, int $part_number, $body, int $content_length ): string;
	public function list_parts( string $key, string $upload_id ): array;
	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): void;
	public function abort_multipart_upload( string $key, string $upload_id ): void;
	public function get_range( string $key, int $start, int $end );
}
