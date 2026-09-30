<?php
/**
 * The custom "Add New Book" form.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\Import\BookRowSchema;

/**
 * A single-page replacement for WordPress's native "Add New" post editor.
 * Every "Add New Book" entry point in the admin (the Books page's "Add
 * New" button, the Dashboard's quick link, and WordPress's own admin-bar
 * "+ New" and post-new.php URL) is redirected here; editing an existing
 * book goes through EditBookPage instead (see its own redirect).
 *
 * Shares its form rendering with EditBookPage via AbstractBookFormPage;
 * this class only supplies blank default field values and
 * create-via-BookRowSchema::apply_row() save handling -- the same
 * row-creation engine the CSV/JSON/XML import already relies on, so this
 * page adds no new post-creation logic of its own, only the form around it.
 */
final class AddBookPage extends AbstractBookFormPage {

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'sb_add_book';

	/**
	 * admin-post.php action name for the form's own submission.
	 */
	private const SAVE_ACTION = 'sb_add_book';

	/**
	 * Nonce action name.
	 */
	private const NONCE_ACTION = 'sb_add_book_save';

	/**
	 * Nonce hidden field name.
	 */
	private const NONCE_NAME = 'sb_add_book_nonce';

	/**
	 * Name of the client-side cookie (see sb-admin.js' sb_initFormDraft())
	 * that mirrors this form's field values, so a reload or a
	 * failed-validation redirect back to this same page (which loses
	 * whatever was POSTed, since this page never re-renders with the
	 * submitted values) can restore what was typed instead of silently
	 * discarding it.
	 */
	private const DRAFT_COOKIE = 'sb_add_book_draft';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'load-post-new.php', array( $this, 'redirect_legacy_add_new' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Send WordPress's own "Add New" post editor for the book post type
	 * (reached via the admin-bar "+ New" menu, or a hand-typed
	 * post-new.php URL) to this page instead, so there is no way to land
	 * on the native editor when adding a book.
	 */
	public function redirect_legacy_add_new(): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( BookPostType::SLUG !== $post_type ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Process the submitted form: validate it (BookFormValidator::validate()
	 * -- required title, ISBN-10/13 format, non-negative Pages/Price, a
	 * real Purchase Date, and no existing book matching this ISBN/title),
	 * then create the book via BookRowSchema::apply_row(), then apply the
	 * cover/gallery images and description, which apply_row() doesn't
	 * handle (it only knows about BookFields meta and taxonomies, not
	 * core post_content/thumbnail).
	 */
	public function handle_save(): void {
		if ( ! $this->can_access() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'smartbook' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

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

		$validation_error = BookFormValidator::validate( $data );

		if ( null !== $validation_error ) {
			$this->redirect_with_notice( 'error', $validation_error );
		}

		$result = BookRowSchema::apply_row( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
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
							__( '"%s" was added to your library.', 'smartbook' ),
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
		return self::DRAFT_COOKIE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function form_id(): string {
		return 'sb-add-book-form';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function heading(): string {
		return __( 'Add New Book', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function submit_label(): string {
		return __( 'Add Book', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function can_access(): bool {
		return current_user_can( BookPostType::CAP_EDIT_BOOKS );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_title(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_status(): string {
		return 'publish';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_content(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_cover_id(): int {
		return 0;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_gallery_ids(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_field_value( string $key ): mixed {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function current_terms( string $taxonomy ): array {
		return array();
	}
}
