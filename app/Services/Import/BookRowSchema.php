<?php
/**
 * Canonical row shape shared by every import/export format.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

use SmartBook\MetaBoxes\BookFields;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\CollectionTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use SmartBook\Taxonomies\LanguageTaxonomy;
use SmartBook\Taxonomies\PublisherTaxonomy;
use SmartBook\Taxonomies\SeriesTaxonomy;
use SmartBook\Taxonomies\ShelfTaxonomy;
use WP_Error;
use WP_Post;

/**
 * Single source of truth for the "one associative array per book" row
 * shape that CsvFormat, JsonFormat, XmlFormat, and BackupFormat all
 * encode/decode. Columns are "ID", "title", "status", every BookFields
 * meta key, and one column per taxonomy (see taxonomy_columns()); every
 * value is a scalar string except the taxonomy columns, which are
 * string[] of term names.
 *
 * Column names are deliberately plain English words ("authors",
 * "genres", ...) rather than taxonomy slugs so they never collide with a
 * BookFields meta key of the same underlying slug (e.g. the "sb_language"
 * meta field vs. the "sb_language" taxonomy).
 */
final class BookRowSchema {

	/**
	 * Post statuses this schema will read or write.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Column name => taxonomy slug, for every taxonomy attached to books.
	 *
	 * @return array<string, string>
	 */
	public static function taxonomy_columns(): array {
		return array(
			'authors'     => AuthorTaxonomy::SLUG,
			'genres'      => GenreTaxonomy::SLUG,
			'publishers'  => PublisherTaxonomy::SLUG,
			'languages'   => LanguageTaxonomy::SLUG,
			'collections' => CollectionTaxonomy::SLUG,
			'series'      => SeriesTaxonomy::SLUG,
			'shelves'     => ShelfTaxonomy::SLUG,
		);
	}

	/**
	 * Every column name, in the order they should appear in a file.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array_merge(
			array( 'ID', 'title', 'status' ),
			array_keys( BookFields::definitions() ),
			array_keys( self::taxonomy_columns() )
		);
	}

	/**
	 * Build one export row from a book post.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return array<string, mixed>
	 */
	public static function row_for_post( WP_Post $post ): array {
		$row = array(
			'ID'     => (string) $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
		);

		foreach ( array_keys( BookFields::definitions() ) as $key ) {
			$row[ $key ] = (string) get_post_meta( $post->ID, $key, true );
		}

		foreach ( self::taxonomy_columns() as $column => $taxonomy ) {
			$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );

			$row[ $column ] = is_array( $terms ) ? array_values( $terms ) : array();
		}

		return $row;
	}

	/**
	 * Create or update a book post from one parsed row.
	 *
	 * @param array<string, mixed> $data      Column name => value, as produced by a FormatInterface::decode().
	 * @param int                  $target_id Existing post ID to update, or 0 to create a new book.
	 */
	public static function apply_row( array $data, int $target_id = 0 ): int|WP_Error {
		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'sb_import_missing_title', __( 'Missing required "title" value.', 'smartbook' ) );
		}

		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true )
			? (string) $data['status']
			: 'draft';

		$post_data = array(
			'post_type'   => BookPostType::SLUG,
			'post_title'  => $title,
			'post_status' => $status,
		);

		if ( $target_id > 0 && get_post( $target_id ) instanceof WP_Post ) {
			$post_data['ID'] = $target_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		foreach ( array_keys( BookFields::definitions() ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			update_post_meta( $post_id, $key, BookFields::sanitize( $key, $data[ $key ] ) );
		}

		foreach ( self::taxonomy_columns() as $column => $taxonomy ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			wp_set_object_terms( $post_id, self::sanitize_terms( $data[ $column ] ), $taxonomy, false );
		}

		return $post_id;
	}

	/**
	 * Normalize a taxonomy column's raw value (either a string[] already,
	 * or a single comma-separated string from CSV) into a clean string[]
	 * of term names.
	 *
	 * @param mixed $raw Raw taxonomy value.
	 *
	 * @return string[]
	 */
	private static function sanitize_terms( mixed $raw ): array {
		$values = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$values = array_map( static fn ( mixed $value ): string => sanitize_text_field( trim( (string) $value ) ), $values );

		return array_values( array_filter( $values, static fn ( string $value ): bool => '' !== $value ) );
	}
}
