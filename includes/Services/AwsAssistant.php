<?php
/**
 * IAM policy snippets for AWS setup assistant.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Generates least-privilege IAM policy JSON for a bucket.
 */
final class AwsAssistant {

	/**
	 * Suggested IAM policy for a single bucket (+ optional prefix).
	 *
	 * @param string $bucket Bucket name.
	 * @param string $prefix Object prefix (may be empty).
	 * @return array{policy:string,checklist:list<string>,console_links:array<string,string>}
	 */
	public function build( string $bucket, string $prefix = '' ): array {
		$bucket = trim( $bucket );
		if ( $bucket === '' ) {
			$bucket = 'YOUR-BUCKET-NAME';
		}
		$prefix = ltrim( str_replace( '\\', '/', trim( $prefix ) ), '/' );
		$obj    = $prefix !== '' ? "{$bucket}/{$prefix}*" : "{$bucket}/*";

		$policy = array(
			'Version'   => '2012-10-17',
			'Statement' => array(
				array(
					'Sid'      => 'ListBucket',
					'Effect'   => 'Allow',
					'Action'   => array( 's3:ListBucket', 's3:GetBucketLocation' ),
					'Resource' => "arn:aws:s3:::{$bucket}",
				),
				array(
					'Sid'      => 'ObjectRW',
					'Effect'   => 'Allow',
					'Action'   => array(
						's3:PutObject',
						's3:GetObject',
						's3:DeleteObject',
						's3:AbortMultipartUpload',
						's3:ListMultipartUploadParts',
					),
					'Resource' => "arn:aws:s3:::{$obj}",
				),
			),
		);

		$json = wp_json_encode( $policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			$json = '';
		}

		return array(
			'policy'        => $json,
			'checklist'     => array(
				__( 'Create an S3 bucket in your chosen region (Block Public Access can stay ON if you use CloudFront/OAC or signed URLs).', 'kazcode-universal-storage' ),
				__( 'Create an IAM user (or role for EC2/ECS) dedicated to this site.', 'kazcode-universal-storage' ),
				__( 'Attach the policy below (edit bucket/prefix first).', 'kazcode-universal-storage' ),
				__( 'For public delivery without signed URLs: bucket policy or CloudFront that allows GetObject for your objects.', 'kazcode-universal-storage' ),
				__( 'Paste Access Key + Secret (or enable IAM role mode on EC2) and run Test connection.', 'kazcode-universal-storage' ),
				__( 'Theme/plugin static assets under /themes or /plugins are never offloaded — only Media Library attachments.', 'kazcode-universal-storage' ),
			),
			'console_links' => array(
				'buckets' => 'https://s3.console.aws.amazon.com/s3/buckets',
				'iam'     => 'https://console.aws.amazon.com/iam/home#/users',
				'policies'=> 'https://console.aws.amazon.com/iam/home#/policies',
			),
		);
	}
}
