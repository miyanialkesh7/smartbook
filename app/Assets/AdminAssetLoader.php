<?php
/**
 * Admin asset loader.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Core\Contracts\Hookable;

use function sb_asset_url;
use function sb_asset_version;

/**
 * Enqueues SmartBook's admin stylesheet and script, restricted to
 * SmartBook's own admin screens so other screens in wp-admin do not pay
 * for assets they never use.
 *
 * Other modules register their screens via the "sb_admin_asset_screens"
 * filter (either the raw $hook_suffix or, for custom post type screens,
 * the WP_Screen id) instead of this class needing to know about them.
 */
final class AdminAssetLoader implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the admin assets when the current screen belongs to SmartBook.
	 *
	 * @param string $hook_suffix Current admin page hook suffix, supplied by WordPress.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->should_enqueue( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'sb-admin',
			sb_asset_url( 'css/sb-admin.css' ),
			array(),
			sb_asset_version( 'css/sb-admin.css' )
		);

		wp_enqueue_script(
			'sb-admin',
			sb_asset_url( 'js/sb-admin.js' ),
			array(),
			sb_asset_version( 'js/sb-admin.js' ),
			true
		);

		wp_localize_script(
			'sb-admin',
			'sbAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'sb_admin_nonce' ),
				// So client-side draft cookies (see sb-admin.js'
				// sb_initFormDraft()) use the same path WordPress's own
				// cookies do, letting a PHP-side setcookie() (e.g.
				// AddBookPage::handle_save() clearing the draft on
				// success) actually reach and clear them.
				'cookiePath' => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
			)
		);
	}

	/**
	 * Whether the current admin screen should receive SmartBook's assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	private function should_enqueue( string $hook_suffix ): bool {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = null !== $screen ? (string) $screen->id : '';

		/**
		 * Filters the list of admin hook suffixes / screen ids that should
		 * receive SmartBook's admin assets.
		 *
		 * @param string[] $screens Allowed hook suffixes and/or screen ids.
		 */
		$allowed_screens = (array) apply_filters( 'sb_admin_asset_screens', array() );

		if ( in_array( $hook_suffix, $allowed_screens, true ) ) {
			return true;
		}

		if ( '' !== $screen_id && in_array( $screen_id, $allowed_screens, true ) ) {
			return true;
		}

		if ( $this->has_sb_prefix( $screen_id ) || $this->has_sb_prefix( $hook_suffix ) ) {
			return true;
		}

		return $this->has_sb_prefix( $this->requested_page_slug() );
	}

	/**
	 * Whether a screen id / hook suffix belongs to SmartBook.
	 *
	 * WordPress computes a submenu page's hook name/screen id from
	 * sanitize_title() of its *top-level* menu's title, not that menu's
	 * slug: AdminMenu registers the top-level menu with title "SmartBook"
	 * (slug "sb_dashboard"), so every submenu page under it actually hooks
	 * as "smartbook_page_{slug}" (e.g. "smartbook_page_sb_books") --
	 * never "sb_..." -- with a single exception: the top-level menu's own
	 * page (the "Dashboard" entry, added via add_menu_page()) hooks as
	 * "toplevel_page_{menu_slug}", i.e. "toplevel_page_sb_dashboard",
	 * which *does* start with "sb_" once that prefix is stripped. Relying
	 * on this prefix alone therefore only ever matched the Dashboard page;
	 * every other SmartBook admin page silently never got its assets --
	 * see requested_page_slug() for the fix, which checks the reliable
	 * signal instead: the plugin's own "page" query var.
	 */
	private function has_sb_prefix( string $id ): bool {
		if ( '' === $id ) {
			return false;
		}

		if ( str_starts_with( $id, 'toplevel_page_' ) ) {
			$id = substr( $id, strlen( 'toplevel_page_' ) );
		}

		return str_starts_with( $id, 'sb_' );
	}

	/**
	 * The "page" query var of the current admin.php?page=... request, if
	 * any -- e.g. "sb_add_book". Unlike the screen id/hook suffix (see
	 * has_sb_prefix()'s doc comment), this is the literal menu slug this
	 * plugin registered, so it reliably starts with "sb_" for every
	 * SmartBook admin.php page regardless of how WordPress happened to
	 * compute that page's internal hook name.
	 */
	private function requested_page_slug(): string {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection, not a state-changing action.
	}
}
