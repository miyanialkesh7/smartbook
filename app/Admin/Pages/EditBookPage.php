<?php
/**
 * The custom "Edit Book" form.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use SmartBook\MetaBoxes\BarcodeMetaBox;
use SmartBook\MetaBoxes\QrCodeMetaBox;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\Import\BookRowSchema;
use WP_Post;

use function sb_option;

/**
 * A single-page replacement for WordPress's native post editor when
 * editing an existing book: the exact same form AddBookPage renders
 * (via AbstractBookFormPage), preloaded with the book's current data,
 * plus a QR code/barcode panel (this.render_side_panel()) so those
 * meta boxes' "Regenerate"/"Print Label" actions -- otherwise only
 * reachable from the native edit screen this page replaces -- aren't
 * lost. WordPress's own post.php?post=X&action=edit for a book is
 * redirected here (redirect_native_edit()); updating is delegated to
 * BookRowSchema::apply_row() with this book's id as the target, the
 * same engine AddBookPage/the CSV import already rely on.
 */
final class EditBookPage extends AbstractBookFormPage {

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'sb_edit_book';

	/**
	 * admin-post.php action name for the form's own submission.
	 */
	private const SAVE_ACTION = 'sb_edit_book';

	/**
	 * Nonce action name.
	 */
	private const NONCE_ACTION = 'sb_edit_book_save';

	/**
	 * Nonce hidden field name.
	 */
	private const NONCE_NAME = 'sb_edit_book_nonce';

