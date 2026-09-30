<?php
/**
 * Plugin activation handler.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Core;

use SmartBook\Frontend\BooksShortcode;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Taxonomies\TaxonomyServiceProvider;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Default option values seeded on first activation.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULT_OPTIONS = array(
		'enable_logging' => true,
		'log_level'      => 'error',
	);

	/**
	 * Activation entry point registered via register_activation_hook().
	 */
	public static function activate(): void {
		if ( ! Requirements::satisfied() ) {
			deactivate_plugins( SB_BASENAME );

			wp_die(
				esc_html( Requirements::unsatisfied_message() ),
				esc_html__( 'Plugin activation error', 'smartbook' ),
				array( 'back_link' => true )
			);
		}

		self::create_default_options();
		self::maybe_upgrade();

		// Post types and taxonomies are normally registered on the "init"
		// hook, which has already fired by the time a fresh activation
		// reaches this point. Register synchronously here so the rewrite
		// rules generated below actually include them.
		( new BookPostType() )->register();
		self::register_taxonomies();
		self::grant_capabilities();
		self::create_books_page();

		flush_rewrite_rules();
	}

	/**
	 * Seed default options on first-ever activation.
	 */
	private static function create_default_options(): void {
		if ( false === get_option( 'sb_options' ) ) {
			add_option( 'sb_options', self::DEFAULT_OPTIONS );
		}

		if ( false === get_option( 'sb_db_version' ) ) {
			add_option( 'sb_db_version', SB_VERSION );
		}
	}

	/**
	 * Run schema/data migrations when reactivating an older install.
	 */
	private static function maybe_upgrade(): void {
		$installed = (string) get_option( 'sb_db_version', '0' );

		if ( version_compare( $installed, SB_VERSION, '<' ) ) {
			// Place versioned schema/data migrations here as the plugin evolves.
			update_option( 'sb_db_version', SB_VERSION );
		}
	}

	/**
	 * Synchronously register every taxonomy the plugin defines.
	 */
	private static function register_taxonomies(): void {
		foreach ( TaxonomyServiceProvider::TAXONOMIES as $taxonomy_class ) {
			( new $taxonomy_class() )->register();
		}
	}

	/**
	 * Grant the administrator role every custom "sb_book"/"sb_books"
	 * capability. A custom capability_type starts out granted to no one,
	 * so without this an administrator would be locked out of the post
	 * type they just activated.
	 */
	private static function grant_capabilities(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		foreach ( array_unique( BookPostType::capabilities() ) as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Publish a page containing the "[smartbook_books]" shortcode, so a
	 * site has a working "All Books" listing immediately on activation
	 * without the user needing to build one by hand. Runs only once:
	 * skipped whenever the previously-created page (tracked via the
	 * "sb_books_page_id" option) still exists and isn't trashed, but
	 * self-heals by creating a fresh one if it was deleted. Deactivator
	 * deliberately never removes this page -- see that class's doc
	 * comment on not deleting user data.
	 */
	private static function create_books_page(): void {
		$page_id = (int) get_option( 'sb_books_page_id', 0 );

		if ( $page_id > 0 && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'All Books', 'smartbook' ),
				'post_content' => '[' . BooksShortcode::TAG . ']',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'sb_books_page_id', $page_id );
		}
	}
}
