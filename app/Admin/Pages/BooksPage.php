<?php
/**
 * The SmartBook books list page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Admin\Support\RedirectsWithNotice;
use SmartBook\Admin\Tables\BooksListTable;
use SmartBook\Core\Contracts\Hookable;
use SmartBook\MetaBoxes\BookFields;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Taxonomies\GenreTaxonomy;
use SmartBook\Taxonomies\ShelfTaxonomy;

/**
 * Renders the custom books catalog (Admin\Tables\BooksListTable) and
 * processes the actions it can trigger: single/bulk trash, restore,
 * permanent delete, and a two-step bulk edit (an intermediate field
 * picker, then the actual update).
 *
 * Every mutating action is nonce-checked (the table's own "bulk-books"
 * nonce, reused for single-row action links too) and capability-checked
 * per post via current_user_can( 'edit_post' | 'delete_post', $id ),
 * which resolves through BookPostType's custom capability mapping.
 *
 * Trash/untrash/delete and the bulk-edit "Apply Changes" submission are
 * both processed on "admin_init" (maybe_process_action()), not inline in
 * render() -- render() is this page's add_submenu_page() callback,
 * invoked well after wp-admin's own header/scripts have already been
 * output, so a wp_safe_redirect() attempted from inside it always fails
 * with a "headers already sent" warning (the redirect Location header
 * never actually reaches the browser, but the exit; still runs, leaving
 * a broken half-rendered page). "admin_init" fires before any of that
 * output starts, so the exact same redirect_with_notice() calls work
 * correctly from there instead.
 */
final class BooksPage implements Hookable {

	use RedirectsWithNotice;

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'sb_books';

