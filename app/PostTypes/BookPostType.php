<?php
/**
 * The "sb_book" custom post type.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Core\Contracts\Hookable;
use WP_REST_Posts_Controller;

/**
 * Registers the "sb_book" post type.
 *
 * Uses a dedicated capability_type ("sb_book"/"sb_books") rather than the
 * default "post" capabilities, so book permissions can be granted to
 * roles independently of ordinary post-editing rights. Because a custom
 * capability_type starts out granted to no one, Activator explicitly
 * grants these capabilities to the administrator role on activation; see
 * self::capabilities() and Core\Activator::grant_capabilities().
 */
final class BookPostType implements Hookable {

	/**
	 * Post type slug.
	 */
	public const SLUG = 'sb_book';

	/**
	 * The "edit_posts"-equivalent custom capability, exposed as a constant
	 * so other modules (admin menu, admin pages) can gate access without
	 * duplicating the literal string.
	 */
	public const CAP_EDIT_BOOKS = 'edit_sb_books';

	/**
	 * The "edit_others_posts"-equivalent custom capability. Users who lack
	 * this capability may only act on books they authored themselves; see
	 * self::author_scope_args().
	 */
	public const CAP_EDIT_OTHERS_BOOKS = 'edit_others_sb_books';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the post type with WordPress.
	 *
	 * Called both from the "init" hook (register_hooks()) and directly
	 * and synchronously from Core\Activator, since activation runs after
	 * "init" has already fired for that request and flush_rewrite_rules()
	 * needs the rewrite rules to exist beforehand.
	 */
	public function register(): void {
		register_post_type( self::SLUG, $this->args() );
	}

	/**
	 * WP_Query/get_posts() args that scope a books query to the current
	 * user's own books, or no scoping at all for a user allowed to see
	 * everyone's. Shared by every screen that lists, exports, or bulk
	 * prints books, so "own books only" roles can't be bypassed on one
	 * screen just because another screen forgot to add the check.
	 *
	 * @return array<string, int>
	 */
	public static function author_scope_args(): array {
		if ( current_user_can( self::CAP_EDIT_OTHERS_BOOKS ) ) {
			return array();
		}

		return array( 'author' => get_current_user_id() );
	}

	/**
	 * Meta capability => custom capability map used by both the post type
	 * registration and Core\Activator's role capability grant.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'edit_post'              => 'edit_sb_book',
			'read_post'              => 'read_sb_book',
			'delete_post'            => 'delete_sb_book',
			'edit_posts'             => self::CAP_EDIT_BOOKS,
			'edit_others_posts'      => self::CAP_EDIT_OTHERS_BOOKS,
			'publish_posts'          => 'publish_sb_books',
			'read_private_posts'     => 'read_private_sb_books',
			'delete_posts'           => 'delete_sb_books',
			'delete_private_posts'   => 'delete_private_sb_books',
			'delete_published_posts' => 'delete_published_sb_books',
			'delete_others_posts'    => 'delete_others_sb_books',
			'edit_private_posts'     => 'edit_private_sb_books',
			'edit_published_posts'   => 'edit_published_sb_books',
			'create_posts'           => 'create_sb_books',
		);
	}

	/**
	 * Full argument set passed to register_post_type().
	 *
	 * @return array<string, mixed>
	 */
	private function args(): array {
		return array(
			'labels'                => $this->labels(),
			'description'           => __( 'A catalog of books managed by SmartBook.', 'smartbook' ),
			'public'                => true,
			'publicly_queryable'    => true,
			'show_ui'               => true,
			// The list/edit screens still exist and work when linked to
			// directly; Admin\AdminMenu adds the explicit "Books" submenu
			// entry so it lands under the SmartBook parent menu instead of
			// WordPress auto-creating its own top-level menu for it.
			'show_in_menu'          => false,
			'show_in_admin_bar'     => true,
			'show_in_nav_menus'     => true,
			'show_in_rest'          => true,
			'rest_base'             => 'sb_books',
			'rest_controller_class' => WP_REST_Posts_Controller::class,
			'query_var'             => true,
			'rewrite'               => array(
				'slug'       => 'books',
				'with_front' => false,
			),
			'capability_type'       => array( 'sb_book', 'sb_books' ),
			'capabilities'          => self::capabilities(),
			'map_meta_cap'          => true,
			'has_archive'           => true,
			'hierarchical'          => false,
			'menu_position'         => 20,
			'menu_icon'             => 'dashicons-book-alt',
			'supports'              => array( 'title', 'editor', 'thumbnail', 'revisions', 'author', 'custom-fields', 'comments' ),
			'can_export'            => true,
			'delete_with_user'      => false,
			'exclude_from_search'   => false,
		);
	}

	/**
	 * Full label set for the post type.
	 *
	 * @return array<string, string>
	 */
	private function labels(): array {
		return array(
			'name'                  => _x( 'Books', 'Post type general name', 'smartbook' ),
			'singular_name'         => _x( 'Book', 'Post type singular name', 'smartbook' ),
			'menu_name'             => _x( 'SmartBook', 'Admin Menu text', 'smartbook' ),
			'name_admin_bar'        => _x( 'Book', 'Add New on Toolbar', 'smartbook' ),
			'add_new'               => __( 'Add New', 'smartbook' ),
			'add_new_item'          => __( 'Add New Book', 'smartbook' ),
			'new_item'              => __( 'New Book', 'smartbook' ),
			'edit_item'             => __( 'Edit Book', 'smartbook' ),
			'view_item'             => __( 'View Book', 'smartbook' ),
			'view_items'            => __( 'View Books', 'smartbook' ),
			'all_items'             => __( 'All Books', 'smartbook' ),
			'search_items'          => __( 'Search Books', 'smartbook' ),
			'parent_item_colon'     => __( 'Parent Book:', 'smartbook' ),
			'not_found'             => __( 'No books found.', 'smartbook' ),
			'not_found_in_trash'    => __( 'No books found in Trash.', 'smartbook' ),
			'featured_image'        => _x( 'Book Cover Image', 'Overrides the "Featured Image" phrase', 'smartbook' ),
			'set_featured_image'    => _x( 'Set cover image', 'Overrides the "Set featured image" phrase', 'smartbook' ),
			'remove_featured_image' => _x( 'Remove cover image', 'Overrides the "Remove featured image" phrase', 'smartbook' ),
			'use_featured_image'    => _x( 'Use as cover image', 'Overrides the "Use as featured image" phrase', 'smartbook' ),
			'archives'              => _x( 'Book archives', 'The post type archive label used in nav menus', 'smartbook' ),
			'insert_into_item'      => _x( 'Insert into book', 'Overrides the "Insert into post" phrase', 'smartbook' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this book', 'Overrides the "Uploaded to this post" phrase', 'smartbook' ),
			'filter_items_list'     => _x( 'Filter books list', 'Screen reader text for the filter links', 'smartbook' ),
			'items_list_navigation' => _x( 'Books list navigation', 'Screen reader text for the pagination', 'smartbook' ),
			'items_list'            => _x( 'Books list', 'Screen reader text for the items list', 'smartbook' ),
		);
	}
}