	/**
	 * @param QrCodeMetaBox  $qr_code_meta_box QR code display/regenerate panel.
	 * @param BarcodeMetaBox $barcode_meta_box Barcode display/regenerate panel.
	 */
	public function __construct(
		private readonly QrCodeMetaBox $qr_code_meta_box,
		private readonly BarcodeMetaBox $barcode_meta_box
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'load-post.php', array( $this, 'redirect_native_edit' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Send WordPress's own post editor for a book (reached via the books
	 * table's row actions -- get_edit_post_link() -- the admin bar's
	 * "Edit Book" link on a single book's front-end page, or a hand-typed
	 * post.php URL) to this page instead, so there is no way to land on
	 * the native editor when editing a book. BooksListTable's own Edit
	 * links point straight here already; this is the catch-all for every
	 * other route to the native editor.
	 */
	public function redirect_native_edit(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id <= 0 || BookPostType::SLUG !== get_post_type( $post_id ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&book_id=' . $post_id ) );
		exit;
	}

	/**
	 * Process the submitted form: validate it (BookFormValidator::validate()
	 * -- required title, ISBN-10/13 format, non-negative Pages/Price, a
	 * real Purchase Date, and no *other* book matching this ISBN/title --
	 * this book itself is excluded from that last check, since editing a
	 * book without changing its own ISBN/title would otherwise always
	 * "match itself"), then update the book via BookRowSchema::apply_row()
	 * (targeting this book's id), then apply the cover/gallery images and
	 * description, which apply_row() doesn't handle (it only knows about
	 * BookFields meta and taxonomies, not core post_content/thumbnail).
	 */
	public function handle_save(): void {
		if ( ! $this->can_access() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'smartbook' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$book_id = $this->book_id();

		$title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';

		$status = isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : 'publish';

		if ( ! array_key_exists( $status, $this->status_options() ) ) {
			$status = 'publish';
		}

		$data = array_merge(
			array(
				'title'  => $title,
				'status' => $status,
			),
			$this->collect_posted_row()
		);

		$validation_error = BookFormValidator::validate( $data, $book_id );

		if ( null !== $validation_error ) {
			$this->redirect_with_notice( 'error', $validation_error, array( 'book_id' => $book_id ) );
		}

		$result = BookRowSchema::apply_row( $data, $book_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message(), array( 'book_id' => $book_id ) );
		}

		$post_id = (int) $result;

		if ( isset( $_POST['post_content'] ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_kses_post( wp_unslash( $_POST['post_content'] ) ),
				)
			);
		}

		$this->maybe_attach_cover( $post_id );
		$this->maybe_attach_gallery( $post_id );
		$this->clear_draft_cookie();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'sb_books',
					'sb_notice'      => rawurlencode(
						sprintf(
							/* translators: %s: book title. */
							__( '"%s" was updated.', 'smartbook' ),
							$title
						)
					),
					'sb_notice_type' => 'success',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * The book id this request concerns, from wherever it's carried:
	 * "book_id" in the query string on a GET (render()), or the same
	 * name as a hidden POST field on save (render_extra_hidden_fields()).
	 */
	private function book_id(): int {
		if ( isset( $_POST['book_id'] ) ) {
			return absint( $_POST['book_id'] );
		}

		return isset( $_GET['book_id'] ) ? absint( $_GET['book_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The book being edited, or null if book_id() doesn't resolve to a
	 * "sb_book" post (missing, wrong post type, or already trashed --
	 * get_post() still returns a trashed post, so a deliberate check
	 * against BookPostType::SLUG is what actually guards this, not
	 * against post_status).
	 */
	private function book(): ?WP_Post {
		$post = get_post( $this->book_id() );

		return ( $post instanceof WP_Post && BookPostType::SLUG === $post->post_type ) ? $post : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_slug(): string {
		return self::PAGE_SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function save_action(): string {
		return self::SAVE_ACTION;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function nonce_name(): string {
		return self::NONCE_NAME;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function draft_cookie(): string {
		// Scoped per book so a draft for one book never bleeds into
		// another's edit session.
		return 'sb_edit_book_draft_' . $this->book_id();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function form_id(): string {
		return 'sb-edit-book-form';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function heading(): string {
		return __( 'Edit Book', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function submit_label(): string {
		return __( 'Update Book', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function can_access(): bool {
		$book = $this->book();

		return null !== $book && current_user_can( 'edit_post', $book->ID );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_extra_hidden_fields(): void {
		printf( '<input type="hidden" name="book_id" value="%d" />', $this->book_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A "View Book" link to the live front-end page for a published
	 * book, or "Preview" (WordPress's own draft-preview link) otherwise.
	 * Opens in a new tab so leaving to look doesn't lose unsaved changes
	 * in this form.
	 */
	protected function render_header_actions(): void {
		$book = $this->book();

		if ( null === $book ) {
			return;
		}

		$is_published = 'publish' === $book->post_status;
		$url          = $is_published ? get_permalink( $book ) : get_preview_post_link( $book );

		if ( null === $url || false === $url || '' === $url ) {
			return;
		}

		printf(
			' <a href="%1$s" class="page-title-action" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			esc_html( $is_published ? __( 'View Book', 'smartbook' ) : __( 'Preview', 'smartbook' ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reuses QrCodeMetaBox/BarcodeMetaBox's own render() output (each
	 * already gated on its own "enable_qr"/"enable_barcode" setting) so
	 * their display + "Regenerate"/"Print Label" actions keep working
	 * here now that the native edit screen -- their original home -- is
	 * unreachable for books.
	 */
	protected function render_side_panel(): void {
		$book = $this->book();

		if ( null === $book ) {
			return;
		}

		$show_qr      = sb_option( 'enable_qr', true );
		$show_barcode = sb_option( 'enable_barcode', true );

		if ( ! $show_qr && ! $show_barcode ) {
			return;
		}

		printf( '<h3 class="sb-meta-box__section-title">%s</h3>', esc_html__( 'QR Code & Barcode', 'smartbook' ) );
		echo '<div class="sb-meta-box__grid">';

		if ( $show_qr ) {
			echo '<div class="sb-field-group">';
			$this->qr_code_meta_box->render( $book );
			echo '</div>';
		}

		if ( $show_barcode ) {
			echo '<div class="sb-field-group">';
			$this->barcode_meta_box->render( $book );
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_title(): string {
		$book = $this->book();

		return null !== $book ? $book->post_title : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_status(): string {
		$book = $this->book();

		return null !== $book ? $book->post_status : 'publish';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_content(): string {
		$book = $this->book();

		return null !== $book ? $book->post_content : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_cover_id(): int {
		$book = $this->book();

		return null !== $book ? (int) get_post_thumbnail_id( $book->ID ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_gallery_ids(): string {
		$book = $this->book();

		return null !== $book ? (string) get_post_meta( $book->ID, 'sb_gallery', true ) : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_field_value( string $key ): mixed {
		$book = $this->book();

		return null !== $book ? get_post_meta( $book->ID, $key, true ) : '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_terms( string $taxonomy ): array {
		$book = $this->book();

		if ( null === $book ) {
			return array();
		}

		$terms = wp_get_post_terms( $book->ID, $taxonomy, array( 'fields' => 'names' ) );

		return is_array( $terms ) ? $terms : array();
	}
}
