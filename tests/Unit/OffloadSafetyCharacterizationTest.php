<?php
/**
 * Characterization: AttachmentOffloader safety invariants (mocked storage).
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

namespace Kazcode\WpStorage\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Kazcode\WpStorage\Attachment\AttachmentFileResolver;
use Kazcode\WpStorage\Attachment\AttachmentOffloader;
use Kazcode\WpStorage\Core\Settings;
use Kazcode\WpStorage\Infrastructure\AttachmentLeaseHandle;
use Kazcode\WpStorage\Infrastructure\AttachmentLock;
use Kazcode\WpStorage\Storage\S3KeyResolver;
use Kazcode\WpStorage\Storage\S3Storage;
use Kazcode\WpStorage\Tests\Support\WpStubs;

final class OffloadSafetyCharacterizationTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		WpStubs::reset();
		WpStubs::$options['s3ms_object_offload_enabled'] = false;
		$this->uploads = sys_get_temp_dir() . '/s3ms-char-' . uniqid('', true);
		wp_mkdir_p($this->uploads . '/2026/08');
		WpStubs::$uploads_basedir = $this->uploads;
	}

	protected function tearDown(): void {
		$this->rmTree($this->uploads);
		WpStubs::reset();
	}

	public function test_partial_upload_failure_does_not_delete_local_files(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file('2026/08/photo.jpg'),
			'2026/08/photo-150x150.jpg' => $this->touch_file('2026/08/photo-150x150.jpg'),
			'2026/08/photo-300x200.jpg' => $this->touch_file('2026/08/photo-300x200.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys         = new S3KeyResolver($this->settings_map(array( 'object_prefix' => 'uploads/' )));
		$upload_count = 0;
		$storage      = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturnCallback(
			static function (string $absolute, string $relative) use (&$upload_count): string {
				++$upload_count;
				if ($upload_count >= 3) {
					throw new \RuntimeException('Simulated PutObject failure on third file');
				}
				return 'uploads/' . $relative;
			}
		);
		$storage->expects($this->never())->method('delete_keys');
		$storage->expects($this->never())->method('delete_relatives');

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		WpStubs::set_meta(42, '_wp_attached_file', '2026/08/photo.jpg');

		$result = $offloader->offload(42, true);

		$this->assertFalse($result['success']);
		$this->assertSame(AttachmentOffloader::STATUS_FAILED, WpStubs::$post_meta[42]['_s3ms_status'] ?? null);
		$this->assertSame(array(), WpStubs::$deleted_files);
		foreach ($files as $absolute) {
			$this->assertFileExists($absolute);
		}
	}

	public function test_keep_originals_deletes_size_variants_only(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file('2026/08/photo.jpg'),
			'2026/08/photo-150x150.jpg' => $this->touch_file('2026/08/photo-150x150.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::KEEP_ORIGINALS);

		$keys    = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$storage = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturn('2026/08/photo.jpg');
		$storage->method('head_relative')->willReturnCallback($this->head_relative_for_files($files));

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		WpStubs::set_meta(7, '_wp_attached_file', '2026/08/photo.jpg');

		$result = $offloader->offload(7, null);

		$this->assertTrue($result['success']);
		$this->assertFileExists($files['2026/08/photo.jpg']);
		$this->assertFileDoesNotExist($files['2026/08/photo-150x150.jpg']);
	}

	public function test_verify_before_delete_true_skips_local_delete_when_head_fails(): void {
		$files = array(
			'2026/08/photo.jpg' => $this->touch_file('2026/08/photo.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys    = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$heads   = 0;
		$storage = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturn('2026/08/photo.jpg');
		$storage->method('head_relative')->willReturnCallback(
			static function () use (&$heads): array {
				++$heads;
				if ($heads === 1) {
					return array( 'exists' => true, 'content_length' => 1 );
				}
				return array( 'exists' => false, 'confirmed_missing' => false, 'error' => 'timeout' );
			}
		);

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		WpStubs::set_meta(8, '_wp_attached_file', '2026/08/photo.jpg');

		$result = $offloader->offload(8, true);

		$this->assertTrue($result['success']);
		$this->assertSame(AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[8]['_s3ms_status'] ?? null);
		$this->assertSame(array(), WpStubs::$deleted_files);
		$this->assertFileExists($files['2026/08/photo.jpg']);
	}

	public function test_legacy_offload_size_mismatch_does_not_mark_verified_or_delete_local(): void {
		$files = array(
			'2026/08/photo.jpg' => $this->touch_file('2026/08/photo.jpg', 1000),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys    = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$storage = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturn('2026/08/photo.jpg');
		$storage->method('head_relative')->willReturn(array( 'exists' => true, 'content_length' => 900 ));

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		WpStubs::set_meta(18, '_wp_attached_file', '2026/08/photo.jpg');

		$result = $offloader->offload(18, true);

		$this->assertFalse($result['success']);
		$this->assertSame(AttachmentOffloader::STATUS_FAILED, WpStubs::$post_meta[18]['_s3ms_status'] ?? null);
		$this->assertArrayNotHasKey('_s3ms_verified_at', WpStubs::$post_meta[18] ?? array());
		$this->assertFileExists($files['2026/08/photo.jpg']);
	}

	/**
	 * Regression for a confirmed bug: WP core's wp_create_image_subsizes() (called
	 * from inside wp_generate_attachment_metadata(), before any sub-sizes exist) saves
	 * a checkpoint via wp_update_attachment_metadata() with 'sizes' still empty. Under
	 * a delete-local-after-verify policy, an unguarded on_update_metadata() offloaded
	 * + deleted the original from that checkpoint alone — leaving WP's own sub-size
	 * generation with no local original to resize from (every real upload ended up
	 * with zero sub-sizes), and then WP core's own *paired* wp_update_attachment_metadata()
	 * call right after the real wp_generate_attachment_metadata() found the
	 * already-cleaned-up files "missing" and clobbered the correct `offloaded` status
	 * with `failed`. Reproduced live via `wp media import` against MinIO before the
	 * on_add_attachment()/$just_generated guards were added — this exercises the exact
	 * three-call sequence WP core actually performs for one real upload.
	 */
	public function test_generate_metadata_checkpoint_and_paired_update_do_not_corrupt_delete_local_offload(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file('2026/08/photo.jpg'),
			'2026/08/photo-150x150.jpg' => $this->touch_file('2026/08/photo-150x150.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys         = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$upload_count = 0;
		$storage      = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturnCallback(
			static function (string $absolute, string $relative) use (&$upload_count): string {
				++$upload_count;
				return $relative;
			}
		);
		$storage->method('head_relative')->willReturnCallback($this->head_relative_for_files($files));

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->live_file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		$id = 99;
		WpStubs::set_meta($id, '_wp_attached_file', '2026/08/photo.jpg');

		// 1) WordPress creates the attachment post — strictly before it ever calls
		// wp_generate_attachment_metadata() for it.
		$offloader->on_add_attachment($id);

		// 2) wp_create_image_subsizes()'s pre-resize checkpoint: wp_update_attachment_metadata()
		// with 'sizes' still empty. Must be a no-op — nothing uploaded, no status set.
		$checkpoint_meta = array(
			'width'    => 10,
			'height'   => 10,
			'file'     => '2026/08/photo.jpg',
			'filesize' => 1,
			'sizes'    => array(),
		);
		$offloader->on_update_metadata($checkpoint_meta, $id);

		$this->assertSame(0, $upload_count, 'the pre-resize checkpoint must not trigger an offload');
		$this->assertArrayNotHasKey('_s3ms_status', WpStubs::$post_meta[ $id ] ?? array());
		$this->assertFileExists($files['2026/08/photo.jpg'], 'checkpoint must not delete the original before sub-sizes are generated');

		// 3) The real wp_generate_attachment_metadata() pass, now with sub-sizes populated.
		$real_meta = array(
			'width'    => 10,
			'height'   => 10,
			'file'     => '2026/08/photo.jpg',
			'filesize' => 1,
			'sizes'    => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
			),
		);
		$offloader->on_generate_metadata($real_meta, $id);

		$this->assertSame(AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[ $id ]['_s3ms_status'] ?? null);
		$this->assertSame(2, $upload_count, 'both the original and the one size variant should have been uploaded exactly once');
		$this->assertFileDoesNotExist($files['2026/08/photo.jpg'], 'remote_only policy deletes the original once verified on S3');
		$this->assertFileDoesNotExist($files['2026/08/photo-150x150.jpg'], 'remote_only policy deletes size variants once verified on S3');

		// 4) WP core's own paired wp_update_attachment_metadata( $id, wp_generate_attachment_metadata(...) )
		// call, same request, same metadata — not a new event. Must be a no-op: it must
		// NOT re-attempt an offload (which would fail with "no local files found" since
		// step 3 already correctly deleted them) and must NOT clobber the offloaded status.
		$offloader->on_update_metadata($real_meta, $id);

		$this->assertSame(AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[ $id ]['_s3ms_status'] ?? null, 'the paired update call must not downgrade status to failed');
		$this->assertSame(2, $upload_count, 'the paired update call must not re-upload anything');
		$this->assertArrayNotHasKey('_s3ms_last_error', WpStubs::$post_meta[ $id ] ?? array());
	}

	/**
	 * Regression for a bug introduced — and caught — while fixing the one above: naively
	 * skipping every paired on_update_metadata() call whenever on_generate_metadata() just
	 * ran breaks `wp media regenerate` on an *already-offloaded* S3-only attachment.
	 * There, on_generate_metadata() itself legitimately no-ops (maybe_offload(force: false)
	 * sees `_s3ms_status = offloaded` and skips) — it's the *paired* on_update_metadata()
	 * call (force: true) that must actually upload the freshly regenerated local files.
	 * Reproduced live via `wp media regenerate` against MinIO: thumbnails were regenerated
	 * on disk but silently never uploaded, and (before this test's fix landed) the whole
	 * paired call was skipped outright.
	 */
	public function test_regenerate_of_already_offloaded_attachment_still_processes_paired_update(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file('2026/08/photo.jpg'),
			'2026/08/photo-150x150.jpg' => $this->touch_file('2026/08/photo-150x150.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys         = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$upload_count = 0;
		$storage      = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturnCallback(
			static function (string $absolute, string $relative) use (&$upload_count): string {
				++$upload_count;
				return $relative;
			}
		);
		$storage->method('head_relative')->willReturnCallback($this->head_relative_for_files($files));

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->live_file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		$id = 123;
		WpStubs::set_meta($id, '_wp_attached_file', '2026/08/photo.jpg');
		// Already offloaded from a prior, unrelated upload — this is what makes it a
		// regenerate scenario rather than a fresh upload.
		WpStubs::set_meta($id, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED);

		// LocalFileProvider::ensure_local() guarding the file it just re-materialized
		// before feeding it back into wp_generate_attachment_metadata().
		AttachmentOffloader::guard_next_generate($id);

		$meta = array(
			'width'    => 10,
			'height'   => 10,
			'file'     => '2026/08/photo.jpg',
			'filesize' => 1,
			'sizes'    => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
			),
		);

		// wp_create_image_subsizes()'s pre-resize checkpoint — still guarded, no-op.
		$offloader->on_update_metadata(array_merge($meta, array( 'sizes' => array() )), $id);
		$this->assertSame(0, $upload_count);

		// The real wp_generate_attachment_metadata() pass: already offloaded, so
		// maybe_offload(force: false) itself no-ops here — nothing to upload yet.
		$offloader->on_generate_metadata($meta, $id);
		$this->assertSame(0, $upload_count, 'on_generate_metadata must not re-upload by itself when already offloaded');

		// WP core's paired wp_update_attachment_metadata() call — this is the one that
		// must actually process the regenerated files.
		$offloader->on_update_metadata($meta, $id);

		$this->assertSame(2, $upload_count, 'the paired update call must upload the regenerated files, not be skipped');
		$this->assertSame(AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[ $id ]['_s3ms_status'] ?? null);
		$this->assertFileDoesNotExist($files['2026/08/photo.jpg']);
		$this->assertFileDoesNotExist($files['2026/08/photo-150x150.jpg']);
	}

	/**
	 * Regression for a third bug — introduced by, and caught while verifying, the fix
	 * above. guard_next_generate() (originally called unconditionally from
	 * LocalFileProvider::ensure_local(), which materializes a local file before both
	 * Image Editor crop/rotate/scale *and* `wp media regenerate`) assumed a
	 * wp_generate_attachment_metadata() call — whose finally-block clears the guard —
	 * always follows. It doesn't: Image Editor saves (wp_save_image() in WP core) call
	 * wp_update_attachment_metadata() *directly*, with a complete, non-empty 'sizes'
	 * array, no wp_generate_attachment_metadata() involved at all. The guard was left
	 * permanently set for that attachment, silently dropping every edit — reproduced
	 * live: rotating an S3-only image via Attachment Details -> Edit Image reported
	 * success and updated `_wp_attachment_metadata` correctly, but
	 * `_s3ms_status`/`_s3ms_original_key` never updated and the new file was never
	 * uploaded, leaving wp_get_attachment_url() pointing at a 404.
	 *
	 * Fixed by moving the guard call out of the shared ensure_local() and into a
	 * WP_CLI-only condition (regenerate is CLI-only; the Image Editor is exclusively a
	 * browser admin-ajax action — see LocalFileProvider::ensure_local()). This test
	 * exercises the real LocalFileProvider — not a manual guard_next_generate() call —
	 * in the PHPUnit process, which is never WP_CLI, so it proves the guard is
	 * correctly *not* set for this flow at all, rather than merely tolerated once set.
	 */
	public function test_image_editor_flow_never_guards_since_it_is_never_wp_cli(): void {
		$files = array(
			'2026/08/photo-edited.jpg' => $this->touch_file('2026/08/photo-edited.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys         = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$upload_count = 0;
		$storage      = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturnCallback(
			static function (string $absolute, string $relative) use (&$upload_count): string {
				++$upload_count;
				return $relative;
			}
		);
		$storage->method('head_relative')->willReturnCallback($this->head_relative_for_files($files));

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->live_file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		$id = 456;
		WpStubs::set_meta($id, '_wp_attached_file', '2026/08/photo-edited.jpg');
		WpStubs::set_meta($id, '_s3ms_status', AttachmentOffloader::STATUS_OFFLOADED);

		// The actual call ensure_before_image_editor() makes — PHPUnit never runs under
		// WP_CLI, exactly like the real browser admin-ajax request this simulates.
		$this->assertFalse(defined('WP_CLI') && WP_CLI, 'this test is only meaningful outside WP_CLI');
		$provider_settings = $this->createMock(Settings::class);
		$provider_settings->method('is_enabled')->willReturn(true);
		$provider_settings->method('is_aws_configured')->willReturn(true);
		$provider = new \Kazcode\WpStorage\Attachment\LocalFileProvider($provider_settings, $storage);
		$provider->ensure_local($id, true);

		// wp_save_image()'s direct, complete metadata save — no wp_generate_attachment_metadata()
		// call ever happens in this flow.
		$edited_meta = array(
			'width'    => 20,
			'height'   => 10,
			'file'     => '2026/08/photo-edited.jpg',
			'filesize' => 1,
			'sizes'    => array(
				'thumbnail' => array( 'file' => 'photo-edited-150x150.jpg', 'width' => 150, 'height' => 150 ),
			),
		);
		$offloader->on_update_metadata($edited_meta, $id);

		$this->assertSame(1, $upload_count, 'a real, complete metadata update must not be dropped by a guard the Image Editor flow never sets');
		$this->assertSame(AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[ $id ]['_s3ms_status'] ?? null);
		$this->assertSame('2026/08/photo-edited.jpg', WpStubs::$post_meta[ $id ]['_s3ms_original_key'] ?? null);
	}

	/**
	 * Regression for a fourth bug — introduced by, and caught while verifying, the fix
	 * above. wp_create_image_subsizes() doesn't save one empty-'sizes' checkpoint —
	 * it saves progressively as each sub-size finishes (0, then 1, then 2, ... entries
	 * in 'sizes'), all strictly before the real, complete
	 * wp_generate_attachment_metadata() filter call. Blocking on_update_metadata()
	 * only when 'sizes' was *empty* (the fix for the bug above) let these non-empty
	 * but still mid-generation saves through: each one triggered a real offload +
	 * cleanup with only a partial file set, deleting the original before
	 * wp_create_image_subsizes() could even generate the remaining sizes from it —
	 * reproduced live via `wp media import`: sizes=1, then sizes=2 on_update_metadata
	 * calls both fired real, unwanted offload attempts while still `$in_generate`.
	 * Fixed by blocking unconditionally whenever the guard is set (safe now that the
	 * guard is only ever set when a real generate call is guaranteed to follow).
	 */
	public function test_progressive_mid_generation_checkpoints_are_all_blocked(): void {
		$files = array(
			'2026/08/photo.jpg'         => $this->touch_file('2026/08/photo.jpg'),
			'2026/08/photo-150x150.jpg' => $this->touch_file('2026/08/photo-150x150.jpg'),
		);

		$settings = $this->createMock(Settings::class);
		$settings->method('is_enabled')->willReturn(true);
		$settings->method('is_aws_configured')->willReturn(true);
		$settings->method('should_delete_local')->willReturn(true);
		$settings->method('local_storage_policy')->willReturn(\Kazcode\WpStorage\Domain\LocalStoragePolicy::REMOTE_ONLY);

		$keys         = new S3KeyResolver($this->settings_map(array( 'object_prefix' => '' )));
		$upload_count = 0;
		$storage      = $this->createMock(S3Storage::class);
		$storage->method('keys')->willReturn($keys);
		$storage->method('upload_file')->willReturnCallback(
			static function (string $absolute, string $relative) use (&$upload_count): string {
				++$upload_count;
				return $relative;
			}
		);
		$storage->method('head_relative')->willReturnCallback($this->head_relative_for_files($files));

		$offloader = new AttachmentOffloader($settings, $storage);
		$this->inject($offloader, 'files', $this->live_file_resolver_stub($files, $keys));
		$this->inject($offloader, 'lock', $this->always_lock());

		$id = 789;
		WpStubs::set_meta($id, '_wp_attached_file', '2026/08/photo.jpg');

		$offloader->on_add_attachment($id);

		// wp_create_image_subsizes()'s progressive checkpoints — 0, then 1 (of 1)
		// sub-size saved so far — both still strictly mid-generation.
		$offloader->on_update_metadata(array( 'file' => '2026/08/photo.jpg', 'sizes' => array() ), $id);
		$offloader->on_update_metadata(
			array(
				'file'  => '2026/08/photo.jpg',
				'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ) ),
			),
			$id
		);

		$this->assertSame(0, $upload_count, 'no mid-generation checkpoint — empty or partial — may trigger a real offload');
		$this->assertArrayNotHasKey('_s3ms_status', WpStubs::$post_meta[ $id ] ?? array());
		$this->assertFileExists($files['2026/08/photo.jpg'], 'the original must survive every mid-generation checkpoint');

		// The real, final wp_generate_attachment_metadata() pass.
		$real_meta = array(
			'file'  => '2026/08/photo.jpg',
			'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ) ),
		);
		$offloader->on_generate_metadata($real_meta, $id);
		$offloader->on_update_metadata($real_meta, $id);

		$this->assertSame(2, $upload_count);
		$this->assertSame(AttachmentOffloader::STATUS_OFFLOADED, WpStubs::$post_meta[ $id ]['_s3ms_status'] ?? null);
	}

	/**
	 * @return list<array{0:string,1:string}>
	 */
	public static function destructive_call_sites_provider(): array {
		return array(
			array( 'LocalFileCleanup::delete_files', 'wp_delete_file under uploads jail' ),
			array( 'AttachmentRestorer::on_delete_attachment', 'S3Storage::delete_relatives from metadata paths' ),
			array( 'S3Storage::delete_keys', 'explicit key list only — never recursive prefix' ),
			array( 'ConnectionTestService::run', 'temp unlink + delete test object key' ),
		);
	}

	/**
	 * @dataProvider destructive_call_sites_provider
	 */
	public function test_destructive_call_sites_are_documented(string $site, string $note): void {
		$this->assertNotSame('', $site);
		$this->assertNotSame('', $note);
	}

	/**
	 * @param array<string, string> $files
	 */
	private function file_resolver_stub(array $files, S3KeyResolver $keys): AttachmentFileResolver {
		$stub = $this->getMockBuilder(AttachmentFileResolver::class)
			->setConstructorArgs(array( $keys ))
			->onlyMethods(array( 'existing_local_files', 'relative_paths' ))
			->getMock();
		$stub->method('existing_local_files')->willReturn($files);
		$stub->method('relative_paths')->willReturn(array_keys($files));
		return $stub;
	}

	/**
	 * Unlike file_resolver_stub() (a fixed snapshot), this re-checks the real
	 * filesystem on every call — needed to detect a redundant second offload pass
	 * finding files the first pass already (correctly) deleted.
	 *
	 * @param array<string, string> $files
	 */
	private function live_file_resolver_stub(array $files, S3KeyResolver $keys): AttachmentFileResolver {
		$stub = $this->getMockBuilder(AttachmentFileResolver::class)
			->setConstructorArgs(array( $keys ))
			->onlyMethods(array( 'existing_local_files', 'relative_paths' ))
			->getMock();
		$stub->method('relative_paths')->willReturn(array_keys($files));
		$stub->method('existing_local_files')->willReturnCallback(
			static function () use ($files): array {
				$found = array();
				foreach ($files as $relative => $absolute) {
					if (is_file($absolute)) {
						$found[ $relative ] = $absolute;
					}
				}
				return $found;
			}
		);
		return $stub;
	}

	/**
	 * @param array<string, string> $files
	 */
	private function head_relative_for_files(array $files): callable {
		return static function (string $relative) use ($files): array {
			if (!isset($files[$relative]) || !is_file($files[$relative])) {
				return array( 'exists' => false, 'confirmed_missing' => true );
			}
			return array(
				'exists'         => true,
				'content_length' => (int) filesize($files[$relative]),
			);
		};
	}

	private function always_lock(): AttachmentLock {
		$lock = $this->createMock(AttachmentLock::class);
		$lease = new AttachmentLeaseHandle( 1, str_repeat( 'a', 32 ), 1, 'test', time() + 300 );
		$lock->method('acquire')->willReturn(true);
		$lock->method('acquire_lease')->willReturn($lease);
		$lock->method('renew')->willReturn(true);
		$lock->method('release');
		$lock->method('release_lease')->willReturn(true);
		$lock->method('is_locked')->willReturn(false);
		return $lock;
	}

	/**
	 * @param array<string, mixed> $map
	 */
	private function settings_map(array $map): Settings {
		$settings = $this->createMock(Settings::class);
		$settings->method('get')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($map): mixed {
				return $map[ $key ] ?? $default;
			}
		);
		return $settings;
	}

	private function inject(object $target, string $property, mixed $value): void {
		$ref  = new ReflectionClass($target);
		$prop = $ref->getProperty($property);
		$prop->setValue($target, $value);
	}

	private function touch_file(string $relative, int $size = 1): string {
		$absolute = $this->uploads . '/' . $relative;
		$dir      = dirname($absolute);
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($absolute, str_repeat('x', max(1, $size)));
		return $absolute;
	}

	private function rmTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
