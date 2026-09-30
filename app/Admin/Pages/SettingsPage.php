<?php
/**
 * The SmartBook settings page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Core\Contracts\Hookable;
use SmartBook\Settings\Settings;

/**
 * Registers the Settings API sections/fields for the plugin's options
 * and renders the settings form. Persistence, sanitization, and the
 * nonce/capability check on save are all handled by options.php via
 * register_setting() (see Settings\SettingsServiceProvider); this class
 * only supplies the field markup and the page shell.
 *
 * Four tabs, one per section: General (logging), Features (the five
 * "Enable ..." toggles other modules read via sb_option() to turn
 * themselves on/off -- see QrCodeMetaBox, BarcodeMetaBox,
 * BookDetailsMetaBox, AdminMenu, BooksListTable, BooksPage,
 * DashboardPage, and Notifications\BorrowNotifications/
 * Notifications\OverdueReminders), Display (currency/date format, read by the
 * sb_format_currency()/sb_format_date() helpers), and External APIs
 * (optional Google Books / Open Library lookups). Each tab is its own
 * Settings API "page" (do_settings_sections() only renders one at a
 * time), all wrapped in the shared ".sb-tabs" strip (see
 * Admin\Pages\ImportExportPage and sb-admin.js's sb_initTabs()) so
 * without JavaScript every tab simply stacks and still submits as one
 * settings_fields()/options.php form.
 */
final class SettingsPage implements Hookable {

	/**
	 * Settings API "page" slugs, one per tab.
	 */
	private const TAB_GENERAL = 'sb_settings_general';
	private const TAB_FEATURES = 'sb_settings_features';
	private const TAB_DISPLAY = 'sb_settings_display';
	private const TAB_APIS = 'sb_settings_apis';

