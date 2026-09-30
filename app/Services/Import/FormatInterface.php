<?php
/**
 * Import/export file format contract.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

/**
 * A file format SmartBook can export book rows to and import them back
 * from. Every implementation works with the same row shape (see
 * BookRowSchema): an associative array per book, where taxonomy values
 * are string[] and everything else is a scalar string.
 */
interface FormatInterface {

	/**
	 * File extension (no dot), e.g. "csv".
	 */
	public function extension(): string;

	/**
	 * MIME type used for the download response.
	 */
	public function mime_type(): string;

	/**
	 * Serialize rows to this format's string representation.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows built by BookRowSchema::row_for_post().
	 */
	public function encode( array $rows ): string;

	/**
	 * Parse this format's string representation back into rows.
	 *
	 * @param string $content Raw contents of the uploaded file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function decode( string $content ): array;
}
