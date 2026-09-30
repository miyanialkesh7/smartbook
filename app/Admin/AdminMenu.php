<?php
/**
 * The SmartBook admin menu.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin;

use SmartBook\Admin\Pages\AddBookPage;
use SmartBook\Admin\Pages\AllLabelsPage;
use SmartBook\Admin\Pages\BarcodeLabelsPage;
use SmartBook\Admin\Pages\BookCardsPage;
use SmartBook\Admin\Pages\BooksPage;
use SmartBook\Admin\Pages\BorrowedBooksPage;
use SmartBook\Admin\Pages\DashboardPage;
use SmartBook\Admin\Pages\EditBookPage;
use SmartBook\Admin\Pages\ImportExportPage;
use SmartBook\Admin\Pages\LabelsPage;
use SmartBook\Admin\Pages\QrLabelsPage;
use SmartBook\Admin\Pages\SettingsPage;
use SmartBook\Admin\Pages\StatisticsPage;
use SmartBook\Core\Contracts\Hookable;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use SmartBook\Taxonomies\PublisherTaxonomy;
use SmartBook\Taxonomies\ShelfTaxonomy;

use function sb_option;

/**
 * Registers the top-level "SmartBook" admin menu and its eleven entries:
 * Dashboard, Books, Borrowed Books, Authors, Genres, Publishers, Shelves,
 * Labels, Statistics, Import Export, and Settings, plus six deliberately
 * hidden pages: the label-print pages "QR Labels", "Barcode Labels",
 * "All Labels", and "Book Cards" (linked to from the visible "Labels"
 * page instead of directly), and the custom "Add New Book"/"Edit Book"
 * forms (see register()).
 *
 * BookPostType and the four linked taxonomies are registered with
 * show_in_menu => false, so WordPress does not also auto-add its own
 * menu entries for them; every entry here is explicit, so the visible
 * menu is exactly those eleven items, no more.
 */
final class AdminMenu implements Hookable {

	/**
	 * Top-level and "Dashboard" submenu slug.
	 */
	public const PARENT_SLUG = 'sb_dashboard';

	/**
	 * Page slugs that are registered with add_submenu_page() (so they
	 * route, are capability-gated, and get a title) but must not appear
	 * in the rendered sidebar -- see hide_from_menu(). "sb_edit_book"'s
	 * own bare sidebar link (no "book_id") is what gets hidden here; the
	 * real, per-book "Edit" links (BooksListTable) carry a "book_id" and
	 * live in the books table, not #adminmenu, so this doesn't touch them.
	 * Listing "sb_all_labels"/"sb_book_cards" here is harmless even when
	 * either isn't registered this request (see register()) -- the
	 * selector simply matches nothing.
	 *
	 * @var string[]
	 */
	private const HIDDEN_SLUGS = array( 'sb_add_book', 'sb_edit_book', 'sb_qr_labels', 'sb_barcode_labels', 'sb_all_labels', 'sb_book_cards' );

	/**
	 * Constructor.
	 *
	 * @param DashboardPage     $dashboard Dashboard page renderer.
	 * @param BooksPage         $books Books list page renderer.
	 * @param BorrowedBooksPage $borrowed_books Borrowed books management page renderer.
	 * @param AddBookPage       $add_book Custom "Add New Book" form renderer.
	 * @param EditBookPage      $edit_book Custom "Edit Book" form renderer.
	 * @param LabelsPage        $labels Labels hub page renderer.
	 * @param StatisticsPage    $statistics Statistics page renderer.
	 * @param ImportExportPage  $import_export Import/export page renderer.
	 * @param QrLabelsPage      $qr_labels QR label print page renderer.
	 * @param BarcodeLabelsPage $barcode_labels Barcode label print page renderer.
	 * @param AllLabelsPage     $all_labels Combined QR + barcode label print page renderer.
	 * @param BookCardsPage     $book_cards Book detail card print page renderer.
	 * @param SettingsPage      $settings Settings page renderer.
	 */
	public function __construct(
		private readonly DashboardPage $dashboard,
		private readonly BooksPage $books,
		private readonly BorrowedBooksPage $borrowed_books,
		private readonly AddBookPage $add_book,
		private readonly EditBookPage $edit_book,
		private readonly LabelsPage $labels,
		private readonly StatisticsPage $statistics,
		private readonly ImportExportPage $import_export,
		private readonly QrLabelsPage $qr_labels,
		private readonly BarcodeLabelsPage $barcode_labels,
		private readonly AllLabelsPage $all_labels,
		private readonly BookCardsPage $book_cards,
		private readonly SettingsPage $settings
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_head', array( $this, 'hide_from_menu' ) );
		add_filter( 'parent_file', array( $this, 'fix_taxonomy_parent_file' ) );

		// Must be live before wp-admin/admin.php's own set_screen_options()
		// runs (it processes a submitted Screen Options form before the
		// "admin_menu" action even fires), so this is registered here --
		// unconditionally, on every request -- rather than inside
		// register()'s "load-{hook}" callback (see BooksPage::add_screen_options()),
		// which would be registered too late to save that very submission.
		add_filter( 'set_screen_option_sb_books_per_page', static fn ( mixed $status, string $option, mixed $value ): mixed => $value, 10, 3 );
	}

