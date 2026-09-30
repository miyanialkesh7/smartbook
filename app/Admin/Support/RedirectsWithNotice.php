<?php
/**
 * Shared "redirect back with a one-time notice" behaviour for admin pages.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Several admin pages process an action (import, export, bulk edit,
 * trash/delete) and then need to redirect back to themselves with a
 * result message, since re-submitting a POST on refresh must be
 * avoided. This trait centralizes that pattern so it is implemented
 * once instead of once per page.
 */
trait RedirectsWithNotice {

	/**
	 * The admin page slug to redirect back to; implemented by the class
	 * using this trait. Protected, not private: AbstractBookFormPage uses
	 * this trait itself, but it's AddBookPage/EditBookPage -- its
	 * subclasses -- that actually implement this and call
	 * redirect_with_notice() below from their own handle_save(); a
	 * private trait method is only visible to the exact class that used
	 * the trait, not to further subclasses of it.
	 */
	abstract protected function notice_page_slug(): string;

	/**
	 * Redirect back to the page with a result notice, then terminate the
	 * request (standard for a POST/admin-post.php handler).
	 *
	 * @param string               $type        "error" or "success".
	 * @param string               $message     Notice text.
	 * @param array<string, mixed> $extra_args  Additional query args to carry over (e.g.
	 *                                           EditBookPage's "book_id", so the page it
	 *                                           redirects back to still knows which book).
	 */
	protected function redirect_with_notice( string $type, string $message, array $extra_args = array() ): never {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					$extra_args,
					array(
						'page'           => $this->notice_page_slug(),
						'sb_notice'      => rawurlencode( $message ),
						'sb_notice_type' => $type,
					)
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Read a one-time result notice from the query string.
	 *
	 * @return array{type: string, message: string}|null
	 */
	protected function consume_notice(): ?array {
		if ( ! isset( $_GET['sb_notice'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['sb_notice_type'] ) && 'success' === $_GET['sb_notice_type'] ? 'success' : 'error';

		return array(
			'type'    => $type,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() is the outermost call and sanitizes the final, fully-decoded value.
			'message' => sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['sb_notice'] ) ) ),
		);
	}

	/**
	 * Render the pending notice, if any, escaping it at the point of output.
	 */
	protected function render_notice(): void {
		$notice = $this->consume_notice();

		if ( null === $notice ) {
			return;
		}

		printf(
			'<div class="sb-notice sb-notice--%1$s"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}
}
