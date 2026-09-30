<?php
/**
 * The "[smartbook_books]" shortcode.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Frontend;

use SmartBook\Core\Contracts\Hookable;
use SmartBook\MetaBoxes\BookFields;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\CommentRating;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use SmartBook\Taxonomies\PublisherTaxonomy;
use WP_Post;
use WP_Query;
use WP_Term;

use function sb_format_currency;
use function sb_option;

/**
 * Renders every published book as a filterable, searchable, paginated
 * grid of cards: cover, title (linked to the book's own page, where
 * Frontend\BookContentDisplay appends its details panel), author/genre,
 * rating, reading status, and price. A filter bar above the grid lets a
 * visitor search by title and narrow by author/publisher/genre, all via
 * plain GET query args (so results are bookmarkable/shareable and work
 * with JavaScript off). Core\Activator publishes a page containing this
 * shortcode on activation, so a site has a working "All Books" listing
 * immediately.
 */
final class BooksShortcode implements Hookable {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'smartbook_books';

	/**
	 * Books shown per page.
	 */
	private const PER_PAGE = 12;

	/**
	 * Reader ratings for the current page's books, keyed by post ID --
	 * populated once in render() (CommentRating::averages(), a single
	 * batched query) rather than rating() calling CommentRating::average()
	 * per book, which would run one get_comments() query for every book
	 * on the page.
	 *
	 * @var array<int, array{0: float, 1: int}>
	 */
	private array $ratings = array();

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback: the filter bar, the current page of matching
	 * published books (title order, unless searching), and pagination.
	 */
	public function render(): string {
		$filters = $this->current_filters();
		$query   = $this->query( $filters );

		$this->ratings = CommentRating::averages( wp_list_pluck( $query->posts, 'ID' ) );

		$html  = '<div class="sb-books">';
		$html .= $this->render_filter_bar( $filters );

		if ( ! $query->have_posts() ) {
			$html .= $this->render_empty_state( $filters );
			$html .= '</div>';

			return $html;
		}

		$html .= '<div class="sb-books-list">';

		foreach ( $query->posts as $book ) {
			$html .= $this->render_book( $book );
		}

		$html .= '</div>';
		$html .= $this->render_pagination( $query, $filters );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Run the filtered/searched/paginated query.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 */
	private function query( array $filters ): WP_Query {
		$args = array(
			'post_type'           => BookPostType::SLUG,
			'post_status'         => 'publish',
			'posts_per_page'      => self::PER_PAGE,
			'paged'               => $filters['paged'],
			'orderby'             => 'title',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
		);

		if ( '' !== $filters['search'] ) {
			$args['s'] = $filters['search'];
		}

		$tax_query = $this->tax_query( $filters );

		if ( array() !== $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a handful of exact-slug clauses on a small custom taxonomy, not an unbounded query.
		}

		return new WP_Query( $args );
	}

	/**
	 * Build the tax_query fragment for whichever of author/publisher/
	 * genre are currently set, empty when none are.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 *
	 * @return array<int|string, mixed>
	 */
	private function tax_query( array $filters ): array {
		$map = array(
			'author'    => AuthorTaxonomy::SLUG,
			'publisher' => PublisherTaxonomy::SLUG,
			'genre'     => GenreTaxonomy::SLUG,
		);

		$clauses = array();

		foreach ( $map as $filter_key => $taxonomy ) {
			if ( '' === $filters[ $filter_key ] ) {
				continue;
			}

			$clauses[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $filters[ $filter_key ],
			);
		}

		if ( array() === $clauses ) {
			return array();
		}

		return array_merge( array( 'relation' => 'AND' ), $clauses );
	}

	/**
	 * Render the search/filter bar.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 */
	private function render_filter_bar( array $filters ): string {
		$html  = '<form method="get" class="sb-books-filter">';
		$html .= sprintf(
			'<input type="search" name="sb_search" class="sb-books-filter__search" placeholder="%1$s" value="%2$s" />',
			esc_attr__( 'Search books…', 'smartbook' ),
			esc_attr( $filters['search'] )
		);

		$html .= $this->render_taxonomy_select( 'sb_author', AuthorTaxonomy::SLUG, $filters['author'], __( 'All Authors', 'smartbook' ) );
		$html .= $this->render_taxonomy_select( 'sb_publisher', PublisherTaxonomy::SLUG, $filters['publisher'], __( 'All Publishers', 'smartbook' ) );
		$html .= $this->render_taxonomy_select( 'sb_genre', GenreTaxonomy::SLUG, $filters['genre'], __( 'All Genres', 'smartbook' ) );

		$html .= sprintf( '<button type="submit" class="sb-books-filter__submit">%s</button>', esc_html__( 'Filter', 'smartbook' ) );

		if ( $this->has_active_filters( $filters ) ) {
			$html .= sprintf(
				'<a class="sb-books-filter__reset" href="%s">%s</a>',
				esc_url( $this->base_url() ),
				esc_html__( 'Reset', 'smartbook' )
			);
		}

		$html .= '</form>';

		return $html;
	}

	/**
	 * Render one taxonomy <select>, '' when that taxonomy has no terms.
	 */
	private function render_taxonomy_select( string $field_name, string $taxonomy, string $current, string $all_label ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}

		$html = sprintf( '<select name="%s" class="sb-books-filter__select">', esc_attr( $field_name ) );
		$html .= sprintf( '<option value="">%s</option>', esc_html( $all_label ) );

		/** @var WP_Term $term */
		foreach ( $terms as $term ) {
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $term->slug ),
				selected( $current, $term->slug, false ),
				esc_html( $term->name )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * The "no results" message, offering to clear filters when any are active.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 */
	private function render_empty_state( array $filters ): string {
		if ( ! $this->has_active_filters( $filters ) ) {
			return sprintf( '<p class="sb-books-list__empty">%s</p>', esc_html__( 'No books found.', 'smartbook' ) );
		}

		return sprintf(
			'<p class="sb-books-list__empty">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'No books match your search/filters.', 'smartbook' ),
			esc_url( $this->base_url() ),
			esc_html__( 'Clear filters', 'smartbook' )
		);
	}

	/**
	 * Render the previous/next pagination bar, '' when there's only one page.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 */
	private function render_pagination( WP_Query $query, array $filters ): string {
		$total = (int) $query->max_num_pages;

		if ( $total <= 1 ) {
			return '';
		}

		$current = max( 1, min( $filters['paged'], $total ) );

		$html  = sprintf( '<nav class="sb-books-pagination" aria-label="%s">', esc_attr__( 'Books pagination', 'smartbook' ) );
		$html .= $this->pagination_link( $filters, $current, -1, __( '← Previous', 'smartbook' ), $total );

		$html .= sprintf(
			'<span class="sb-books-pagination__status">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: current page number, 2: total number of pages. */
					__( 'Page %1$d of %2$d', 'smartbook' ),
					$current,
					$total
				)
			)
		);

