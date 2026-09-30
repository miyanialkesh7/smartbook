<?php
/**
 * Base class for printable label pages.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\PostTypes\BookPostType;
use SmartBook\Taxonomies\ShelfTaxonomy;
use WP_Post;

/**
 * Shared two-step, GET-only (read-only, so no nonce is needed) flow for
 * printable label pages: pick which books to print labels for, then a
 * print-optimized A4 sheet of labels for the chosen books.
 *
 * Concrete subclasses only describe what makes their label kind
 * different (page slug/title, which image(s) a label carries and how to
 * ensure each exists, and any extra per-label content); the selection
 * checklist, print-sheet shell, and book-lookup plumbing live here once
 * instead of being duplicated per label kind.
 */
abstract class AbstractLabelsPage {

	/**
	 * Admin page slug, e.g. "sb_qr_labels".
	 */
	abstract protected function page_slug(): string;

	/**
	 * Page heading, e.g. __( 'QR Labels', 'smartbook' ).
	 */
	abstract protected function page_title(): string;

	/**
	 * One-line instruction shown above the selection checklist.
	 */
	abstract protected function selection_intro(): string;

	/**
	 * The image(s) to print on one book's label -- most label kinds
	 * (QrLabelsPage, BarcodeLabelsPage) return exactly one; a kind
	 * combining more than one image per label (AllLabelsPage) returns
	 * more. Responsible for making sure each image actually exists
	 * (generating it if not) before returning its URL; an entry whose
	 * "url" comes back '' is skipped when the label is rendered.
	 *
	 * @param int $post_id Book post ID.
	 *
	 * @return array<int, array{url: string, alt: string}>
	 */
	abstract protected function images( int $post_id ): array;

	/**
	 * Render the page: the print sheet when "sb_print_labels=1" is
	 * present, otherwise the book selection checklist.
	 */
	public function render(): void {
		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smartbook' ) );
		}

		if ( isset( $_GET['sb_print_labels'] ) && '1' === $_GET['sb_print_labels'] ) {
			// This GET request has the side effect of generating a label
			// image on disk (ensure_asset()), so it still needs a nonce
			// even though nothing is written to the database.
			check_admin_referer( self::print_nonce_action( $this->page_slug() ) );

			$this->render_print_sheet( $this->requested_ids() );

			return;
		}

