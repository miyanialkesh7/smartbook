<?php
/**
 * Persistent storage for uploaded import/restore files.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

use WP_Error;

/**
 * Moves an uploaded file out of PHP's per-request tmp location into a
 * private, locked-down directory under uploads/ so it survives the
 * several separate AJAX requests a chunked import makes, then deletes it
 * once the session finishes. Files left behind by an abandoned session
 * are swept up by garbage_collect().
 */
final class UploadedFileStore {

	/**
	 * Directory name under wp_upload_dir()'s basedir.
	 */
	private const DIRECTORY_NAME = 'sb-imports';

	/**
	 * Age after which an orphaned file is considered abandoned and removed.
	 */
	private const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Extension => MIME type, for the file types this store ever accepts.
	 *
	 * @var array<string, string>
	 */
	private const MIME_TYPES = array(
		'csv'  => 'text/csv',
		'json' => 'application/json',
		'xml'  => 'application/xml',
	);

	/**
	 * Move an uploaded file into permanent storage.
	 *
	 * @param array<string, mixed> $file               One $_FILES entry.
	 * @param string[]             $allowed_extensions Extensions (no dot) this upload may be.
	 *
	 * @return array{path: string, extension: string}|WP_Error
	 */
	public function store( array $file, array $allowed_extensions ): array|WP_Error {
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'sb_upload_error', __( 'The file could not be uploaded.', 'smartbook' ) );
		}

		$mimes    = array_intersect_key( self::MIME_TYPES, array_flip( $allowed_extensions ) );
		$filetype = wp_check_filetype( (string) ( $file['name'] ?? '' ), $mimes );

		if ( '' === (string) $filetype['ext'] || ! in_array( $filetype['ext'], $allowed_extensions, true ) ) {
			return new WP_Error( 'sb_upload_extension', __( 'Unsupported file type.', 'smartbook' ) );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );

		if ( ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'sb_upload_invalid', __( 'Invalid upload.', 'smartbook' ) );
		}

		$this->garbage_collect();

		$directory = $this->prepare_directory();

		if ( false === $directory ) {
			return new WP_Error( 'sb_upload_directory', __( 'The upload directory could not be prepared.', 'smartbook' ) );
		}

		$path = trailingslashit( $directory ) . wp_generate_password( 20, false, false ) . '.' . $filetype['ext'];

		if ( ! move_uploaded_file( $tmp_name, $path ) ) {
			return new WP_Error( 'sb_upload_move', __( 'The uploaded file could not be saved.', 'smartbook' ) );
		}

		return array(
			'path'      => $path,
			'extension' => (string) $filetype['ext'],
		);
	}

	/**
	 * Delete a previously stored file, ignoring a missing/already-deleted one.
	 *
	 * @param string $path File path.
	 */
	public function delete( string $path ): void {
		if ( '' !== $path && file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			@unlink( $path );
		}
	}

	/**
	 * Remove files left behind by sessions that were never finished
	 * (browser closed mid-import, session expired, etc.).
	 */
	public function garbage_collect(): void {
		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIRECTORY_NAME;

		if ( ! is_dir( $directory ) ) {
			return;
		}

		$paths = glob( trailingslashit( $directory ) . '*' );

		foreach ( false !== $paths ? $paths : array() as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! array_key_exists( $extension, self::MIME_TYPES ) ) {
				continue;
			}

			$modified_at = filemtime( $path );

			if ( false !== $modified_at && ( time() - $modified_at ) > self::MAX_AGE ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				@unlink( $path );
			}
		}
	}

	/**
	 * Ensure the storage directory exists and is not publicly browsable
	 * or directly servable, creating the guard files on first use.
	 */
	private function prepare_directory(): string|false {
		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIRECTORY_NAME;

		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$index_file = trailingslashit( $directory ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = trailingslashit( $directory ) . '.htaccess';

		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess_file, "Require all denied\n" );
		}

		return $directory;
	}
}
