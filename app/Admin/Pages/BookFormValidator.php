<?php
/**
 * Validation for the Add/Edit Book forms.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use DateTimeImmutable;
use SmartBook\Services\Import\DuplicateDetector;

/**
 * Single source of truth for validating a submitted Add/Edit Book form
 * before it ever reaches BookRowSchema::apply_row() -- catching problems
 * that engine would otherwise either silently clamp/discard (an invalid
 * ISBN, a negative Pages/Price, a malformed Purchase Date all just get
 * sanitized into something else by BookFields::sanitize(), never
 * rejected) or never check at all (a duplicate ISBN/title). Shared by
 * AddBookPage and EditBookPage so both forms enforce exactly the same
 * rules; CSV/JSON/XML import deliberately does not run through this --
 * a bulk import already has its own per-row ImportError reporting via
 * ImportRunner, and silently skipping/sanitizing a bad bulk row is more
 * useful there than aborting the whole file.
 *
 * Returns the *first* problem found, not a list -- both forms only ever
 * show one message per submission (RedirectsWithNotice's single-notice
 * convention), so validating further once something has already failed
 * would just be discarded work.
 */
final class BookFormValidator {

	/**
	 * Validate a submitted row. $data is the same shape AddBookPage/
	 * EditBookPage build for BookRowSchema::apply_row(): "title"/"status"
	 * merged with collect_posted_row()'s raw (unslashed, not yet
	 * sanitized) BookFields values.
	 *
	 * @param array<string, mixed> $data              Submitted row data.
	 * @param int                  $excluding_post_id  The book being edited, excluded from its own duplicate check; 0 when adding a new book.
	 *
	 * @return string|null The first validation error, or null when the row is valid.
	 */
	public static function validate( array $data, int $excluding_post_id = 0 ): ?string {
		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			return __( 'Please enter a title.', 'smartbook' );
		}

		$isbn10 = isset( $data['sb_isbn'] ) ? trim( (string) $data['sb_isbn'] ) : '';

		if ( '' !== $isbn10 && ! self::is_valid_isbn10( $isbn10 ) ) {
			return __( 'ISBN-10 must be 10 digits (the last one may be "X").', 'smartbook' );
		}

		$pages = isset( $data['sb_pages'] ) ? trim( (string) $data['sb_pages'] ) : '';

		if ( '' !== $pages && ( ! is_numeric( $pages ) || (float) $pages < 0 ) ) {
			return __( 'Pages must be a positive number.', 'smartbook' );
		}

		$price = isset( $data['sb_price'] ) ? trim( (string) $data['sb_price'] ) : '';

		if ( '' !== $price && ( ! is_numeric( $price ) || (float) $price < 0 ) ) {
			return __( 'Price must be a positive number.', 'smartbook' );
		}

		$purchase_date = isset( $data['sb_purchase_date'] ) ? trim( (string) $data['sb_purchase_date'] ) : '';

		if ( '' !== $purchase_date && ! self::is_valid_date( $purchase_date ) ) {
			return __( 'Purchase Date is not a valid date.', 'smartbook' );
		}

		return self::find_duplicate( $title, $isbn10, $excluding_post_id );
	}

	/**
	 * Whether a book matching this row's title/ISBN-10 already exists --
	 * the same lookup Services\Import\DuplicateDetector uses to decide
	 * whether an import row updates an existing book instead of creating
	 * one, reused here to reject the duplicate outright instead.
	 */
	private static function find_duplicate( string $title, string $isbn10, int $excluding_post_id ): ?string {
		$match_id = ( new DuplicateDetector() )->find_existing_id(
			array(
				'title'   => $title,
				'sb_isbn' => $isbn10,
			)
		);

		if ( 0 === $match_id || $match_id === $excluding_post_id ) {
			return null;
		}

		return sprintf(
			/* translators: %s: title of the existing matching book. */
			__( 'A book matching this ISBN/title already exists: "%s". Edit that book instead of creating a duplicate.', 'smartbook' ),
			get_the_title( $match_id )
		);
	}

	/**
	 * Validate an ISBN-10 check digit (mod 11; the 10th character may be
	 * "X" representing the value 10). Hyphens/spaces are stripped before
	 * checking, purely to accept how ISBNs are commonly typed -- they are
	 * not stripped from the stored value itself, which stays whatever
	 * BookFields::sanitize() already does with it.
	 *
	 * Format only (10 characters, digits with an optional trailing "X"
	 * check-digit placeholder) -- deliberately not the actual mod-11
	 * check-digit math. A cataloger typing an ISBN off the back of a
	 * book, a placeholder for a book that predates ISBNs, or a simple
	 * transposition typo would otherwise get hard-rejected on a checksum
	 * technicality unrelated to whether the entry is usable.
	 */
	private static function is_valid_isbn10( string $isbn ): bool {
		$isbn = strtoupper( str_replace( array( '-', ' ' ), '', $isbn ) );

		return 1 === preg_match( '/^\d{9}[\dX]$/', $isbn );
	}

	/**
	 * Whether a string is a valid, real "Y-m-d" date (rejects e.g.
	 * "2024-02-30", not just anything DateTime can loosely parse).
	 */
	private static function is_valid_date( string $value ): bool {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