	/**
	 * Nonce action for the intermediate bulk-edit form's own submission.
	 */
	private const BULK_EDIT_NONCE_ACTION = 'sb_bulk_edit_apply';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_process_action' ) );
	}

	/**
	 * Process a pending trash/untrash/delete row action or a bulk-edit
	 * "Apply Changes" submission, if this request is actually for this
	 * page -- both end in a redirect (see this class's own doc comment
	 * for why that has to happen here, on "admin_init", rather than
	 * inline in render()). Anything else (a plain page view, or the
	 * bulk-edit *picker* itself) is left entirely to render().
	 */
	public function maybe_process_action(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			return;
		}

		$ids = $this->requested_ids();

		if ( $this->is_bulk_edit_apply_request() ) {
			$this->handle_bulk_edit_apply( $ids );
		}

		$action = ( new BooksListTable() )->current_action();

		if ( in_array( $action, array( 'trash', 'untrash', 'delete' ), true ) && array() !== $ids ) {
			$this->handle_row_action( $action, $ids );
		}
	}

	/**
	 * Register this page's "Screen Options" tab content: a "Number of
	 * items per page" field (BooksListTable::prepare_items() already
	 * reads back the "sb_books_per_page" user option this saves, via
	 * WP_List_Table::get_items_per_page()) and per-column show/hide
	 * checkboxes for every column BooksListTable defines. Hooked onto
	 * this page's own "load-{hook}" action from AdminMenu::register() --
	 * without an "option" registered here (or a "manage_{screen}_columns"
	 * filter), WP_Screen::show_screen_options() has nothing to show and
	 * the tab doesn't render at all.
	 */
	public function add_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Books', 'smartbook' ),
				'default' => 20,
				'option'  => 'sb_books_per_page',
			)
		);

		$screen = get_current_screen();

		if ( null !== $screen ) {
			add_filter( "manage_{$screen->id}_columns", array( new BooksListTable(), 'get_columns' ) );
		}
	}

	/**
	 * Render the page: dispatches to the bulk-edit picker, or renders the
	 * list table. Trash/untrash/delete and the bulk-edit "Apply Changes"
	 * submission are already handled by maybe_process_action() on
	 * "admin_init" (and end in a redirect, so this point is never reached
	 * for those) -- only the bulk-edit *picker* itself is rendered here.
	 */
	public function render(): void {
		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smartbook' ) );
		}

		$ids = $this->requested_ids();

		$table  = new BooksListTable();
		$action = $table->current_action();

		if ( 'bulk_edit' === $action && array() !== $ids ) {
			check_admin_referer( 'bulk-books' );
			$this->render_bulk_edit_form( $ids );
			return;
		}

		$table->prepare_items();

		echo '<div class="wrap sb-admin-page">';
		printf( '<h1>%s ', esc_html__( 'Books', 'smartbook' ) );
		printf(
			'<a href="%s" class="page-title-action">%s</a></h1>',
			esc_url( admin_url( 'admin.php?page=sb_add_book' ) ),
			esc_html__( 'Add New', 'smartbook' )
		);

		$this->render_notice();

		echo '<form method="post">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
		$table->views();
		$table->search_box( __( 'Search Books', 'smartbook' ), 'sb_book' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Process a trash/untrash/delete action (single row or bulk) and
	 * redirect back with a result notice.
	 *
	 * @param string $action Requested action: "trash", "untrash", or "delete".
	 * @param int[]  $ids    Post IDs to act on.
	 */
	private function handle_row_action( string $action, array $ids ): void {
		check_admin_referer( 'bulk-books' );

		$count = 0;

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}

			$success = match ( $action ) {
				'trash'   => (bool) wp_trash_post( $id ),
				'untrash' => (bool) wp_untrash_post( $id ),
				'delete'  => (bool) wp_delete_post( $id, true ),
				default   => false,
			};

			if ( $success ) {
				++$count;
			}
		}

		$this->redirect_with_notice( 'success', $this->row_action_message( $action, $count ) );
	}

	/**
	 * Translated, pluralized confirmation message for a processed action.
	 *
	 * @param string $action Processed action: "trash", "untrash", or "delete".
	 * @param int    $count  Number of posts the action was applied to.
	 */
	private function row_action_message( string $action, int $count ): string {
		return match ( $action ) {
			/* translators: %d: number of books moved to Trash. */
			'trash'   => sprintf( _n( '%d book moved to Trash.', '%d books moved to Trash.', $count, 'smartbook' ), $count ),
			/* translators: %d: number of books restored. */
			'untrash' => sprintf( _n( '%d book restored.', '%d books restored.', $count, 'smartbook' ), $count ),
			/* translators: %d: number of books permanently deleted. */
			'delete'  => sprintf( _n( '%d book permanently deleted.', '%d books permanently deleted.', $count, 'smartbook' ), $count ),
			default   => '',
		};
	}

	/**
	 * Whether the intermediate bulk-edit form itself is being submitted.
	 */
	private function is_bulk_edit_apply_request(): bool {
		// Only detects which mode to render; the nonce is verified in
		// handle_bulk_edit_apply() before anything is actually written.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['sb_bulk_edit_apply'] ) && '1' === $_POST['sb_bulk_edit_apply'];
	}

	/**
	 * Render the "pick which fields to change" intermediate step for
	 * bulk editing. Every field defaults to "No Change" so an admin can
	 * update just one attribute across many books at once.
	 *
	 * @param int[] $ids Post IDs selected in the list table.
	 */
	private function render_bulk_edit_form( array $ids ): void {
		echo '<div class="wrap sb-admin-page">';
		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: %d: number of selected books. */
					__( 'Bulk Edit %d Book(s)', 'smartbook' ),
					count( $ids )
				)
			)
		);

		echo '<form method="post">';
		wp_nonce_field( self::BULK_EDIT_NONCE_ACTION );
		echo '<input type="hidden" name="sb_bulk_edit_apply" value="1" />';

		foreach ( $ids as $id ) {
			printf( '<input type="hidden" name="sb_book_id[]" value="%d" />', $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the "%d" specifier already coerces to an integer, no HTML can pass through.
		}

		$this->render_bulk_select( 'sb_bulk_genre', __( 'Genre', 'smartbook' ), $this->term_options( GenreTaxonomy::SLUG ) );
		$this->render_bulk_select( 'sb_bulk_shelf', __( 'Shelf', 'smartbook' ), $this->term_options( ShelfTaxonomy::SLUG ) );
		$this->render_bulk_select( 'sb_bulk_status', __( 'Reading Status', 'smartbook' ), BooksListTable::status_labels() );
		$this->render_bulk_select(
			'sb_bulk_favorite',
			__( 'Favorite', 'smartbook' ),
			array(
				'1' => __( 'Mark as Favorite', 'smartbook' ),
				'0' => __( 'Remove from Favorites', 'smartbook' ),
			)
		);

		submit_button( __( 'Apply Changes', 'smartbook' ) );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Cancel', 'smartbook' )
		);

		echo '</form></div>';
	}

	/**
	 * Render one "No Change" + options <select> for the bulk-edit form.
	 *
	 * @param string                $name    Field name/id.
	 * @param string                $label   Field label.
	 * @param array<string, string> $options Value => label pairs.
	 */
	private function render_bulk_select( string $name, string $label, array $options ): void {
		printf( '<div class="sb-field-group"><label for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
		printf( '<select id="%1$s" name="%1$s">', esc_attr( $name ) );
		printf( '<option value="">%s</option>', esc_html__( '— No Change —', 'smartbook' ) );

		foreach ( $options as $value => $option_label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( (string) $value ), esc_html( $option_label ) );
		}

		echo '</select></div>';
	}

	/**
	 * Apply the submitted bulk-edit form to every selected post.
	 *
	 * @param int[] $ids Post IDs to update.
	 */
	private function handle_bulk_edit_apply( array $ids ): void {
		check_admin_referer( self::BULK_EDIT_NONCE_ACTION );

		if ( array() === $ids ) {
			$this->redirect_with_notice( 'error', __( 'No books were selected.', 'smartbook' ) );
		}

		$genre    = isset( $_POST['sb_bulk_genre'] ) ? sanitize_key( wp_unslash( $_POST['sb_bulk_genre'] ) ) : '';
		$shelf    = isset( $_POST['sb_bulk_shelf'] ) ? sanitize_key( wp_unslash( $_POST['sb_bulk_shelf'] ) ) : '';
		$status   = isset( $_POST['sb_bulk_status'] ) ? sanitize_key( wp_unslash( $_POST['sb_bulk_status'] ) ) : '';
		$favorite = isset( $_POST['sb_bulk_favorite'] ) ? sanitize_key( wp_unslash( $_POST['sb_bulk_favorite'] ) ) : '';

		$updated = 0;

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			if ( '' !== $genre && term_exists( $genre, GenreTaxonomy::SLUG ) ) {
				wp_set_object_terms( $id, array( $genre ), GenreTaxonomy::SLUG );
			}

			if ( '' !== $shelf && term_exists( $shelf, ShelfTaxonomy::SLUG ) ) {
				wp_set_object_terms( $id, array( $shelf ), ShelfTaxonomy::SLUG );
			}

			if ( '' !== $status ) {
				update_post_meta( $id, 'sb_status', BookFields::sanitize( 'sb_status', $status ) );
			}

			if ( '' !== $favorite ) {
				update_post_meta( $id, 'sb_favorite', BookFields::sanitize( 'sb_favorite', '1' === $favorite ) );
			}

			++$updated;
		}

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: %d: number of books updated. */
				__( '%d book(s) updated.', 'smartbook' ),
				$updated
			)
		);
	}

	/**
	 * Term slug => name options for a taxonomy <select>.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return array<string, string>
	 */
	private function term_options( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$options = array();

		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		return $options;
	}

	/**
	 * Post IDs submitted via the "sb_book_id[]" checkboxes or a single
	 * row-action link.
	 *
	 * @return int[]
	 */
	private function requested_ids(): array {
		// Only parses which IDs were selected; every action that acts on
		// them (trash/untrash/delete/bulk-edit) verifies its own nonce
		// before doing anything, see this class's docblock.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['sb_book_id'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element is absint()'d below.
		$raw = wp_unslash( $_REQUEST['sb_book_id'] );
		$raw = is_array( $raw ) ? $raw : array( $raw );

		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function notice_page_slug(): string {
		return self::PAGE_SLUG;
	}
}