	/**
	 * Force WordPress to recognize "SmartBook" as the current top-level
	 * menu while on one of the four taxonomy screens (Authors, Genres,
	 * Publishers, Shelves).
	 *
	 * WordPress's wp-admin/edit-tags.php unconditionally sets $parent_file to
	 * "edit.php?post_type=$post_type" for any taxonomy attached to a
	 * custom post type -- but BookPostType is registered with
	 * show_in_menu => false, so that slug was never registered as a
	 * top-level menu at all. wp-admin/menu-header.php then looks for a
	 * top-level $menu entry whose slug equals $parent_file to decide
	 * which item gets the "current"/expanded treatment; finding none,
	 * it marks nothing current, and the *entire* SmartBook submenu
	 * collapses to hover-only on these four screens -- fixing just the
	 * submenu_file (see register()'s "&amp;" comment) isn't enough on
	 * its own, since that comparison never even runs without a matching
	 * parent first.
	 *
	 * @param string $parent_file Parent menu file.
	 */
	public function fix_taxonomy_parent_file( string $parent_file ): string {
		$screen = get_current_screen();

		if ( null === $screen || '' === (string) $screen->taxonomy ) {
			return $parent_file;
		}

		$taxonomies = array( AuthorTaxonomy::SLUG, GenreTaxonomy::SLUG, PublisherTaxonomy::SLUG, ShelfTaxonomy::SLUG );

		return in_array( $screen->taxonomy, $taxonomies, true ) ? self::PARENT_SLUG : $parent_file;
	}

