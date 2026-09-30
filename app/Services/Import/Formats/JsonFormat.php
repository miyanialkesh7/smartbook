<?php
/**
 * JSON import/export format.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import\Formats;

use RuntimeException;
use SmartBook\Services\Import\FormatInterface;

/**
 * Reads and writes the row shape described by BookRowSchema as a plain
 * JSON array of row objects. See Formats\BackupFormat for the enveloped
 * variant used by the Backup/Restore flow.
 */
final class JsonFormat implements FormatInterface {

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type(): string {
		return 'application/json';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $rows Rows to process.
	 */
	public function encode( array $rows ): string {
		$encoded = wp_json_encode( array_values( $rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false !== $encoded ? $encoded : '[]';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $content Raw contents of the uploaded file.
	 *
	 * @throws RuntimeException When the JSON file cannot be parsed.
	 */
	public function decode( string $content ): array {
		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( esc_html__( 'The JSON file could not be parsed.', 'smartbook' ) );
		}

		$rows = array();

		foreach ( $decoded as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}
}
