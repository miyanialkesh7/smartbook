<?php
/**
 * Book catalog statistics.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use SmartBook\MetaBoxes\BookFields;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use WP_Post;
use WP_Query;
use WP_User;

/**
 * Aggregates counts and chart-ready datasets from the book catalog, for
 * the dashboard cards/charts and (potentially) anywhere else that needs
 * the same figures. The underlying post collection is fetched once per
 * request and reused by every method, since a dashboard view typically
 * calls several of them.
 */
final class BookStats {

	/**
	 * Every non-trash book, fetched once and reused.
	 *
	 * @var WP_Post[]|null
	 */
	private ?array $posts = null;

	/**
	 * Total number of books in the catalog (any non-trash status).
	 */
	public function total(): int {
		return count( $this->posts() );
	}

	/**
	 * Number of books whose "sb_status" meta equals the given value.
	 *
	 * @param string $status Status value to match.
	 */
	public function count_by_status( string $status ): int {
		return count(
			array_filter(
				$this->posts(),
				static fn ( WP_Post $post ): bool => (string) get_post_meta( $post->ID, 'sb_status', true ) === $status
			)
		);
	}

	/**
	 * Number of books whose boolean meta flag (e.g. "sb_favorite",
	 * "sb_wishlist", "sb_borrowed") is set.
	 *
	 * @param string $meta_key Post meta key to check.
	 */
	public function count_by_flag( string $meta_key ): int {
		return count(
			array_filter(
				$this->posts(),
				static fn ( WP_Post $post ): bool => '1' === (string) get_post_meta( $post->ID, $meta_key, true )
			)
		);
	}

	/**
	 * Number of books currently on loan: "sb_borrowed" is set and
	 * "sb_returned" is not — a book stays counted as borrowed until it's
	 * explicitly marked returned, regardless of its return/reminder dates.
	 */
	public function count_active_borrows(): int {
		return count(
			array_filter(
				$this->posts(),
				static function ( WP_Post $post ): bool {
					return '1' === (string) get_post_meta( $post->ID, 'sb_borrowed', true )
						&& '1' !== (string) get_post_meta( $post->ID, 'sb_returned', true );
				}
			)
		);
	}

	/**
	 * Number of books with a pending "request to borrow" awaiting
	 * approval, for the "Borrowed Books" sidebar menu's notification
	 * bubble (see AdminMenu::register()). Deliberately does not reuse
	 * posts()/pending_borrow_requests() -- AdminMenu calls this while
	 * building the admin sidebar on *every* wp-admin screen, not just
	 * SmartBook's own pages, so it runs its own lightweight, ids-only
	 * query instead of hydrating the whole catalog on every backend
	 * request.
	 */
	public function count_pending_borrow_requests(): int {
		return $this->count_by_meta_query(
			array(
				array(
					'key'     => 'sb_borrow_request_user',
					'compare' => 'EXISTS',
				),
			)
		);
	}

	/**
	 * Every book with a pending "request to borrow" (see
	 * Frontend\BorrowRequestController), oldest request first.
	 *
	 * @return array<int, array{post_id: int, title: string, requester: string, requested_date: string}>
	 */
	public function pending_borrow_requests(): array {
		$rows = array();

		foreach ( $this->posts() as $post ) {
			$requester_id = (int) get_post_meta( $post->ID, 'sb_borrow_request_user', true );

			if ( $requester_id <= 0 ) {
				continue;
			}

			$requester = get_userdata( $requester_id );

			$rows[] = array(
				'post_id'        => $post->ID,
				'title'          => get_the_title( $post ),
				'requester'      => $requester instanceof WP_User ? $requester->display_name : __( '(deleted user)', 'smartbook' ),
				'requested_date' => (string) get_post_meta( $post->ID, 'sb_borrow_request_date', true ),
			);
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => strcmp( $a['requested_date'], $b['requested_date'] )
		);

		return $rows;
	}

