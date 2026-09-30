<?php
/**
 * Reader star ratings attached to book comments.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services;

/**
 * Single source of truth for the "sb_comment_rating" comment meta key a
 * commenter's 1-5 star rating (see Frontend\BookContentDisplay's
 * add_rating_field()/save_comment_rating()) is stored under, and for
 * averaging it across a book's approved comments -- consumed by both
 * BookContentDisplay (the single book page's reader-rating summary) and
 * Admin\Tables\BooksListTable (the "Rating" column on the admin books
 * list), so both agree on exactly the same figure.
 */
final class CommentRating {

	/**
	 * Comment meta key a comment's star rating is stored under.
	 */
	public const META_KEY = 'sb_comment_rating';

	/**
	 * Average of every approved comment's star rating on a book, and how
	 * many ratings that average is based on. array(0.0, 0) when nobody
	 * has rated yet. A thin wrapper around averages() for the common
	 * single-post case (e.g. the single book page's own reader-rating
	 * summary) -- for a whole page of posts at once, call averages()
	 * directly instead, so as not to run one get_comments() query per
	 * post (see Admin\Tables\BooksListTable::prepare_items()).
	 *
	 * @return array{0: float, 1: int}
	 */
	public static function average( int $post_id ): array {
		return self::averages( array( $post_id ) )[ $post_id ] ?? array( 0.0, 0 );
	}

	/**
	 * Average rating (and count) for every post ID given, in a single
	 * batched query. Only posts with at least one valid rating appear
	 * in the returned array; a post with none is simply absent from it.
	 *
	 * @param int[] $post_ids Post IDs to average ratings for.
	 *
	 * @return array<int, array{0: float, 1: int}> Post ID => [average, count].
	 */
	public static function averages( array $post_ids ): array {
		if ( array() === $post_ids ) {
			return array();
		}

		$comments = get_comments(
			array(
				'post__in' => $post_ids,
				'status'   => 'approve',
			)
		);

		$ratings = array();

		foreach ( $comments as $comment ) {
			$rating = (int) get_comment_meta( $comment->comment_ID, self::META_KEY, true );

			if ( $rating < 1 || $rating > 5 ) {
				continue;
			}

			$ratings[ (int) $comment->comment_post_ID ][] = $rating;
		}

		$averages = array();

		foreach ( $ratings as $post_id => $values ) {
			$averages[ $post_id ] = array( array_sum( $values ) / count( $values ), count( $values ) );
		}

		return $averages;
	}
}