		$this->render_selection_form();
	}

	/**
	 * Nonce action shared by the selection form and every generated
	 * print-sheet link for a given label page slug. Public so
	 * Admin\Tables\BooksListTable and Admin\Pages\BooksPage can build
	 * matching nonce-protected URLs without needing a page instance.
	 *
	 * @param string $page_slug Admin page slug.
	 */
	public static function print_nonce_action( string $page_slug ): string {
		return 'sb_print_labels_' . $page_slug;
	}

	/**
	 * Extra markup (already escaped) rendered on a label after the
	 * title, before the shelf line. Empty by default.
	 *
	 * @param int $post_id Book post ID.
	 */
	protected function extra_label_content( int $post_id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- part of the overridable contract; unused only in this no-op default, see BarcodeLabelsPage's override.
		return '';
	}

	/**
	 * Extra class(es) on the print sheet's grid wrapper, beyond the base
	 * "sb-labels-grid" -- e.g. BookCardsPage adds a modifier for its
	 * wider per-book layout. Empty by default.
	 */
	protected function sheet_modifier_class(): string {
		return '';
	}

	/**
	 * Render the "which books?" checklist.
	 */
	private function render_selection_form(): void {
		$preselected = $this->requested_ids();
		$books       = $this->all_books();

		echo '<div class="wrap sb-admin-page">';
		printf( '<h1>%s</h1>', esc_html( $this->page_title() ) );
		printf( '<p>%s</p>', esc_html( $this->selection_intro() ) );

		if ( array() === $books ) {
			printf( '<p>%s</p>', esc_html__( 'No books found.', 'smartbook' ) );
			echo '</div>';

			return;
		}

		printf( '<form method="get" action="%s" class="sb-panel sb-label-selection">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( $this->page_slug() ) );
		echo '<input type="hidden" name="sb_print_labels" value="1" />';

		printf(
			'<p class="sb-label-select-list__select-all"><label><input type="checkbox" id="sb-select-all-books" /> %s</label></p>',
			esc_html__( 'Select All', 'smartbook' )
		);

		echo '<ul class="sb-label-select-list">';

		foreach ( $books as $book ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns an escaped attribute and selection_cover() returns escaped markup.
			printf(
				'<li><label><input type="checkbox" name="sb_book_id[]" value="%1$d" class="sb-label-select-list__checkbox" %2$s />%3$s<span class="sb-label-select-list__title">%4$s</span></label></li>',
				absint( $book->ID ),
				checked( array() === $preselected || in_array( $book->ID, $preselected, true ), true, false ),
				$this->selection_cover( $book ),
				esc_html( get_the_title( $book ) )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</ul>';

		submit_button( __( 'Print Labels', 'smartbook' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * A small cover thumbnail (or placeholder glyph) for one book's row
	 * in the selection checklist, already safe to echo directly.
	 *
	 * @param WP_Post $book Book post object.
	 */
	private function selection_cover( WP_Post $book ): string {
		if ( has_post_thumbnail( $book ) ) {
			return get_the_post_thumbnail( $book, array( 32, 48 ), array( 'class' => 'sb-label-select-list__cover' ) );
		}

		return '<span class="sb-label-select-list__cover sb-label-select-list__cover--placeholder" aria-hidden="true">&#128214;</span>';
	}

	/**
	 * Render the print-optimized A4 label sheet.
	 *
	 * @param int[] $ids Book IDs to print; empty means every book.
	 */
	private function render_print_sheet( array $ids ): void {
		$books = array() === $ids ? $this->all_books() : $this->books_by_ids( $ids );

		echo '<div class="wrap sb-admin-page sb-label-print-page">';

		echo '<div class="sb-no-print">';
		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: %s: label page title, e.g. "QR Labels". */
					__( '%s — Print Preview', 'smartbook' ),
					$this->page_title()
				)
			)
		);
		printf(
			'<p><button type="button" class="button button-primary" data-sb-print="1">%1$s</button> <a class="button" href="%2$s">%3$s</a></p>',
			esc_html__( 'Print', 'smartbook' ),
			esc_url( admin_url( 'admin.php?page=' . $this->page_slug() ) ),
			esc_html__( 'Back to Selection', 'smartbook' )
		);
		echo '</div>';

		if ( array() === $books ) {
			printf( '<p class="sb-no-print">%s</p>', esc_html__( 'No books selected.', 'smartbook' ) );
			echo '</div>';

			return;
		}

		printf(
			'<div class="%s">',
			esc_attr( trim( 'sb-labels-grid ' . $this->sheet_modifier_class() ) )
		);

		foreach ( $books as $book ) {
			$this->render_label( $book );
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render a single label: image(s), title, any extra content, and
	 * shelf. Overridable -- BookCardsPage replaces this entirely with a
	 * richer "library card" layout, while still reusing images(),
	 * term_names(), and every bit of surrounding selection/print-sheet
	 * plumbing here.
	 *
	 * @param WP_Post $book Book post object.
	 */
	protected function render_label( WP_Post $book ): void {
		$shelf = $this->shelf_names( $book->ID );

		echo '<div class="sb-label">';

		foreach ( $this->images( $book->ID ) as $image ) {
			if ( '' === $image['url'] ) {
				continue;
			}

			printf(
				'<img src="%1$s" alt="%2$s" class="sb-label__image" />',
				esc_url( $image['url'] ),
				esc_attr( $image['alt'] )
			);
		}

		printf( '<span class="sb-label__title">%s</span>', esc_html( get_the_title( $book ) ) );

		echo $this->extra_label_content( $book->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped fragment, see extra_label_content().

		if ( '' !== $shelf ) {
			printf( '<span class="sb-label__shelf">%s</span>', esc_html( $shelf ) );
		}

		echo '</div>';
	}

	/**
	 * Comma-separated shelf term names for a book.
	 *
	 * @param int $post_id Book post ID.
	 */
	private function shelf_names( int $post_id ): string {
		return $this->term_names( $post_id, ShelfTaxonomy::SLUG );
	}

	/**
	 * Comma-separated term names for one taxonomy on a book, '' if none
	 * (or the taxonomy isn't attached to it). Shared by shelf_names()
	 * here and BookCardsPage's own author/genre lines.
	 *
	 * @param int    $post_id Book post ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	protected function term_names( int $post_id, string $taxonomy ): string {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Book IDs requested via "sb_book_id[]".
	 *
	 * @return int[]
	 */
	private function requested_ids(): array {
		// Only parses which IDs were pre-selected/requested; render()
		// verifies the nonce itself before this list is ever used to
		// generate or print anything, see print_nonce_action().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['sb_book_id'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element is absint()'d below.
		$raw = wp_unslash( $_GET['sb_book_id'] );
		$raw = is_array( $raw ) ? $raw : array( $raw );

		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Every non-trash book, alphabetical by title.
	 *
	 * @return WP_Post[]
	 */
	private function all_books(): array {
		return get_posts(
			array_merge(
				array(
					'post_type'      => BookPostType::SLUG,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				),
				BookPostType::author_scope_args()
			)
		);
	}

	/**
	 * Specific books by ID, in the given order.
	 *
	 * @param int[] $ids Post IDs.
	 *
	 * @return WP_Post[]
	 */
	private function books_by_ids( array $ids ): array {
		return get_posts(
			array_merge(
				array(
					'post_type'      => BookPostType::SLUG,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'post__in'       => $ids,
					'orderby'        => 'post__in',
				),
				BookPostType::author_scope_args()
			)
		);
	}
}
