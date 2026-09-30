<?php
/**
 * Existing-book matching for import rows.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

use SmartBook\PostTypes\BookPostType;
use WP_Post;

/**
 * Finds a pre-existing book that an incoming row most likely refers to,
 * so ImportRunner can update it instead of creating a duplicate.
 *
 * Matching, most specific first: an explicit "ID" column that points at
 * a real book (used by Restore, where the backup carries original post
 * IDs), then ISBN-10, then an exact (case-insensitive) title match. The
 * first hit wins; an empty/absent value at any step is skipped rather
 * than treated as a match.
 */
final class DuplicateDetector {

	/**
	 * Find an existing book matching the row, or 0 if none matches.
	 *
	 * @param array<string, mixed> $row Decoded row, as produced by a FormatInterface::decode().
	 */
	public function find_existing_id( array $row ): int {
		$id = isset( $row['ID'] ) ? absint( $row['ID'] ) : 0;

		if ( $id > 0 ) {
			$post = get_post( $id );

			if ( $post instanceof WP_Post && BookPostType::SLUG === $post->post_type ) {
				return $id;
			}
		}

		$isbn = isset( $row['sb_isbn'] ) ? trim( (string) $row['sb_isbn'] ) : '';

		if ( '' !== $isbn ) {
			$match = $this->find_by_meta( 'sb_isbn', $isbn );

			if ( $match > 0 ) {
				return $match;
			}
		}

		$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';

		return '' !== $title ? $this->find_by_title( $title ) : 0;
	}

	/**
	 * First book post ID with an exact match on a given meta key/value.
	 */
	private function find_by_meta( string $key, string $value ): int {
		$posts = get_posts(
			array(
				'post_type'      => BookPostType::SLUG,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $key,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return array() !== $posts ? (int) $posts[0] : 0;
	}

	/**
	 * First book post ID with an exact (case-insensitive) title match.
	 */
	private function find_by_title( string $title ): int {
		$posts = get_posts(
			array(
				'post_type'      => BookPostType::SLUG,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $title,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		return array() !== $posts ? (int) $posts[0] : 0;
	}
}