	/**
	 * Books that need attention on the dashboard: lost copies, overdue
	 * loans (past their "sb_return_date"), and loans whose
	 * "sb_reminder" date has arrived. Returned books never appear.
	 * A book already flagged "overdue" is not also listed as a
	 * "reminder", to avoid showing it twice.
	 *
	 * Sorted most urgent first (overdue, then lost, then reminder), and
	 * chronologically within each group.
	 *
	 * @return array<int, array{post_id: int, title: string, borrowed_to: string, date: string, status: string}>
	 */
	public function borrow_alerts(): array {
		$today  = current_time( 'Y-m-d' );
		$alerts = array();

		foreach ( $this->posts() as $post ) {
			if ( '1' === (string) get_post_meta( $post->ID, 'sb_lost', true ) ) {
				$alerts[] = $this->borrow_alert_row( $post, 'lost', (string) get_post_meta( $post->ID, 'sb_return_date', true ) );
				continue;
			}

			$borrowed = '1' === (string) get_post_meta( $post->ID, 'sb_borrowed', true );
			$returned = '1' === (string) get_post_meta( $post->ID, 'sb_returned', true );

			if ( ! $borrowed || $returned ) {
				continue;
			}

			$return_date = (string) get_post_meta( $post->ID, 'sb_return_date', true );

			if ( '' !== $return_date && $return_date < $today ) {
				$alerts[] = $this->borrow_alert_row( $post, 'overdue', $return_date );
				continue;
			}

			$reminder_date = (string) get_post_meta( $post->ID, 'sb_reminder', true );

			if ( '' !== $reminder_date && $reminder_date <= $today ) {
				$alerts[] = $this->borrow_alert_row( $post, 'reminder', $return_date );
			}
		}

		usort(
			$alerts,
			static function ( array $a, array $b ): int {
				$rank      = array(
					'overdue'  => 0,
					'lost'     => 1,
					'reminder' => 2,
				);
				$rank_diff = $rank[ $a['status'] ] <=> $rank[ $b['status'] ];

				if ( 0 !== $rank_diff ) {
					return $rank_diff;
				}

				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $alerts;
	}

	/**
	 * Build one borrow_alerts() row.
	 *
	 * @param WP_Post $post   Book post.
	 * @param string  $status Alert status: "overdue", "lost", or "reminder".
	 * @param string  $date   Relevant date for the alert.
	 *
	 * @return array{post_id: int, title: string, borrowed_to: string, date: string, status: string}
	 */
	private function borrow_alert_row( WP_Post $post, string $status, string $date ): array {
		return array(
			'post_id'     => $post->ID,
			'title'       => get_the_title( $post ),
			'borrowed_to' => BookFields::borrowed_to_display( (string) get_post_meta( $post->ID, 'sb_borrowed_to', true ) ),
			'date'        => $date,
			'status'      => $status,
		);
	}

	/**
	 * Number of active loans with a pending return request awaiting
	 * admin confirmation, for the same sidebar bubble as
	 * count_pending_borrow_requests() (see its own doc comment for why
	 * this runs a lightweight, ids-only query instead of posts()).
	 * Matching just the "sb_return_request" meta is enough on its own --
	 * it's only ever set while a book is actively on loan
	 * (Frontend\BorrowRequestController::handle_return_request()'s
	 * is_borrowed_by() guard) and always cleared the moment a return is
	 * confirmed (Admin\Pages\BorrowedBooksPage::handle_mark_returned()),
	 * so there's no "sb_borrowed"/"sb_returned" case where it can be set
	 * on a loan that isn't still active.
	 */
	public function count_pending_return_requests(): int {
		return $this->count_by_meta_query(
			array(
				array(
					'key'   => 'sb_return_request',
					'value' => '1',
				),
			)
		);
	}

	/**
	 * Count of "sb_book" posts matching a meta_query, via a minimal,
	 * ids-only WP_Query -- no post hydration, no full-catalog PHP-side
	 * filtering. Backs the two sidebar-bubble counts above, which run on
	 * every wp-admin screen and so need to stay cheap regardless of
	 * catalog size.
	 *
	 * @param array<int, array<string, mixed>> $meta_query WP_Query meta_query clauses.
	 */
	private function count_by_meta_query( array $meta_query ): int {
		$query = new WP_Query(
			array(
				'post_type'      => BookPostType::SLUG,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Every book ever marked "sb_borrowed", filtered by loan status, for
	 * Admin\Pages\BorrowedBooksPage's management table. Unlike
	 * borrow_alerts() (only what needs attention right now), this
	 * includes ordinary on-time loans too. Sorted soonest-due first;
	 * books with no return date sort last.
	 *
	 * @param string $filter "active" (not yet returned), "return_requested" (active loans awaiting return confirmation), "returned", or "all".
	 *
	 * @return array<int, array{post_id: int, title: string, borrowed_to: string, borrow_date: string, return_date: string, reminder_date: string, lost: bool, returned: bool, overdue: bool, return_requested: bool}>
	 */
	public function borrowed_books( string $filter = 'active' ): array {
		$today = current_time( 'Y-m-d' );
		$rows  = array();

		foreach ( $this->posts() as $post ) {
			if ( '1' !== (string) get_post_meta( $post->ID, 'sb_borrowed', true ) ) {
				continue;
			}

			$returned         = '1' === (string) get_post_meta( $post->ID, 'sb_returned', true );
			$return_requested = '1' === (string) get_post_meta( $post->ID, 'sb_return_request', true );

			if ( 'active' === $filter && $returned ) {
				continue;
			}

			if ( 'returned' === $filter && ! $returned ) {
				continue;
			}

			if ( 'return_requested' === $filter && ( $returned || ! $return_requested ) ) {
				continue;
			}

			$return_date = (string) get_post_meta( $post->ID, 'sb_return_date', true );

			$rows[] = array(
				'post_id'          => $post->ID,
				'title'            => get_the_title( $post ),
				'borrowed_to'      => BookFields::borrowed_to_display( (string) get_post_meta( $post->ID, 'sb_borrowed_to', true ) ),
				'borrow_date'      => (string) get_post_meta( $post->ID, 'sb_borrow_date', true ),
				'return_date'      => $return_date,
				'reminder_date'    => (string) get_post_meta( $post->ID, 'sb_reminder', true ),
				'lost'             => '1' === (string) get_post_meta( $post->ID, 'sb_lost', true ),
				'returned'         => $returned,
				'overdue'          => ! $returned && '' !== $return_date && $return_date < $today,
				'return_requested' => $return_requested,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$a_date = '' !== $a['return_date'] ? $a['return_date'] : '9999-99-99';
				$b_date = '' !== $b['return_date'] ? $b['return_date'] : '9999-99-99';

				return strcmp( $a_date, $b_date );
			}
		);

		return $rows;
	}

	/**
	 * Book counts grouped by the year of their "sb_purchase_date" meta,
	 * ascending. Books without a purchase date are not counted, since
	 * there is nothing to chart them by.
	 *
	 * @return array<string, int> Year => count.
	 */
	public function books_per_year(): array {
		$years = array();

		foreach ( $this->posts() as $post ) {
			$date = (string) get_post_meta( $post->ID, 'sb_purchase_date', true );

			if ( '' === $date ) {
				continue;
			}

			$year           = substr( $date, 0, 4 );
			$years[ $year ] = ( $years[ $year ] ?? 0 ) + 1;
		}

		ksort( $years );

		return $years;
	}

	/**
	 * The ten most-used genre terms and their book counts, descending.
	 *
	 * @return array<string, int> Genre name => count.
	 */
	public function books_per_genre(): array {
		return $this->books_per_taxonomy( GenreTaxonomy::SLUG );
	}

	/**
	 * The ten most-used author terms and their book counts, descending.
	 *
	 * @return array<string, int> Author name => count.
	 */
	public function books_per_author(): array {
		return $this->books_per_taxonomy( AuthorTaxonomy::SLUG );
	}

	/**
	 * Books that entered "reading" or "read" status, bucketed by the
	 * month they were added to the catalog, for the last 12 months.
	 *
	 * @return array<string, int> "Y-m" => count, in chronological order.
	 */
	public function monthly_reading(): array {
		$months = array();
		$cursor = new DateTimeImmutable( 'first day of this month' );

		for ( $i = 11; $i >= 0; $i-- ) {
			$months[ $cursor->modify( "-{$i} months" )->format( 'Y-m' ) ] = 0;
		}

		foreach ( $this->posts() as $post ) {
			$status = (string) get_post_meta( $post->ID, 'sb_status', true );

			if ( ! in_array( $status, array( 'reading', 'read' ), true ) ) {
				continue;
			}

			$month = substr( $post->post_date, 0, 7 );

			if ( isset( $months[ $month ] ) ) {
				++$months[ $month ];
			}
		}

		return $months;
	}

	/**
	 * Term name => book count for a taxonomy, top ten by count.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return array<string, int>
	 */
	private function books_per_taxonomy( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 10,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$counts = array();

		foreach ( $terms as $term ) {
			$counts[ $term->name ] = (int) $term->count;
		}

		return $counts;
	}

	/**
	 * Every non-trash book, fetched once per request and cached.
	 *
	 * @return WP_Post[]
	 */
	private function posts(): array {
		if ( null === $this->posts ) {
			$this->posts = get_posts(
				array(
					'post_type'      => BookPostType::SLUG,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
		}

		return $this->posts;
	}
}
