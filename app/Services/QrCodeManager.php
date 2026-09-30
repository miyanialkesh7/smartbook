<?php
/**
 * QR code storage and lifecycle management.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use chillerlan\QRCode\Output\QRCodeOutputException;
use SmartBook\Frontend\BookScanPage;
use SmartBook\PostTypes\BookPostType;
use WP_Post;

/**
 * Generates, stores, retrieves, and cleans up the QR code image for a
 * book. Each book's QR code encodes its own permalink with
 * Frontend\BookScanPage's "?sb_scan=1" query var appended, so scanning
 * it opens that dedicated mobile page rather than however the active
 * theme would otherwise render the book, and is saved as an SVG file
 * under uploads/sb-qrcodes/{post_id}.svg; the public URL to that file
 * is cached in the "sb_qr_code_url" post meta so callers never need to
 * touch the filesystem directly.
 */
final class QrCodeManager {

	/**
	 * Post meta key holding the public URL of the generated QR image.
	 */
	private const META_URL = 'sb_qr_code_url';

	/**
	 * Post meta key holding the generation timestamp.
	 */
	private const META_GENERATED_AT = 'sb_qr_code_generated_at';

	/**
	 * Uploads subdirectory QR images are stored in.
	 */
	private const DIRECTORY_NAME = 'sb-qrcodes';

	/**
	 * Create the QR code manager.
	 *
	 * @param QrCodeGenerator $generator SVG QR code renderer.
	 * @param LoggerInterface $logger    Logger, used when generation fails.
	 */
	public function __construct(
		private readonly QrCodeGenerator $generator,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * Public URL of a book's QR code image, or '' if none has been
	 * generated yet.
	 *
	 * @param int $post_id Book post ID.
	 */
	public function url_for( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_URL, true );
	}

	/**
	 * Whether a book already has a generated QR code.
	 *
	 * @param int $post_id Book post ID.
	 */
	public function has_qr_code( int $post_id ): bool {
		return '' !== $this->url_for( $post_id );
	}

	/**
	 * Generate the QR code only if one doesn't already exist.
	 *
	 * @param int $post_id Book post ID.
	 */
	public function ensure_generated( int $post_id ): void {
		if ( $this->has_qr_code( $post_id ) ) {
			return;
		}

		$this->regenerate( $post_id );
	}

	/**
	 * (Re)generate a book's QR code, encoding its current permalink,
	 * overwriting any previous image.
	 *
	 * @param int $post_id Book post ID.
	 */
	public function regenerate( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || BookPostType::SLUG !== $post->post_type ) {
			return false;
		}

		$permalink = get_permalink( $post_id );

		if ( false === $permalink ) {
			return false;
		}

		$scan_url = add_query_arg( BookScanPage::QUERY_VAR, '1', $permalink );

		$directory = $this->directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			$this->logger->error( 'Could not create the QR code directory.', array( 'directory' => $directory ) );

			return false;
		}

		$this->ensure_index_file( $directory );

		$file_path = trailingslashit( $directory ) . $post_id . '.svg';

		try {
			$this->generator->generate_svg_file( $scan_url, $file_path );
		} catch ( QRCodeOutputException $exception ) {
			$this->logger->error(
				'QR code generation failed.',
				array(
					'post_id' => $post_id,
					'message' => $exception->getMessage(),
				)
			);

			return false;
		}

		update_post_meta( $post_id, self::META_URL, trailingslashit( $this->directory_url() ) . $post_id . '.svg' );
		update_post_meta( $post_id, self::META_GENERATED_AT, current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Remove a book's stored QR image file and its meta, e.g. when the
	 * book is permanently deleted.
	 *
	 * @param int $post_id Book post ID.
	 */
	public function delete_for_post( int $post_id ): void {
		$file_path = trailingslashit( $this->directory() ) . $post_id . '.svg';

		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		delete_post_meta( $post_id, self::META_URL );
		delete_post_meta( $post_id, self::META_GENERATED_AT );
	}

	/**
	 * Absolute filesystem path to the QR code storage directory. Public
	 * so Core\Uninstaller can remove it entirely on uninstall.
	 */
	public function directory(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . self::DIRECTORY_NAME;
	}

	/**
	 * Public URL of the QR code storage directory.
	 */
	private function directory_url(): string {
		return trailingslashit( wp_upload_dir()['baseurl'] ) . self::DIRECTORY_NAME;
	}

	/**
	 * Guard against directory listing, matching Services\Logger's pattern.
	 *
	 * @param string $directory Absolute path to the directory to guard.
	 */
	private function ensure_index_file( string $directory ): void {
		$index_file = trailingslashit( $directory ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			// Best-effort directory-listing guard; a failure here is not
			// worth surfacing, matching Services\Logger's own suppression.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}
}
