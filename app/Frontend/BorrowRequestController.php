<?php
/**
 * Front-end "request to borrow"/"request a return" handlers.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Frontend;

use SmartBook\Core\Contracts\Hookable;
use SmartBook\PostTypes\BookPostType;

use function sb_option;

/**
 * Lets any logged-in visitor ask to borrow an available book from its
 * own front-end page, and separately lets whoever currently has it ask
 * to return it (see BookContentDisplay::render_availability() for the
 * buttons these post from). Neither request changes the loan by
 * itself: an admin still has to approve both from
 * Admin\Pages\BorrowedBooksPage ("Requests" starts a loan;
 * "Confirm Return" on an on-loan row ends one) -- this class only ever
 * records that a request was made (sb_borrow_request_user/
 * sb_borrow_request_date for a borrow, sb_return_request for a return),
 * it never sets "sb_borrowed"/"sb_returned" directly. Fires
 * "sb_borrow_requested"/"sb_return_requested" once each request is
 * recorded, purely so Notifications\BorrowNotifications can email the
 * admin without this class needing to know notifications exist.
 *
 * Registered on "admin_post_{action}" only, not the "_nopriv_" variant,
 * since WordPress routes a submission to exactly one of the two based
 * on login state -- omitting the nopriv hook means a logged-out
 * submission (which shouldn't happen anyway, since both forms are only
 * ever rendered for a logged-in visitor) simply has no handler to run,
 * rather than needing to reject it here.
 */
final class BorrowRequestController implements Hookable {

	/**
	 * admin-post.php action name for "Request to Borrow".
	 */
	public const ACTION = 'sb_request_borrow';

	/**
	 * Nonce hidden field name for "Request to Borrow".
	 */
	public const NONCE_NAME = 'sb_request_borrow_nonce';

	/**
	 * admin-post.php action name for "Return Book".
	 */
	public const RETURN_ACTION = 'sb_request_return';

	/**
	 * Nonce hidden field name for "Return Book".
	 */
	public const RETURN_NONCE_NAME = 'sb_request_return_nonce';

	/**
	 * Nonce action name prefix for "Request to Borrow", suffixed with
	 * the book's post id.
	 */
	private const NONCE_ACTION_PREFIX = 'sb_request_borrow_';

	/**
	 * Nonce action name prefix for "Return Book", suffixed with the
	 * book's post id.
	 */
	private const RETURN_NONCE_ACTION_PREFIX = 'sb_request_return_';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( sb_option( 'enable_borrow', true ) ) {
			add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
			add_action( 'admin_post_' . self::RETURN_ACTION, array( $this, 'handle_return_request' ) );
		}
	}

	/**
	 * Nonce action name for a given book's "Request to Borrow" form, so
	 * BookContentDisplay's form and this handler always agree on it.
	 */
	public static function nonce_action( int $post_id ): string {
		return self::NONCE_ACTION_PREFIX . $post_id;
	}

	/**
	 * Nonce action name for a given book's "Return Book" form.
	 */
	public static function return_nonce_action( int $post_id ): string {
		return self::RETURN_NONCE_ACTION_PREFIX . $post_id;
	}

	/**
	 * Process the "Request to Borrow" form submission.
	 */
	public function handle_request(): void {
		$post_id = isset( $_POST['sb_post_id'] ) ? absint( $_POST['sb_post_id'] ) : 0;

		if ( $post_id <= 0 || BookPostType::SLUG !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Invalid book.', 'smartbook' ) );
		}

		check_admin_referer( self::nonce_action( $post_id ), self::NONCE_NAME );

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to request a book.', 'smartbook' ) );
		}

		if ( $this->is_borrowed( $post_id ) ) {
			$this->redirect_back( $post_id, 'unavailable' );
		}

		if ( $this->has_pending_request( $post_id ) ) {
			$this->redirect_back( $post_id, 'already_requested' );
		}

		update_post_meta( $post_id, 'sb_borrow_request_user', get_current_user_id() );
		update_post_meta( $post_id, 'sb_borrow_request_date', current_time( 'mysql' ) );

		/**
		 * Fires when a "Request to Borrow" is submitted, for
		 * Notifications\BorrowNotifications to email the admin.
		 *
		 * @param int $post_id Book post ID.
		 * @param int $user_id Requester's user ID.
		 */
		do_action( 'sb_borrow_requested', $post_id, get_current_user_id() );

		$this->redirect_back( $post_id, 'requested' );
	}

	/**
	 * Process the "Return Book" form submission.
	 */
	public function handle_return_request(): void {
		$post_id = isset( $_POST['sb_post_id'] ) ? absint( $_POST['sb_post_id'] ) : 0;

		if ( $post_id <= 0 || BookPostType::SLUG !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Invalid book.', 'smartbook' ) );
		}

		check_admin_referer( self::return_nonce_action( $post_id ), self::RETURN_NONCE_NAME );

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to request a return.', 'smartbook' ) );
		}

		if ( ! $this->is_borrowed_by( $post_id, get_current_user_id() ) ) {
			$this->redirect_back( $post_id, 'not_your_book' );
		}

		if ( $this->has_pending_return_request( $post_id ) ) {
			$this->redirect_back( $post_id, 'return_already_requested' );
		}

		update_post_meta( $post_id, 'sb_return_request', '1' );

		/**
		 * Fires when a "Return Book" request is submitted, for
		 * Notifications\BorrowNotifications to email the admin.
		 *
		 * @param int $post_id Book post ID.
		 * @param int $user_id Borrower's user ID.
		 */
		do_action( 'sb_return_requested', $post_id, get_current_user_id() );

		$this->redirect_back( $post_id, 'return_requested' );
	}

	/**
	 * Whether a book is currently on loan (not available to request).
	 */
	private function is_borrowed( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, 'sb_borrowed', true )
			&& '1' !== (string) get_post_meta( $post_id, 'sb_returned', true );
	}

	/**
	 * Whether a book is currently on loan specifically to the given
	 * user -- only ever true for a loan that started from an approved
	 * request (BookFields::user_options()'s id-keyed "sb_borrowed_to");
	 * a free-typed name from the book scan page's "Borrow" quick action
	 * can't be tied to a specific account, so it never matches here.
	 */
	private function is_borrowed_by( int $post_id, int $user_id ): bool {
		if ( ! $this->is_borrowed( $post_id ) ) {
			return false;
		}

		$borrowed_to = (string) get_post_meta( $post_id, 'sb_borrowed_to', true );

		return '' !== $borrowed_to && ctype_digit( $borrowed_to ) && (int) $borrowed_to === $user_id;
	}

	/**
	 * Whether a book already has an unresolved pending borrow request.
	 */
	private function has_pending_request( int $post_id ): bool {
		return (int) get_post_meta( $post_id, 'sb_borrow_request_user', true ) > 0;
	}

	/**
	 * Whether a book already has an unresolved pending return request.
	 */
	private function has_pending_return_request( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, 'sb_return_request', true );
	}

	/**
	 * Redirect back to the book's own page, flagged with a notice
	 * BookContentDisplay turns into a banner.
	 */
	private function redirect_back( int $post_id, string $notice ): never {
		$url = add_query_arg( array( 'sb_borrow_notice' => $notice ), get_permalink( $post_id ) );

		wp_safe_redirect( esc_url_raw( $url ) );
		exit;
	}
}