	/**
	 * Register the top-level menu and every submenu entry.
	 */
	public function register(): void {
		add_menu_page(
			__( 'SmartBook', 'smartbook' ),
			__( 'SmartBook', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			self::PARENT_SLUG,
			array( $this->dashboard, 'render' ),
			$this->icon(),
			25
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Dashboard', 'smartbook' ),
			__( 'Dashboard', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			self::PARENT_SLUG,
			array( $this->dashboard, 'render' )
		);

		$books_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Books', 'smartbook' ),
			__( 'Books', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			'sb_books',
			array( $this->books, 'render' )
		);

		if ( false !== $books_hook ) {
			add_action( "load-{$books_hook}", array( $this->books, 'add_screen_options' ) );
		}

		// Visible only when the Borrow Management feature itself is on --
		// otherwise there is nothing this page could ever show.
		if ( sb_option( 'enable_borrow', true ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Borrowed Books', 'smartbook' ),
				$this->menu_label_with_bubble( __( 'Borrowed Books', 'smartbook' ), $this->borrowed_books->pending_request_count() ),
				BookPostType::CAP_EDIT_BOOKS,
				'sb_borrowed_books',
				array( $this->borrowed_books, 'render' )
			);
		}

		// "&amp;", not a literal "&": wp-admin/edit-tags.php builds its own
		// $submenu_file as "edit-tags.php?taxonomy=$taxonomy&amp;post_type=$post_type"
		// and wp-admin/menu-header.php compares that against each
		// registered slug with strict string equality (no normalization).
		// A literal "&" here would never match, so WordPress would never
		// recognize "SmartBook" as the current parent while on any of
		// these four taxonomy screens -- the sidebar's SmartBook submenu
		// would collapse instead of staying expanded, even though the
		// screen itself still renders fine (the browser's own query
		// string is unaffected by this, it's purely an internal
		// comparison string).
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Authors', 'smartbook' ),
			__( 'Authors', 'smartbook' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . AuthorTaxonomy::SLUG . '&amp;post_type=' . BookPostType::SLUG
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Genres', 'smartbook' ),
			__( 'Genres', 'smartbook' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . GenreTaxonomy::SLUG . '&amp;post_type=' . BookPostType::SLUG
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Publishers', 'smartbook' ),
			__( 'Publishers', 'smartbook' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . PublisherTaxonomy::SLUG . '&amp;post_type=' . BookPostType::SLUG
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Shelves', 'smartbook' ),
			__( 'Shelves', 'smartbook' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . ShelfTaxonomy::SLUG . '&amp;post_type=' . BookPostType::SLUG
		);

		// Visible only when there's something to print; both label
		// settings off means the hub would have nothing to link to.
		if ( sb_option( 'enable_qr', true ) || sb_option( 'enable_barcode', true ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Labels', 'smartbook' ),
				__( 'Labels', 'smartbook' ),
				BookPostType::CAP_EDIT_BOOKS,
				'sb_labels',
				array( $this->labels, 'render' )
			);
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Statistics', 'smartbook' ),
			__( 'Statistics', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			'sb_statistics',
			array( $this->statistics, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Import Export', 'smartbook' ),
			__( 'Import Export', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			'sb_import_export',
			array( $this->import_export, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'smartbook' ),
			__( 'Settings', 'smartbook' ),
			'manage_options',
			'sb_settings',
			array( $this->settings, 'render' )
		);

		// Registered so admin.php?page=sb_add_book routes and is
		// capability-gated like any other page; kept out of the visible
		// menu by hide_from_menu() (CSS), not remove_submenu_page() --
		// see that method's doc comment for why. It's reached via the
		// "Add New" button on the Books page, the Dashboard's quick link,
		// and a redirect from WordPress's own post-new.php (see
		// AddBookPage::redirect_legacy_add_new()), not via direct
		// navigation.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Add New Book', 'smartbook' ),
			__( 'Add New Book', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			'sb_add_book',
			array( $this->add_book, 'render' )
		);

		// Same as "Add New Book" above, but for editing an existing one:
		// reached via the books table's per-row "Edit" link/title link
		// (which append their own "book_id"), or a redirect from
		// WordPress's own post.php edit screen (see
		// EditBookPage::redirect_native_edit()), not via direct navigation.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Edit Book', 'smartbook' ),
			__( 'Edit Book', 'smartbook' ),
			BookPostType::CAP_EDIT_BOOKS,
			'sb_edit_book',
			array( $this->edit_book, 'render' )
		);

		// Registered so admin.php?page=sb_qr_labels (and sb_barcode_labels
		// below) route and are capability-gated like any other page;
		// kept out of the visible menu by hide_from_menu() (CSS), not
		// remove_submenu_page() -- see that method's doc comment for why.
		// Both are reached via the visible "Labels" hub page above and
		// their respective meta boxes' "Print Label" links, not via direct
		// navigation. Skipped entirely when the matching feature is
		// disabled (Settings\Settings), so the page simply doesn't exist
		// rather than existing-but-hidden.
		if ( sb_option( 'enable_qr', true ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'QR Labels', 'smartbook' ),
				__( 'QR Labels', 'smartbook' ),
				BookPostType::CAP_EDIT_BOOKS,
				'sb_qr_labels',
				array( $this->qr_labels, 'render' )
			);
		}

		if ( sb_option( 'enable_barcode', true ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Barcode Labels', 'smartbook' ),
				__( 'Barcode Labels', 'smartbook' ),
				BookPostType::CAP_EDIT_BOOKS,
				'sb_barcode_labels',
				array( $this->barcode_labels, 'render' )
			);
		}

		// Only registered when both label kinds are enabled -- with just
		// one on, this would only ever duplicate that single-type page.
		if ( sb_option( 'enable_qr', true ) && sb_option( 'enable_barcode', true ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'All Labels', 'smartbook' ),
				__( 'All Labels', 'smartbook' ),
				BookPostType::CAP_EDIT_BOOKS,
				'sb_all_labels',
				array( $this->all_labels, 'render' )
			);
		}

		// Only needs a QR code, not a barcode, so this is gated on
		// "enable_qr" alone.
		if ( sb_option( 'enable_qr', true ) ) {
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Book Cards', 'smartbook' ),
				__( 'Book Cards', 'smartbook' ),
				BookPostType::CAP_EDIT_BOOKS,
				'sb_book_cards',
				array( $this->book_cards, 'render' )
			);
		}
	}

	/**
	 * Hide HIDDEN_SLUGS' links from the rendered admin sidebar with CSS,
	 * instead of remove_submenu_page().
	 *
	 * Calling remove_submenu_page() deletes the entry from the global $submenu
	 * array, which breaks WordPress's own access check for *direct*
	 * admin.php?page=... navigation to a page whose parent is a custom
	 * top-level menu (as PARENT_SLUG is): user_can_access_admin_page()
	 * calls get_admin_page_parent() with no arguments, which re-derives
	 * the current page's parent slug by searching $submenu -- and once
	 * the entry is gone, it can no longer find it, resolves to no parent,
	 * and computes a different (wrong) hook name than the one the page
	 * was actually registered under. The mismatch makes WordPress deny
	 * access with "Sorry, you are not allowed to access this page,"
	 * exactly on the pages this was meant to keep reachable. CSS hides
	 * the link without touching $submenu, so registration and the
	 * capability check both keep working.
	 */
	public function hide_from_menu(): void {
		$selectors = array();

		foreach ( self::HIDDEN_SLUGS as $slug ) {
			$href        = esc_attr( 'admin.php?page=' . $slug );
			$selectors[] = sprintf( '#adminmenu a[href="%s"]', $href );
			$selectors[] = sprintf( '#adminmenu li:has(> a[href="%s"])', $href );
		}

		printf( '<style>%s{display:none;}</style>', implode( ',', $selectors ) );
	}

	/**
	 * Append a WordPress-native notification bubble to a menu label --
	 * the same ".awaiting-mod"/".pending-count" markup core itself uses
	 * for the Comments menu's moderation count, styled by wp-admin's own
	 * CSS with no additions needed here. '' count leaves the label
	 * untouched.
	 *
	 * @param string $label Label.
	 * @param int    $count Item count.
	 */
	private function menu_label_with_bubble( string $label, int $count ): string {
		if ( $count <= 0 ) {
			return $label;
		}

		return $label . sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
			$count
		);
	}

	/**
	 * Build a URL-encoded SVG data URI for the top-level menu icon,
	 * following the WordPress convention of a solid, single-colour glyph
	 * that the admin CSS recolours to match the current colour scheme.
	 *
	 * URL-encoding (rather than base64) keeps the SVG markup readable in
	 * the source and avoids base64_encode(), which WordPress.org's Plugin
	 * Check flags as a possible code-obfuscation pattern even when, as
	 * here, it is only ever applied to this fixed, static icon markup.
	 */
	private function icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black">'
			. '<path d="M10 3C8.35 1.9 6.02 1.5 4 1.5A1.5 1.5 0 0 0 2.5 3v12A1.5 1.5 0 0 0 4 16.5c1.86 0 3.98.36 5.55 1.34.14.09.32.14.45.14s.31-.05.45-.14C12.02 16.86 14.14 16.5 16 16.5a1.5 1.5 0 0 0 1.5-1.5V3A1.5 1.5 0 0 0 16 1.5c-2.02 0-4.35.4-6 1.5Zm-.75 12.1c-1.6-.75-3.55-1.1-5.25-1.1a.25.25 0 0 1-.25-.25V3a.25.25 0 0 1 .25-.25c1.7 0 3.65.35 5.25 1.32V15.1Zm1.5-10.03c1.6-.97 3.55-1.32 5.25-1.32.14 0 .25.11.25.25v11.75a.25.25 0 0 1-.25.25c-1.7 0-3.65.35-5.25 1.1V5.07Z"/>'
			. '</svg>';

		return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $svg );
	}
}