	/**
	 * Settings section ids.
	 */
	private const SECTION_GENERAL = 'sb_settings_general_section';
	private const SECTION_FEATURES = 'sb_settings_features_section';
	private const SECTION_DISPLAY = 'sb_settings_display_section';
	private const SECTION_APIS = 'sb_settings_apis_section';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository, used to read current values for the form.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_fields' ) );
	}

	/**
	 * Register every Settings API section and field.
	 */
	public function register_fields(): void {
		$this->register_general_section();
		$this->register_features_section();
		$this->register_display_section();
		$this->register_apis_section();
	}

	/**
	 * "General" section: logging.
	 */
	private function register_general_section(): void {
		add_settings_section( self::SECTION_GENERAL, __( 'General', 'smartbook' ), '__return_false', self::TAB_GENERAL );

		add_settings_field(
			'sb_enable_logging',
			__( 'Enable Logging', 'smartbook' ),
			array( $this, 'render_enable_logging_field' ),
			self::TAB_GENERAL,
			self::SECTION_GENERAL
		);

		add_settings_field(
			'sb_log_level',
			__( 'Log Level', 'smartbook' ),
			array( $this, 'render_log_level_field' ),
			self::TAB_GENERAL,
			self::SECTION_GENERAL
		);
	}

	/**
	 * "Features" section: the five plugin-wide feature toggles.
	 */
	private function register_features_section(): void {
		add_settings_section(
			self::SECTION_FEATURES,
			__( 'Features', 'smartbook' ),
			array( $this, 'render_features_intro' ),
			self::TAB_FEATURES
		);

		add_settings_field(
			'sb_enable_qr',
			__( 'QR Codes', 'smartbook' ),
			array( $this, 'render_enable_qr_field' ),
			self::TAB_FEATURES,
			self::SECTION_FEATURES
		);

		add_settings_field(
			'sb_enable_barcode',
			__( 'Barcodes', 'smartbook' ),
			array( $this, 'render_enable_barcode_field' ),
			self::TAB_FEATURES,
			self::SECTION_FEATURES
		);

		add_settings_field(
			'sb_enable_borrow',
			__( 'Borrow Management', 'smartbook' ),
			array( $this, 'render_enable_borrow_field' ),
			self::TAB_FEATURES,
			self::SECTION_FEATURES
		);

		add_settings_field(
			'sb_enable_reading_tracker',
			__( 'Reading Tracker', 'smartbook' ),
			array( $this, 'render_enable_reading_tracker_field' ),
			self::TAB_FEATURES,
			self::SECTION_FEATURES
		);

		add_settings_field(
			'sb_enable_email_notifications',
			__( 'Email Notifications', 'smartbook' ),
			array( $this, 'render_enable_email_notifications_field' ),
			self::TAB_FEATURES,
			self::SECTION_FEATURES
		);
	}

	/**
	 * "Display" section: currency and date format.
	 */
	private function register_display_section(): void {
		add_settings_section( self::SECTION_DISPLAY, __( 'Display', 'smartbook' ), '__return_false', self::TAB_DISPLAY );

		add_settings_field(
			'sb_currency',
			__( 'Currency', 'smartbook' ),
			array( $this, 'render_currency_field' ),
			self::TAB_DISPLAY,
			self::SECTION_DISPLAY
		);

		add_settings_field(
			'sb_date_format',
			__( 'Date Format', 'smartbook' ),
			array( $this, 'render_date_format_field' ),
			self::TAB_DISPLAY,
			self::SECTION_DISPLAY
		);
	}

	/**
	 * "External APIs" section: optional Google Books / Open Library lookups.
	 */
	private function register_apis_section(): void {
		add_settings_section(
			self::SECTION_APIS,
			__( 'External APIs', 'smartbook' ),
			array( $this, 'render_apis_intro' ),
			self::TAB_APIS
		);

		add_settings_field(
			'sb_google_books_enabled',
			__( 'Google Books', 'smartbook' ),
			array( $this, 'render_google_books_enabled_field' ),
			self::TAB_APIS,
			self::SECTION_APIS
		);

		add_settings_field(
			'sb_google_books_api_key',
			__( 'Google Books API Key', 'smartbook' ),
			array( $this, 'render_google_books_api_key_field' ),
			self::TAB_APIS,
			self::SECTION_APIS
		);

		add_settings_field(
			'sb_open_library_enabled',
			__( 'Open Library', 'smartbook' ),
			array( $this, 'render_open_library_enabled_field' ),
			self::TAB_APIS,
			self::SECTION_APIS
		);
	}

	/**
	 * Render the "Enable Logging" checkbox.
	 */
	public function render_enable_logging_field(): void {
		$this->render_checkbox( 'enable_logging', __( 'Write plugin log entries to disk.', 'smartbook' ) );
	}

	/**
	 * Render the "Log Level" select.
	 */
	public function render_log_level_field(): void {
		$value  = (string) $this->settings->get( 'log_level', 'error' );
		$levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

		printf( '<select name="%s[log_level]">', esc_attr( Settings::option_name() ) );

		foreach ( $levels as $level ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $level ),
				selected( $value, $level, false ),
				esc_html( ucfirst( $level ) )
			);
		}

		echo '</select>';
		printf( '<p class="description">%s</p>', esc_html__( 'Only entries at or above this severity are written to the log file.', 'smartbook' ) );
	}

	/**
	 * One-line intro echoed above the Features section's fields.
	 */
	public function render_features_intro(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Turn optional features on or off. Disabling a feature hides its screens, meta boxes, and bulk actions without deleting any data already stored for it.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable QR Codes" checkbox.
	 */
	public function render_enable_qr_field(): void {
		$this->render_checkbox(
			'enable_qr',
			__( 'Automatically generate a QR code for every book, show the QR Code meta box, and offer QR label printing.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable Barcodes" checkbox.
	 */
	public function render_enable_barcode_field(): void {
		$this->render_checkbox(
			'enable_barcode',
			__( 'Automatically generate a barcode for every book, show the Barcode meta box, offer barcode label printing, and enable the barcode scan box on the Books screen.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable Borrow Management" checkbox.
	 */
	public function render_enable_borrow_field(): void {
		$this->render_checkbox(
			'enable_borrow',
			__( 'Show the Borrow Management fields on each book and the Borrow Reminders list on the dashboard.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable Reading Tracker" checkbox.
	 */
	public function render_enable_reading_tracker_field(): void {
		$this->render_checkbox(
			'enable_reading_tracker',
			__( 'Show the Reading Status/Progress fields on each book, plus the related dashboard stats and chart.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable Email Notifications" checkbox.
	 */
	public function render_enable_email_notifications_field(): void {
		$this->render_checkbox(
			'enable_email_notifications',
			__( 'Email the admin on every new borrow/return request, email the requester when it\'s approved or denied, email the borrower when a return is confirmed, and send a daily reminder for overdue/due-soon loans.', 'smartbook' )
		);
	}

	/**
	 * Render the "Currency" select.
	 */
	public function render_currency_field(): void {
		$value = (string) $this->settings->get( 'currency', 'USD' );

		printf( '<select name="%s[currency]">', esc_attr( Settings::option_name() ) );

		foreach ( Settings::CURRENCIES as $code => $symbol ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $value, $code, false ),
				esc_html( sprintf( '%1$s (%2$s)', $code, $symbol ) )
			);
		}

		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Used by SmartBook wherever a price is displayed.', 'smartbook' )
		);
	}

	/**
	 * Render the "Date Format" select, with a live preview of each option
	 * formatted against the current date.
	 */
	public function render_date_format_field(): void {
		$value = (string) $this->settings->get( 'date_format', 'Y-m-d' );
		$now   = time();

		printf( '<select name="%s[date_format]">', esc_attr( Settings::option_name() ) );

		foreach ( Settings::DATE_FORMATS as $format ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $format ),
				selected( $value, $format, false ),
				esc_html( sprintf( '%1$s (%2$s)', $format, date_i18n( $format, $now ) ) )
			);
		}

		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Used by SmartBook wherever a date is displayed.', 'smartbook' )
		);
	}

	/**
	 * One-line intro echoed above the External APIs section's fields.
	 */
	public function render_apis_intro(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Optional lookups a future "search by title/ISBN" tool can use to pre-fill a new book. Neither is required for SmartBook to work.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable Google Books" checkbox.
	 */
	public function render_google_books_enabled_field(): void {
		$this->render_checkbox( 'google_books_enabled', __( 'Allow SmartBook to look up book data via the Google Books API.', 'smartbook' ) );
	}

	/**
	 * Render the Google Books API key field.
	 */
	public function render_google_books_api_key_field(): void {
		$value = (string) $this->settings->get( 'google_books_api_key', '' );

		printf(
			'<input type="text" class="regular-text code" autocomplete="off" name="%1$s[google_books_api_key]" value="%2$s" />',
			esc_attr( Settings::option_name() ),
			esc_attr( $value )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Required only when Google Books is enabled above. Get a key from the Google Cloud Console.', 'smartbook' )
		);
	}

	/**
	 * Render the "Enable Open Library" checkbox.
	 */
	public function render_open_library_enabled_field(): void {
		$this->render_checkbox(
			'open_library_enabled',
			__( 'Allow SmartBook to look up book data via the Open Library API. Open Library is free and does not require an API key.', 'smartbook' )
		);
	}

	/**
	 * Shared renderer for a single boolean setting's checkbox.
	 */
	private function render_checkbox( string $key, string $description ): void {
		$value = (bool) $this->settings->get( $key, false );

		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( Settings::option_name() ),
			esc_attr( $key ),
			checked( true, $value, false ),
			esc_html( $description )
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smartbook' ) );
		}

		echo '<div class="wrap sb-admin-page">';
		printf( '<h1>%s</h1>', esc_html__( 'SmartBook Settings', 'smartbook' ) );

		echo '<form method="post" action="options.php">';
		settings_fields( Settings::OPTION_GROUP );

		printf( '<div class="sb-tabs" data-sb-tabs data-sb-active-tab="%s">', esc_attr( self::TAB_GENERAL ) );
		$this->render_tab_panel( self::TAB_GENERAL, __( 'General', 'smartbook' ) );
		$this->render_tab_panel( self::TAB_FEATURES, __( 'Features', 'smartbook' ) );
		$this->render_tab_panel( self::TAB_DISPLAY, __( 'Display', 'smartbook' ) );
		$this->render_tab_panel( self::TAB_APIS, __( 'External APIs', 'smartbook' ) );
		echo '</div>';

		submit_button();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render one tab panel: its (JS-hidden-and-replaced-by-a-tab-button)
	 * heading plus every Settings API section registered to that tab's
	 * "page" slug.
	 */
	private function render_tab_panel( string $tab, string $title ): void {
		printf( '<div class="sb-tabs__panel" data-sb-tab-panel="%s">', esc_attr( $tab ) );
		printf( '<h2>%s</h2>', esc_html( $title ) );
		do_settings_sections( $tab );
		echo '</div>';
	}
}
