<?php
/**
 * Plugin settings repository.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin repository around a single autoloaded option.
 *
 * All plugin configuration lives in one option row (rather than one
 * option per field) to keep the number of autoloaded rows WordPress has
 * to fetch on every request to a minimum.
 */
final class Settings {

	/**
	 * Name of the option this repository is backed by.
	 */
	private const OPTION_NAME = 'sb_options';

	/**
	 * Settings API option group name, used by both SettingsServiceProvider
	 * (register_setting()) and Admin\Pages\SettingsPage (settings_fields()).
	 */
	public const OPTION_GROUP = 'sb_options_group';

	/**
	 * Default values used when no stored value exists for a key.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'enable_logging'              => true,
		'log_level'                   => 'error',
		'currency'                    => 'USD',
		'date_format'                 => 'Y-m-d',
		'enable_qr'                   => true,
		'enable_barcode'              => true,
		'enable_borrow'               => true,
		'enable_reading_tracker'      => true,
		'enable_email_notifications'  => true,
		'google_books_enabled'        => false,
		'google_books_api_key'        => '',
		'open_library_enabled'        => false,
	);

	/**
	 * Severity keywords accepted for the "log_level" setting.
	 *
	 * @var string[]
	 */
	private const LOG_LEVELS = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

	/**
	 * Boolean setting keys, i.e. every setting backed by an HTML checkbox.
	 * A checkbox sends no value at all when unchecked (unlike every other
	 * input type), so these are sanitized uniformly: an absent key means
	 * "unchecked" (false), never "fall back to the default" -- see
	 * sanitize()'s doc comment for why that distinction matters.
	 *
	 * @var string[]
	 */
	private const BOOLEAN_KEYS = array(
		'enable_logging',
		'enable_qr',
		'enable_barcode',
		'enable_borrow',
		'enable_reading_tracker',
		'enable_email_notifications',
		'google_books_enabled',
		'open_library_enabled',
	);

	/**
	 * Currency code => symbol, offered by the "currency" setting and used
	 * by the global sb_format_currency() helper (see Helpers/helpers.php).
	 *
	 * @var array<string, string>
	 */
	public const CURRENCIES = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'JPY' => '¥',
		'CNY' => '¥',
		'INR' => '₹',
		'BDT' => '৳',
		'CAD' => 'CA$',
		'AUD' => 'AU$',
		'CHF' => 'Fr',
	);

	/**
	 * PHP date() format strings offered by the "date_format" setting and
	 * used by the global sb_format_date() helper.
	 *
	 * @var string[]
	 */
	public const DATE_FORMATS = array( 'Y-m-d', 'm/d/Y', 'd/m/Y', 'd.m.Y', 'd-m-Y', 'F j, Y', 'M j, Y', 'j F Y' );

	/**
	 * Current values, defaults merged with whatever is stored in the database.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Load and merge the stored option with the defaults.
	 */
	public function __construct() {
		$stored = get_option( self::OPTION_NAME, array() );

		$this->values = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Name of the backing option, exposed for use with register_setting().
	 */
	public static function option_name(): string {
		return self::OPTION_NAME;
	}

	/**
	 * All current setting values.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $fallback_value Value to return when the key isn't set.
	 */
	public function get( string $key, mixed $fallback_value = null ): mixed {
		return $this->values[ $key ] ?? $fallback_value;
	}

	/**
	 * Write a single setting and persist it immediately.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, mixed $value ): void {
		$this->values[ $key ] = $value;
		$this->persist();
	}

	/**
	 * Merge and persist several settings at once.
	 *
	 * @param array<string, mixed> $values Values to merge into the current settings.
	 */
	public function set_many( array $values ): void {
		$this->values = array_merge( $this->values, $values );
		$this->persist();
	}

	/**
	 * Human-readable symbol for a currency code, falling back to the code
	 * itself if it isn't one of CURRENCIES.
	 */
	public static function currency_symbol( string $code ): string {
		return self::CURRENCIES[ $code ] ?? $code;
	}

	/**
	 * Sanitize a raw settings array, e.g. as a register_setting() sanitize
	 * callback or before persisting. Unknown keys are dropped and every
	 * known key is coerced to a safe, expected type.
	 *
	 * Every key in BOOLEAN_KEYS is read with `?? false`, not
	 * `?? self::DEFAULTS[...]`: the only caller that ever hands sanitize()
	 * a genuinely incomplete array is the Settings API form submission
	 * (persist() always sanitizes the full merged $this->values, so every
	 * key is already present there). In that form-submission array, a
	 * missing checkbox key means the box was unchecked, not "value
	 * unknown, use the default" -- falling back to the default would make
	 * an already-true checkbox impossible to ever switch off.
	 *
	 * @param mixed $values Raw values to sanitize.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $values ): array {
		$values = is_array( $values ) ? $values : array();

		$booleans = array();

		foreach ( self::BOOLEAN_KEYS as $key ) {
			$booleans[ $key ] = (bool) ( $values[ $key ] ?? false );
		}

		$log_level   = isset( $values['log_level'] ) ? (string) $values['log_level'] : self::DEFAULTS['log_level'];
		$currency    = isset( $values['currency'] ) ? strtoupper( sanitize_text_field( (string) $values['currency'] ) ) : self::DEFAULTS['currency'];
		$date_format = isset( $values['date_format'] ) ? (string) $values['date_format'] : self::DEFAULTS['date_format'];

		return array_merge(
			$booleans,
			array(
				'log_level'            => in_array( $log_level, self::LOG_LEVELS, true ) ? $log_level : self::DEFAULTS['log_level'],
				'currency'             => array_key_exists( $currency, self::CURRENCIES ) ? $currency : self::DEFAULTS['currency'],
				'date_format'          => in_array( $date_format, self::DATE_FORMATS, true ) ? $date_format : self::DEFAULTS['date_format'],
				'google_books_api_key' => isset( $values['google_books_api_key'] ) ? sanitize_text_field( (string) $values['google_books_api_key'] ) : self::DEFAULTS['google_books_api_key'],
			)
		);
	}

	/**
	 * Persist the in-memory values to the database, sanitized.
	 */
	private function persist(): void {
		update_option( self::OPTION_NAME, $this->sanitize( $this->values ) );
	}
}
