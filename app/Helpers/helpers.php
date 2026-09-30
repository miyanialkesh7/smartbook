<?php
/**
 * Global helper functions.
 *
 * Deliberately declared in the global namespace (not under SmartBook\...)
 * so themes, templates, and other plugins can call them without an
 * import, the same way WordPress's own sb_-agnostic core functions work.
 * Every function is prefixed sb_ to avoid collisions and guarded with
 * function_exists() so this file can be safely required more than once.
 *
 * @package SmartBook
 */

declare(strict_types=1);

use SmartBook\Core\Contracts\ContainerInterface;
use SmartBook\Core\Plugin;
use SmartBook\Services\LoggerInterface;
use SmartBook\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sb_plugin' ) ) {
	/**
	 * The SmartBook plugin singleton.
	 */
	function sb_plugin(): Plugin {
		return Plugin::instance();
	}
}

if ( ! function_exists( 'sb_container' ) ) {
	/**
	 * The SmartBook application service container.
	 */
	function sb_container(): ContainerInterface {
		return sb_plugin()->container();
	}
}

if ( ! function_exists( 'sb_logger' ) ) {
	/**
	 * The SmartBook logger.
	 */
	function sb_logger(): LoggerInterface {
		return sb_container()->make( LoggerInterface::class );
	}
}

if ( ! function_exists( 'sb_option' ) ) {
	/**
	 * Read a single SmartBook setting, falling back to $fallback_value when unset.
	 *
	 * @param string $key            Setting key.
	 * @param mixed  $fallback_value Value returned when the key has not been set.
	 */
	function sb_option( string $key, mixed $fallback_value = null ): mixed {
		/**
		 * Settings repository.
		 *
		 * @var Settings $settings
		 */
		$settings = sb_container()->make( Settings::class );

		return $settings->get( $key, $fallback_value );
	}
}

if ( ! function_exists( 'sb_asset_url' ) ) {
	/**
	 * Build a public URL for a file under this plugin's assets/ directory.
	 *
	 * @param string $relative_path Path relative to the assets/ directory, e.g. "css/sb-admin.css".
	 */
	function sb_asset_url( string $relative_path ): string {
		return SB_URL . 'assets/' . ltrim( $relative_path, '/' );
	}
}

if ( ! function_exists( 'sb_asset_version' ) ) {
	/**
	 * Cache-busting version string for a file under this plugin's
	 * assets/ directory: the file's own modification time when it can be
	 * read, falling back to the plugin version otherwise.
	 *
	 * @param string $relative_path Path relative to the assets/ directory, e.g. "css/sb-admin.css".
	 */
	function sb_asset_version( string $relative_path ): string {
		$path  = SB_PATH . 'assets/' . ltrim( $relative_path, '/' );
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;

		return false !== $mtime ? (string) $mtime : SB_VERSION;
	}
}

if ( ! function_exists( 'sb_format_currency' ) ) {
	/**
	 * Format an amount using the site's configured "currency" setting
	 * (Settings::CURRENCIES), e.g. sb_format_currency( 12.5 ) => "$12.50".
	 */
	function sb_format_currency( float $amount ): string {
		$code = (string) sb_option( 'currency', 'USD' );

		return Settings::currency_symbol( $code ) . number_format( $amount, 2 );
	}
}

if ( ! function_exists( 'sb_format_date' ) ) {
	/**
	 * Format a stored "Y-m-d" date string using the site's configured
	 * "date_format" setting (Settings::DATE_FORMATS), or an explicit
	 * override. Returns an empty string unchanged and an unparseable
	 * value as-is, rather than showing a misleading "1970" date.
	 */
	function sb_format_date( string $date, string $format = '' ): string {
		if ( '' === $date ) {
			return '';
		}

		$timestamp = strtotime( $date );

		if ( false === $timestamp ) {
			return $date;
		}

		return date_i18n( '' !== $format ? $format : (string) sb_option( 'date_format', 'Y-m-d' ), $timestamp );
	}
}
