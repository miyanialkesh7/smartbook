<?php
/**
 * Email notifications for the borrow/return request workflow.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Notifications;

use SmartBook\Core\Contracts\Hookable;
use SmartBook\Services\LoggerInterface;
use WP_User;

use function sb_option;

/**
 * Sends the admin and the requester an email at every step of the
 * borrow/return request workflow, so neither side has to keep checking
 * the "Borrowed Books" admin page or the book's own front-end page to
 * know something happened. Hooked onto the custom actions
 * Frontend\BorrowRequestController and Admin\Pages\BorrowedBooksPage
 * fire at each state change ("sb_borrow_requested"/"sb_return_requested"
 * on the front end, "sb_borrow_approved"/"sb_borrow_denied"/
 * "sb_return_confirmed" from the admin page), rather than being called
 * directly, so either of those classes stays unaware that notifications
 * exist at all. Gated by the "enable_email_notifications" setting;
 * every send failure is logged, never surfaced to the end user, since a
 * failed notification email should not block the borrow/return action
 * itself from having already succeeded.
 */
final class BorrowNotifications implements Hookable {

	/**
	 * @param LoggerInterface $logger Logger, used when wp_mail() fails.
	 */
	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'sb_borrow_requested', array( $this, 'notify_admin_of_borrow_request' ), 10, 2 );
		add_action( 'sb_borrow_approved', array( $this, 'notify_requester_of_approval' ), 10, 2 );
		add_action( 'sb_borrow_denied', array( $this, 'notify_requester_of_denial' ), 10, 2 );
		add_action( 'sb_return_requested', array( $this, 'notify_admin_of_return_request' ), 10, 2 );
		add_action( 'sb_return_confirmed', array( $this, 'notify_borrower_of_return_confirmation' ), 10, 1 );
	}

	/**
	 * A new "Request to Borrow" was submitted -- tell the site admin.
	 */
	public function notify_admin_of_borrow_request( int $post_id, int $user_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$title     = get_the_title( $post_id );
		$requester = get_userdata( $user_id );

		$this->send(
			(string) get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: book title. */
				__( 'New borrow request: "%s"', 'smartbook' ),
				$title
			),
			sprintf(
				/* translators: 1: requester display name, 2: book title, 3: Borrowed Books admin URL. */
				__( "%1\$s has requested to borrow \"%2\$s\".\n\nReview the request here: %3\$s", 'smartbook' ),
				$requester instanceof WP_User ? $requester->display_name : __( 'A user', 'smartbook' ),
				$title,
				$this->borrowed_books_url()
			)
		);
	}

	/**
	 * A pending borrow request was approved -- tell the requester.
	 */
	public function notify_requester_of_approval( int $post_id, int $user_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return;
		}

		$title = get_the_title( $post_id );

		$this->send(
			$user->user_email,
			sprintf(
				/* translators: %s: book title. */
				__( 'Your request to borrow "%s" was approved', 'smartbook' ),
				$title
			),
			sprintf(
				/* translators: 1: requester display name, 2: book title, 3: book permalink. */
				__( "Hi %1\$s,\n\nYour request to borrow \"%2\$s\" has been approved. It's yours until you return it.\n\nView it here: %3\$s", 'smartbook' ),
				$user->display_name,
				$title,
				(string) get_permalink( $post_id )
			)
		);
	}

	/**
	 * A pending borrow request was denied -- tell the requester.
	 */
	public function notify_requester_of_denial( int $post_id, int $user_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return;
		}

		$title = get_the_title( $post_id );

		$this->send(
			$user->user_email,
			sprintf(
				/* translators: %s: book title. */
				__( 'Your request to borrow "%s" was declined', 'smartbook' ),
				$title
			),
			sprintf(
				/* translators: 1: requester display name, 2: book title. */
				__( "Hi %1\$s,\n\nYour request to borrow \"%2\$s\" was not approved this time.", 'smartbook' ),
				$user->display_name,
				$title
			)
		);
	}

	/**
	 * A borrower asked to return a book -- tell the site admin.
	 */
	public function notify_admin_of_return_request( int $post_id, int $user_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$title    = get_the_title( $post_id );
		$borrower = get_userdata( $user_id );

		$this->send(
			(string) get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: book title. */
				__( 'Return requested: "%s"', 'smartbook' ),
				$title
			),
			sprintf(
				/* translators: 1: borrower display name, 2: book title, 3: Borrowed Books admin URL. */
				__( "%1\$s has asked to return \"%2\$s\".\n\nConfirm it here: %3\$s", 'smartbook' ),
				$borrower instanceof WP_User ? $borrower->display_name : __( 'A user', 'smartbook' ),
				$title,
				$this->borrowed_books_url()
			)
		);
	}

	/**
	 * A return was confirmed from the admin page -- tell the borrower.
	 * The borrower is resolved from "sb_borrowed_to" (still present at
	 * this point -- Admin\Pages\BorrowedBooksPage::handle_mark_returned()
	 * leaves it as a historical record of the loan) rather than being
	 * passed in, since confirming a return doesn't otherwise need it.
	 */
	public function notify_borrower_of_return_confirmation( int $post_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$borrowed_to = (string) get_post_meta( $post_id, 'sb_borrowed_to', true );

		if ( '' === $borrowed_to || ! ctype_digit( $borrowed_to ) ) {
			return;
		}

		$user = get_userdata( (int) $borrowed_to );

		if ( ! $user instanceof WP_User || '' === $user->user_email ) {
			return;
		}

		$title = get_the_title( $post_id );

		$this->send(
			$user->user_email,
			sprintf(
				/* translators: %s: book title. */
				__( 'Return confirmed: "%s"', 'smartbook' ),
				$title
			),
			sprintf(
				/* translators: 1: borrower display name, 2: book title. */
				__( "Hi %1\$s,\n\nYour return of \"%2\$s\" has been confirmed. Thanks!", 'smartbook' ),
				$user->display_name,
				$title
			)
		);
	}

	/**
	 * Whether email notifications are turned on.
	 */
	private function enabled(): bool {
		return (bool) sb_option( 'enable_email_notifications', true );
	}

	/**
	 * URL to the "Borrowed Books" admin page's "Requests" tab.
	 */
	private function borrowed_books_url(): string {
		return admin_url( 'admin.php?page=sb_borrowed_books' );
	}

	/**
	 * Send one email, logging (but never throwing on) a failure.
	 */
	private function send( string $to, string $subject, string $message ): void {
		if ( '' === $to ) {
			return;
		}

		$sent = wp_mail( $to, $subject, $message );

		if ( ! $sent ) {
			$this->logger->error(
				'Failed to send SmartBook notification email.',
				array(
					'to'      => $to,
					'subject' => $subject,
				)
			);
		}
	}
}
