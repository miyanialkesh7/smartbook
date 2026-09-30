<?php
/**
 * Quick-action handlers for the book scan page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Frontend;

use SmartBook\Core\Contracts\Hookable;
use SmartBook\MetaBoxes\BookFields;

use function sb_option;

/**
 * Persists the three quick-action forms BookScanPage renders: "Update
 * Progress" (sb_progress/sb_status), "Borrow" (sb_borrowed/
 * sb_borrowed_to/sb_borrow_date/sb_returned), and "Return"
 * (sb_returned). Every handler is capability- and nonce-gated
 * identically to BookScanPage's own visibility rule for the form that
 * posts to it, then redirects back to the same scan page with a
 * "sb_notice" flag BookScanPage turns into a success banner.
 */
final class BookScanActions implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( sb_option( 'enable_reading_tracker', true ) ) {
			add_action( 'admin_post_sb_scan_update_progress', array( $this, 'handle_update_progress' ) );
		}

		if ( sb_option( 'enable_borrow', true ) ) {
			add_action( 'admin_post_sb_scan_borrow', array( $this, 'handle_borrow' ) );
			add_action( 'admin_post_sb_scan_return', array( $this, 'handle_return' ) );
		}
	}

	/**
	 * Handle the "Update Progress" form.
	 */
	public function handle_update_progress(): void {
		$post_id = $this->authorize();

		$progress = isset( $_POST['sb_progress'] ) ? absint( wp_unslash( $_POST['sb_progress'] ) ) : 0;
		update_post_meta( $post_id, 'sb_progress', BookFields::sanitize( 'sb_progress', $progress ) );

		$status         = isset( $_POST['sb_status'] ) ? sanitize_key( wp_unslash( $_POST['sb_status'] ) ) : '';
		$valid_statuses = array_keys( BookFields::definitions()['sb_status']['options'] ?? array() );

		if ( in_array( $status, $valid_statuses, true ) ) {
			update_post_meta( $post_id, 'sb_status', $status );
		}

		$this->redirect_back( $post_id, 'progress_updated' );
	}

	/**
	 * Handle the "Borrow" form.
	 */
	public function handle_borrow(): void {
		$post_id = $this->authorize();

		$borrowed_to = isset( $_POST['sb_borrowed_to'] ) ? sanitize_text_field( wp_unslash( $_POST['sb_borrowed_to'] ) ) : '';

		if ( '' === $borrowed_to ) {
			$borrowed_to = wp_get_current_user()->display_name;
		}

		update_post_meta( $post_id, 'sb_borrowed', '1' );
		update_post_meta( $post_id, 'sb_returned', '' );
		update_post_meta( $post_id, 'sb_borrowed_to', $borrowed_to );
		update_post_meta( $post_id, 'sb_borrow_date', current_time( 'Y-m-d' ) );

		$this->redirect_back( $post_id, 'borrowed' );
	}

	/**
	 * Handle the "Return" form.
	 */
	public function handle_return(): void {
		$post_id = $this->authorize();

		update_post_meta( $post_id, 'sb_returned', '1' );

		$this->redirect_back( $post_id, 'returned' );
	}

	/**
	 * Validate the submitted post id, capability, and nonce shared by
	 * every quick-action form, dying on failure exactly like a core
	 * admin-post handler.
	 *
	 * @return int The validated book post ID.
	 */
	private function authorize(): int {
		$post_id = isset( $_POST['sb_post_id'] ) ? absint( $_POST['sb_post_id'] ) : 0;

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'smartbook' ) );
		}

		$nonce = isset( $_POST[ BookScanPage::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ BookScanPage::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, BookScanPage::NONCE_ACTION_PREFIX . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'smartbook' ) );
		}

		return $post_id;
	}

	/**
	 * Redirect back to the book's own scan page, flagged with a success
	 * notice BookScanPage turns into a banner.
	 */
	private function redirect_back( int $post_id, string $notice ): void {
		$url = add_query_arg(
			array(
				BookScanPage::QUERY_VAR => '1',
				'sb_notice'             => $notice,
			),
			get_permalink( $post_id )
		);

		wp_safe_redirect( esc_url_raw( $url ) );
		exit;
	}
}