		$html .= $this->pagination_link( $filters, $current, 1, __( 'Next →', 'smartbook' ), $total );
		$html .= '</nav>';

		return $html;
	}

	/**
	 * Render one pagination link (or a disabled span at either end of
	 * the page range).
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 * @param int    $current Current page number.
	 * @param int    $step    -1 for "previous", 1 for "next".
	 * @param string $label   Link text.
	 * @param int    $total   Total number of pages.
	 */
	private function pagination_link( array $filters, int $current, int $step, string $label, int $total ): string {
		$target = $current + $step;
		$class  = 1 === $step ? 'sb-books-pagination__link--next' : 'sb-books-pagination__link--prev';

		if ( $target < 1 || $target > $total ) {
			return sprintf(
				'<span class="sb-books-pagination__link %s sb-books-pagination__link--disabled">%s</span>',
				esc_attr( $class ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<a class="sb-books-pagination__link %1$s" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( $this->pagination_url( $filters, $target ) ),
			esc_html( $label )
		);
	}

	/**
	 * URL for a given page number, preserving the current search/filter values.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 */
	private function pagination_url( array $filters, int $page ): string {
		$args = array( 'sb_paged' => $page );

		$map = array(
			'sb_search'    => 'search',
			'sb_author'    => 'author',
			'sb_publisher' => 'publisher',
			'sb_genre'     => 'genre',
		);

		foreach ( $map as $query_key => $filter_key ) {
			if ( '' !== $filters[ $filter_key ] ) {
				$args[ $query_key ] = $filters[ $filter_key ];
			}
		}

		return (string) add_query_arg( $args, $this->base_url() );
	}

	/**
	 * Whether any search/filter value is currently active.
	 *
	 * @param array{search: string, author: string, publisher: string, genre: string, paged: int} $filters Current filter values.
	 */
	private function has_active_filters( array $filters ): bool {
		return '' !== $filters['search']
			|| '' !== $filters['author']
			|| '' !== $filters['publisher']
			|| '' !== $filters['genre'];
	}

	/**
	 * This page's own clean permalink (no query args), the base every
	 * filter/pagination link is built from.
	 */
	private function base_url(): string {
		return (string) get_permalink();
	}

	/**
	 * Read the current search/filter/page values from the query string.
	 * Read-only and non-destructive (this only ever selects what to
	 * display), so no nonce is needed -- the same reasoning
	 * Admin\Pages\AbstractLabelsPage's GET-only flow already relies on.
	 *
	 * @return array{search: string, author: string, publisher: string, genre: string, paged: int}
	 */
	private function current_filters(): array {
		return array(
			'search'    => isset( $_GET['sb_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sb_search'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'author'    => isset( $_GET['sb_author'] ) ? sanitize_title( wp_unslash( $_GET['sb_author'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'publisher' => isset( $_GET['sb_publisher'] ) ? sanitize_title( wp_unslash( $_GET['sb_publisher'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'genre'     => isset( $_GET['sb_genre'] ) ? sanitize_title( wp_unslash( $_GET['sb_genre'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged'     => isset( $_GET['sb_paged'] ) ? max( 1, absint( $_GET['sb_paged'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Render a single book card.
	 */
	private function render_book( WP_Post $book ): string {
		$html  = '<div class="sb-book">';
		$html .= $this->cover( $book );
		$html .= '<div class="sb-book__body">';
		$html .= sprintf(
			'<a class="sb-book__title" href="%s">%s</a>',
			esc_url( get_permalink( $book ) ),
			esc_html( get_the_title( $book ) )
		);

		$meta = $this->meta_parts( $book );

		if ( array() !== $meta ) {
			$html .= sprintf( '<div class="sb-book__meta">%s</div>', implode( ' &middot; ', $meta ) );
		}

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * The book's cover image, linked-page-ready markup straight from
	 * core, or a placeholder glyph when it has none.
	 */
	private function cover( WP_Post $book ): string {
		if ( has_post_thumbnail( $book ) ) {
			return get_the_post_thumbnail( $book, 'medium', array( 'class' => 'sb-book__cover' ) );
		}

		return '<span class="sb-book__cover sb-book__cover--placeholder" aria-hidden="true">&#128214;</span>';
	}

	/**
	 * Already-escaped meta fragments for one book, in display order,
	 * skipping any that are empty.
	 *
	 * @return string[]
	 */
	private function meta_parts( WP_Post $book ): array {
		$parts = array_filter(
			array(
				$this->terms( $book->ID, AuthorTaxonomy::SLUG ),
				$this->terms( $book->ID, GenreTaxonomy::SLUG ),
				$this->rating( $book->ID ),
				sb_option( 'enable_reading_tracker', true ) ? $this->status_label( $book->ID ) : '',
				$this->price( $book->ID ),
			),
			static fn ( string $part ): bool => '' !== $part
		);

		return array_values( $parts );
	}

	/**
	 * Comma-separated term names for one taxonomy, already escaped.
	 */
	private function terms( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		return esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * A star rating, already escaped -- the average reader rating (from
	 * the $ratings cache render() populates via CommentRating::averages(),
	 * front-end commenters' own ratings), rounded to the nearest whole
	 * star. Omitted when nobody has rated the book yet.
	 */
	private function rating( int $post_id ): string {
		[ $average, $count ] = $this->ratings[ $post_id ] ?? array( 0.0, 0 );

		if ( 0 === $count ) {
			return '';
		}

		$rounded = (int) round( $average );

		return esc_html( str_repeat( '★', $rounded ) . str_repeat( '☆', 5 - $rounded ) );
	}

	/**
	 * Translated reading-status label, already escaped, reusing the same
	 * option labels the edit-screen meta box uses.
	 */
	private function status_label( int $post_id ): string {
		$status  = (string) get_post_meta( $post_id, 'sb_status', true );
		$options = BookFields::definitions()['sb_status']['options'] ?? array();

		return ( '' !== $status && isset( $options[ $status ] ) ) ? esc_html( $options[ $status ] ) : '';
	}

	/**
	 * The book's price formatted per the site's currency setting,
	 * already escaped, omitted when unset.
	 */
	private function price( int $post_id ): string {
		$price = (float) get_post_meta( $post_id, 'sb_price', true );

		return $price > 0 ? esc_html( sb_format_currency( $price ) ) : '';
	}
}
