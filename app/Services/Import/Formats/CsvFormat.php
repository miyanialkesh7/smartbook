<?php
/**
 * CSV import/export format.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import\Formats;

use RuntimeException;
use SmartBook\Services\Import\BookRowSchema;
use SmartBook\Services\Import\FormatInterface;

/**
 * Reads and writes the row shape described by BookRowSchema as CSV.
 * Taxonomy columns (string[]) are joined/split on ", " for the single
 * CSV cell; every other column is a plain string cell.
 */
final class CsvFormat implements FormatInterface {

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type(): string {
		return 'text/csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $rows Rows to process.
	 */
	public function encode( array $rows ): string {
		$columns          = array() !== $rows ? array_keys( reset( $rows ) ) : BookRowSchema::columns();
		$taxonomy_columns = BookRowSchema::taxonomy_columns();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
		$handle = fopen( 'php://temp', 'w+' );

		fputcsv( $handle, $columns );

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? '';

				if ( array_key_exists( $column, $taxonomy_columns ) ) {
					$value = implode( ', ', array_map( 'strval', (array) $value ) );
				}

				$line[] = $this->csv_safe( (string) $value );
			}

			fputcsv( $handle, $line );
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
		fclose( $handle );

		return false !== $content ? $content : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $content Raw contents of the uploaded file.
	 *
	 * @throws RuntimeException When the CSV file is empty.
	 */
	public function decode( string $content ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
		$handle = fopen( 'php://temp', 'w+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
		fwrite( $handle, $content );
		rewind( $handle );

		$header = fgetcsv( $handle );

		if ( false === $header || array( null ) === $header ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
			fclose( $handle );

			throw new RuntimeException( esc_html__( 'The CSV file is empty.', 'smartbook' ) );
		}

		$header           = array_map( static fn ( mixed $value ): string => trim( (string) $value ), $header );
		$taxonomy_columns = BookRowSchema::taxonomy_columns();
		$rows             = array();

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			if ( array( null ) === $line ) {
				continue;
			}

			$data = array_combine( $header, array_pad( $line, count( $header ), '' ) );

			if ( false === $data ) {
				continue;
			}

			foreach ( $taxonomy_columns as $column => $taxonomy ) {
				if ( array_key_exists( $column, $data ) ) {
					$data[ $column ] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $data[ $column ] ) ) ) );
				}
			}

			$rows[] = $data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream used for RFC 4180-correct CSV parsing/writing, not a file on disk.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Prefix a cell with an apostrophe if it starts with a character a
	 * spreadsheet application would interpret as the start of a formula,
	 * preventing CSV formula injection when the file is opened in Excel
	 * or similar.
	 *
	 * @param string $value Value.
	 */
	private function csv_safe( string $value ): string {
		if ( '' !== $value && str_contains( '=+-@', $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
