<?php
/**
 * The custom books list table.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Tables;

use SmartBook\Admin\Pages\AbstractLabelsPage;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\CommentRating;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use SmartBook\Taxonomies\ShelfTaxonomy;
use WP_List_Table;
use WP_Post;
use WP_Query;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the "sb_book" catalog as a WP_List_Table: cover, title, post
 * status, author, ISBN, genre, shelf, reading status, rating, favorite,
 * and a row-level actions column, with column sorting, pagination,
 * search, status view tabs, and genre/shelf/favorite filters.
 *
 * This class only builds the query and renders rows; Admin\Pages\BooksPage
 * owns capability checks and processes bulk/row actions before ever
 * asking this table to prepare_items()/display().
 */
final class BooksListTable extends WP_List_Table {

	/**
	 * Reader ratings for the current page's rows, keyed by post ID --
	 * populated once in prepare_items() (CommentRating::averages(), a
	 * single batched query) rather than column_rating() calling
	 * CommentRating::average() per row, which would run one get_comments()
	 * query for every visible book.
	 *
	 * @var array<int, array{0: float, 1: int}>
	 */
	private array $ratings = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'book',
				'plural'   => 'books',
				'ajax'     => false,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'cover'       => __( 'Cover', 'smartbook' ),
			'title'       => __( 'Title', 'smartbook' ),
			'post_status' => __( 'Status', 'smartbook' ),
			'author'      => __( 'Author', 'smartbook' ),
			'isbn'        => __( 'ISBN', 'smartbook' ),
			'genre'       => __( 'Genre', 'smartbook' ),
			'shelf'       => __( 'Shelf', 'smartbook' ),
			'status'      => __( 'Reading Status', 'smartbook' ),
			'rating'      => __( 'Rating', 'smartbook' ),
			'favorite'    => __( 'Favorite', 'smartbook' ),
			'actions'     => __( 'Actions', 'smartbook' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_sortable_columns(): array {
		return array(
			'title'  => array( 'title', false ),
			'isbn'   => array( 'sb_isbn', false ),
			'status' => array( 'sb_status', false ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_bulk_actions(): array {
		if ( 'trash' === $this->current_status() ) {
			return array(
				'untrash' => __( 'Restore', 'smartbook' ),
				'delete'  => __( 'Delete Permanently', 'smartbook' ),
			);
		}

		// Label printing is reached from the "Labels" sidebar page instead
		// (Admin\Pages\LabelsPage), not from here.
		return array(
			'trash'     => __( 'Move to Trash', 'smartbook' ),
			'bulk_edit' => __( 'Bulk Edit', 'smartbook' ),
		);
	}

	/**
	 * Status view tabs (All / Published / Draft / ... / Trash) shown
	 * above the table, each linking back with a "post_status" query arg.
	 *
	 * {@inheritDoc}
	 */
	public function get_views(): array {
		$counts  = wp_count_posts( BookPostType::SLUG );
		$current = $this->requested_status();
		$base    = remove_query_arg( array( 'post_status', 'paged' ) );

		$labels = array(
			'publish' => __( 'Published', 'smartbook' ),
			'draft'   => __( 'Draft', 'smartbook' ),
			'pending' => __( 'Pending', 'smartbook' ),
			'private' => __( 'Private', 'smartbook' ),
			'trash'   => __( 'Trash', 'smartbook' ),
		);

		$total = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
			$total += (int) ( $counts->{$status} ?? 0 );
		}

		$views = array(
			'all' => $this->view_link( $base, '', __( 'All', 'smartbook' ), $total, '' === $current ),
		);

		foreach ( $labels as $status => $label ) {
			$count = (int) ( $counts->{$status} ?? 0 );

			if ( $count < 1 ) {
				continue;
			}

			$views[ $status ] = $this->view_link( $base, $status, $label, $count, $status === $current );
		}

		return $views;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $which Whether this is the "top" or "bottom" tablenav.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions sb-books-filters">';

		$this->render_taxonomy_filter( GenreTaxonomy::SLUG, 'sb_genre', __( 'All Genres', 'smartbook' ) );
		$this->render_taxonomy_filter( ShelfTaxonomy::SLUG, 'sb_shelf', __( 'All Shelves', 'smartbook' ) );

		printf(
			'<label class="sb-books-filters__favorite"><input type="checkbox" name="sb_favorite" value="1" %s /> %s</label>',
			// Read-only: only reflects the current filter state of this GET request back into the checkbox, no data is written.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			checked( isset( $_REQUEST['sb_favorite'] ) && '1' === $_REQUEST['sb_favorite'], true, false ),
			esc_html__( 'Favorites only', 'smartbook' )
		);

		submit_button( __( 'Filter', 'smartbook' ), '', 'sb_filter_action', false );

		echo '</div>';
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $this->get_columns(), array(), $sortable );

		$per_page     = $this->get_items_per_page( 'sb_books_per_page', 20 );
		$current_page = $this->get_pagenum();

		$args = array_merge(
			array(
				'post_type'      => BookPostType::SLUG,
				'post_status'    => '' === $this->current_status() ? array( 'publish', 'draft', 'pending', 'private' ) : $this->current_status(),
				'posts_per_page' => $per_page,
				'paged'          => $current_page,
				// Read-only: a search/list-table query filter, not a mutation.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				's'              => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			),
			BookPostType::author_scope_args()
		);

		$this->apply_sort_args( $args );
		$this->apply_filter_args( $args );

		$query = new WP_Query( $args );

		$this->items   = $query->posts;
		$this->ratings = CommentRating::averages( wp_list_pluck( $this->items, 'ID' ) );

		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => $query->max_num_pages,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e( 'No books found.', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="sb_book_id[]" id="sb-cb-%1$d" value="%1$d" />', $item->ID );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every column this table defines has its own dedicated column_*()
	 * method below (WP_List_Table::single_row_columns() discovers them
	 * by name via method_exists() before ever falling back to this
	 * method), so this is only reached for a genuinely unknown column.
	 *
	 * @param WP_Post $item        Current row.
	 * @param string  $column_name Column being rendered.
	 */
	public function column_default( $item, $column_name ): string {
		return '&#8212;';
	}

	/**
	 * Title column, linking to the custom Edit Book page.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_title( $item ): string {
		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>',
			esc_url( $this->edit_book_link( $item->ID ) ),
			esc_html( get_the_title( $item ) )
		);
	}

	/**
	 * Post status badge (Published/Draft/Pending Review/Private/Trash) --
	 * distinct from column_status()'s "Reading Status" (the "sb_status"
	 * meta) -- needed because the "All" view tab (see get_views()) mixes
	 * every non-trash status together, with nothing otherwise showing
	 * which of them a given row actually has.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_post_status( WP_Post $item ): string {
		$labels = array(
			'publish' => __( 'Published', 'smartbook' ),
			'draft'   => __( 'Draft', 'smartbook' ),
			'pending' => __( 'Pending Review', 'smartbook' ),
			'private' => __( 'Private', 'smartbook' ),
			'trash'   => __( 'Trash', 'smartbook' ),
		);

		$status = $item->post_status;
		$label  = $labels[ $status ] ?? ucfirst( $status );

		return sprintf(
			'<span class="sb-badge sb-badge--post-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Author terms.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_author( WP_Post $item ): string {
		return $this->terms_list( $item->ID, AuthorTaxonomy::SLUG );
	}

	/**
	 * Genre terms.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_genre( WP_Post $item ): string {
		return $this->terms_list( $item->ID, GenreTaxonomy::SLUG );
	}

	/**
	 * Shelf terms.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_shelf( WP_Post $item ): string {
		return $this->terms_list( $item->ID, ShelfTaxonomy::SLUG );
	}

	/**
	 * Cover thumbnail, or a placeholder glyph when the book has none.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_cover( WP_Post $item ): string {
		if ( has_post_thumbnail( $item->ID ) ) {
			return (string) get_the_post_thumbnail(
				$item->ID,
				array( 40, 60 ),
				array(
					'class' => 'sb-books-table__cover',
					'alt'   => '',
				)
			);
		}

		return '<span class="sb-books-table__cover sb-books-table__cover--placeholder" aria-hidden="true">&#128214;</span>';
	}

	/**
	 * ISBN-10 meta value.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_isbn( WP_Post $item ): string {
		$isbn = (string) get_post_meta( $item->ID, 'sb_isbn', true );

		return '' !== $isbn ? esc_html( $isbn ) : '&#8212;';
	}

	/**
	 * Reading-status badge.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_status( WP_Post $item ): string {
		$status = (string) get_post_meta( $item->ID, 'sb_status', true );
		$labels = self::status_labels();

		if ( '' === $status || ! isset( $labels[ $status ] ) ) {
			return '&#8212;';
		}

		return sprintf(
			'<span class="sb-badge sb-badge--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $labels[ $status ] )
		);
	}

	/**
	 * Average reader star rating (from the $ratings cache prepare_items()
	 * populates via CommentRating::averages(), front-end commenters' own
	 * ratings), rounded to the nearest whole star, plus how many ratings
	 * it's based on. An em dash when nobody has rated the book yet.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_rating( WP_Post $item ): string {
		[ $average, $count ] = $this->ratings[ $item->ID ] ?? array( 0.0, 0 );

		if ( 0 === $count ) {
			return '&#8212;';
		}

		$rounded = (int) round( $average );

		return sprintf(
			'<span class="sb-books-table__rating" aria-label="%1$s">%2$s</span> <span class="sb-books-table__rating-count">(%3$d)</span>',
			esc_attr(
				sprintf(
					/* translators: %s: average rating out of 5, one decimal place. */
					__( '%s out of 5', 'smartbook' ),
					number_format_i18n( $average, 1 )
				)
			),
			esc_html( str_repeat( '★', $rounded ) . str_repeat( '☆', 5 - $rounded ) ),
			$count
		);
	}

	/**
	 * Favorite indicator.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_favorite( WP_Post $item ): string {
		$favorite = '1' === (string) get_post_meta( $item->ID, 'sb_favorite', true );

		if ( ! $favorite ) {
			return '<span class="sb-books-table__favorite" aria-hidden="true">&#8212;</span>';
		}

		return sprintf(
			'<span class="sb-books-table__favorite sb-books-table__favorite--on" aria-label="%s">&#9829;</span>',
			esc_attr__( 'Favorite', 'smartbook' )
		);
	}

	/**
	 * Row-level Edit / Trash / Restore / Delete Permanently buttons.
	 *
	 * @param WP_Post $item Current row.
	 */
	public function column_actions( WP_Post $item ): string {
		$links      = array();
		$is_trashed = 'trash' === $item->post_status;

		$links[] = sprintf(
			'<a class="button button-small" href="%s">%s</a>',
			esc_url( $this->edit_book_link( $item->ID ) ),
			esc_html__( 'Edit', 'smartbook' )
		);

		if ( ! $is_trashed ) {
			$view_url = $this->view_book_link( $item );

			if ( '' !== $view_url ) {
				$links[] = sprintf(
					'<a class="button button-small" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $view_url ),
					esc_html( 'publish' === $item->post_status ? __( 'View', 'smartbook' ) : __( 'Preview', 'smartbook' ) )
				);
			}
		}

		if ( $is_trashed ) {
			$links[] = sprintf(
				'<a class="button button-small" href="%s">%s</a>',
				esc_url( $this->row_action_url( 'untrash', $item->ID ) ),
				esc_html__( 'Restore', 'smartbook' )
			);
			$links[] = sprintf(
				'<a class="button button-small sb-books-table__delete" href="%s" data-sb-confirm="%s">%s</a>',
				esc_url( $this->row_action_url( 'delete', $item->ID ) ),
				esc_attr__( 'Permanently delete this book? This cannot be undone.', 'smartbook' ),
				esc_html__( 'Delete Permanently', 'smartbook' )
			);
		} else {
			$links[] = sprintf(
				'<a class="button button-small sb-books-table__delete" href="%s" data-sb-confirm="%s">%s</a>',
				esc_url( $this->row_action_url( 'trash', $item->ID ) ),
				esc_attr__( 'Move this book to Trash?', 'smartbook' ),
				esc_html__( 'Trash', 'smartbook' )
			);
		}

		return '<div class="sb-books-table__actions">' . implode( ' ', $links ) . '</div>';
	}

	/**
	 * URL to the custom Edit Book page (Admin\Pages\EditBookPage) for a
	 * given book -- not get_edit_post_link()/the native post editor,
	 * which EditBookPage's own redirect_native_edit() bounces back here
	 * anyway; linking straight to it avoids that extra redirect hop.
	 */
	private function edit_book_link( int $post_id ): string {
		return add_query_arg(
			array(
				'page'    => 'sb_edit_book',
				'book_id' => $post_id,
			),
			AbstractLabelsPage::print_nonce_action( $page_slug )
		);
	}

	/**
	 * URL to view a book on the live front-end (its permalink once
	 * published, WordPress's own draft-preview link otherwise). Not
	 * called for a trashed book -- see column_actions() -- since neither
	 * link means anything for one.
	 */
	private function view_book_link( WP_Post $item ): string {
		if ( 'publish' === $item->post_status ) {
			return (string) get_permalink( $item );
		}

		$preview_link = get_preview_post_link( $item );

		return null !== $preview_link ? $preview_link : '';
	}

	/**
	 * Build a nonce-protected URL for a single-row trash/untrash/delete
	 * action, reusing the same "bulk-books" nonce action the bulk action
	 * dropdown itself uses.
	 *
	 * @param string $action  One of "trash", "untrash", or "delete".
	 * @param int    $post_id Book post ID.
	 */
	private function row_action_url( string $action, int $post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'sb_books',
					'action'     => $action,
					'sb_book_id' => array( $post_id ),
				),
				admin_url( 'admin.php' )
			),
			'bulk-' . $this->_args['plural']
		);
	}

	/**
	 * Render a taxonomy term <select> filter.
	 *
	 * @param string $taxonomy    Taxonomy slug to list terms for.
	 * @param string $request_key Query arg the selected term is read from/written to.
	 * @param string $all_label   Label for the "no filter" option.
	 */
	private function render_taxonomy_filter( string $taxonomy, string $request_key, string $all_label ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		// Read-only: reflects the current filter selection back into the <select>, not a mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_REQUEST[ $request_key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $request_key ] ) ) : '';

		printf( '<select name="%s">', esc_attr( $request_key ) );
		printf( '<option value="">%s</option>', esc_html( $all_label ) );

		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $term->slug ),
				selected( $selected, $term->slug, false ),
				esc_html( $term->name )
			);
		}

		echo '</select>';
	}

	/**
	 * Comma-separated list of term names for a taxonomy on one post.
	 *
	 * @param int    $post_id  Book post ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	private function terms_list( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '&#8212;';
		}

		return esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
	}

	/**
	 * Apply the requested sort column/direction to a WP_Query args array.
	 *
	 * @param array<string, mixed> $args WP_Query args, modified in place.
	 */
	private function apply_sort_args( array &$args ): void {
		// Read-only: sort column/direction for a query, not a mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';

		if ( in_array( $orderby, array( 'sb_isbn', 'sb_status' ), true ) ) {
			$args['meta_key'] = $orderby; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value';
			$args['order']    = $order;

			return;
		}

		$args['orderby'] = 'title' === $orderby ? 'title' : 'date';
		$args['order']   = $order;
	}

	/**
	 * Apply the genre/shelf/favorite filters to a WP_Query args array.
	 *
	 * @param array<string, mixed> $args WP_Query args, modified in place.
	 */
	private function apply_filter_args( array &$args ): void {
		$tax_query = array();

		foreach ( array(
			GenreTaxonomy::SLUG => 'sb_genre',
			ShelfTaxonomy::SLUG => 'sb_shelf',
		) as $taxonomy => $request_key ) {
			// Read-only: taxonomy filter for a query, not a mutation.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = isset( $_REQUEST[ $request_key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $request_key ] ) ) : '';

			if ( '' !== $slug ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slug,
				);
			}
		}

		if ( array() !== $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// Read-only: favorites-only filter for a query, not a mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['sb_favorite'] ) && '1' === $_REQUEST['sb_favorite'] ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => 'sb_favorite',
					'value' => '1',
				),
			);
		}
	}

	/**
	 * The currently requested post_status, or '' for "All".
	 */
	private function requested_status(): string {
		// Read-only: status-tab filter for a query, not a mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : '';

		return in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'trash' ), true ) ? $status : '';
	}

	/**
	 * Alias kept for readability at call sites.
	 */
	private function current_status(): string {
		return $this->requested_status();
	}

	/**
	 * Build one status view tab link.
	 *
	 * @param string $base_url Base URL to append the "post_status" query arg to.
	 * @param string $status   Post status the tab links to, or '' for "All".
	 * @param string $label    Tab label.
	 * @param int    $count    Post count shown next to the label.
	 * @param bool   $current  Whether this tab represents the currently requested status.
	 */
	private function view_link( string $base_url, string $status, string $label, int $count, bool $current ): string {
		$url = '' === $status ? $base_url : add_query_arg( 'post_status', $status, $base_url );

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( (string) $count )
		);
	}

	/**
	 * Reading-status meta value => translated label. Public so
	 * Admin\Pages\BooksPage can reuse the same options for its bulk-edit
	 * form instead of maintaining a second copy.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			'unread'    => __( 'Unread', 'smartbook' ),
			'reading'   => __( 'Reading', 'smartbook' ),
			'read'      => __( 'Read', 'smartbook' ),
			'on_hold'   => __( 'On Hold', 'smartbook' ),
			'abandoned' => __( 'Abandoned', 'smartbook' ),
		);
	}
}
