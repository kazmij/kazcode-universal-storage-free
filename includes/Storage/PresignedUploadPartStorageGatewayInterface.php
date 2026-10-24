<?php
/**
 * Server-authorized multipart UploadPart presigning primitive.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Storage;

defined( 'ABSPATH' ) || exit;

interface PresignedUploadPartStorageGatewayInterface extends StorageGatewayInterface {
	/** Create a short-lived URL for one exact, server-authorized multipart part. */
	public function presign_upload_part( string $key, string $upload_id, int $part_number, int $ttl_seconds ): string;
	/** @return list<array{upload_id:string,initiated_at?:string}> Incomplete uploads for this exact key only. */
	public function list_multipart_uploads_for_key( string $key ): array;
}
