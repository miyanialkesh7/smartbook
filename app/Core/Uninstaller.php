<?php
/**
 * Plugin uninstall handler.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Core;

use SmartBook\PostTypes\BookPostType;
use WP_Filesystem_Base;

/**
 * Permanently removes all data owned by the plugin. Invoked only from
 * uninstall.php, never on plain deactivation.
 */
final class Uninstaller {

	/**
	 * Uninstall entry point invoked from uninstall.php.
	 */
	public static function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		self::delete_options();
		self::delete_transients();
		self::clear_scheduled_events();
		self::revoke_capabilities();
		self::delete_generated_asset_directories();

		if ( is_multisite() ) {
			self::uninstall_for_network();
		}
	}

	/**
	 * Remove all options owned by the plugin on the current site.
	 */
	private static function delete_options(): void {
		$options = array(
			'sb_options',
			'sb_db_version',
			'sb_books_page_id',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Remove cached transients owned by the plugin, including the
	 * randomly-tokened "sb_import_*" session transients ImportSession
	 * creates per import/restore run, which can't be deleted by a fixed
	 * name and would otherwise only disappear on their own expiry.
	 */
	private static function delete_transients(): void {
		delete_transient( 'sb_cache' );

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_transient_sb_import_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_sb_import_' ) . '%'
			)
		);
	}

	/**
	 * Clear any scheduled cron events owned by the plugin.
	 */
	private static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( 'sb_cron_event' );
	}

	/**
	 * Strip the custom "sb_book"/"sb_books" capabilities Activator granted,
	 * from every role that currently holds them.
	 */
	private static function revoke_capabilities(): void {
		$capabilities = array_unique( BookPostType::capabilities() );
		$roles        = wp_roles();

		foreach ( array_keys( $roles->role_objects ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Remove the uploads/sb-qrcodes, uploads/sb-barcodes, and
	 * uploads/sb-imports directories and everything generated/uploaded
	 * into them, for the current site.
	 */
	private static function delete_generated_asset_directories(): void {
		foreach ( array( 'sb-qrcodes', 'sb-barcodes', 'sb-imports' ) as $directory_name ) {
			self::delete_directory( trailingslashit( wp_upload_dir()['basedir'] ) . $directory_name );
		}
	}

	/**
	 * Recursively remove a directory via WP_Filesystem, if it exists.
	 *
	 * @param string $directory Absolute path to the directory to remove.
	 */
	private static function delete_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$wp_filesystem->delete( $directory, true );
		}
	}

	/**
	 * Repeat the per-site cleanup across every site in a multisite network.
	 */
	private static function uninstall_for_network(): void {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );

			self::delete_options();
			self::delete_transients();
			self::clear_scheduled_events();
			self::revoke_capabilities();
			self::delete_generated_asset_directories();

			restore_current_blog();
		}
	}
}
