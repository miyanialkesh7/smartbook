<?php
/**
 * Canonical schema for the book's custom meta fields.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\MetaBoxes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateTimeImmutable;
use WP_User;

use function sb_option;

/**
 * Single source of truth for the sixteen "sb_"-prefixed book meta
 * fields: their labels, input types, sanitization rules, section
 * grouping, and input markup. Consumed by BookDetailsMetaBox (edit-screen
 * UI), Admin\Pages\AddBookPage (the custom "Add New Book" form), and
 * Admin\Pages\ImportExportPage (CSV import/export) so all three stay in
 * lock-step with the same field set instead of maintaining divergent
 * copies.
 */
final class BookFields {

	/**
	 * Definition of every meta field: label, input type, and (where
	 * relevant) numeric bounds/type, select options, or a help description.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			'sb_isbn'          => array(
				'label'       => __( 'ISBN-10', 'smartbook' ),
				'type'        => 'text',
				'description' => __( '10-character International Standard Book Number.', 'smartbook' ),
			),
			'sb_barcode'       => array(
				'label'       => __( 'Barcode', 'smartbook' ),
				'type'        => 'text',
				'description' => __( 'Value encoded in this book\'s Code128 barcode. Leave blank to auto-assign one.', 'smartbook' ),
			),
			'sb_pages'         => array(
				'label'        => __( 'Pages', 'smartbook' ),
				'type'         => 'number',
				'numeric_type' => 'int',
				'min'          => 0,
				'step'         => 1,
			),
			'sb_edition'       => array(
				'label' => __( 'Edition', 'smartbook' ),
				'type'  => 'text',
			),
			'sb_language'      => array(
				'label' => __( 'Language', 'smartbook' ),
				'type'  => 'text',
			),
			'sb_format'        => array(
				'label'   => __( 'Format', 'smartbook' ),
				'type'    => 'select',
				'options' => array(
					''          => __( '— Select —', 'smartbook' ),
					'hardcover' => __( 'Hardcover', 'smartbook' ),
					'paperback' => __( 'Paperback', 'smartbook' ),
					'ebook'     => __( 'eBook', 'smartbook' ),
					'audiobook' => __( 'Audiobook', 'smartbook' ),
				),
			),
			'sb_condition'     => array(
				'label'   => __( 'Condition', 'smartbook' ),
				'type'    => 'select',
				'options' => array(
					''         => __( '— Select —', 'smartbook' ),
					'new'      => __( 'New', 'smartbook' ),
					'like_new' => __( 'Like New', 'smartbook' ),
					'good'     => __( 'Good', 'smartbook' ),
					'fair'     => __( 'Fair', 'smartbook' ),
					'poor'     => __( 'Poor', 'smartbook' ),
				),
			),
			'sb_price'         => array(
				'label'        => __( 'Price', 'smartbook' ),
				'type'         => 'number',
				'numeric_type' => 'float',
				'min'          => 0,
				'step'         => 0.01,
			),
			'sb_purchase_date' => array(
				'label' => __( 'Purchase Date', 'smartbook' ),
				'type'  => 'date',
			),
			'sb_status'        => array(
				'label'   => __( 'Reading Status', 'smartbook' ),
				'type'    => 'select',
				'options' => array(
					'unread'    => __( 'Unread', 'smartbook' ),
					'reading'   => __( 'Reading', 'smartbook' ),
					'read'      => __( 'Read', 'smartbook' ),
					'on_hold'   => __( 'On Hold', 'smartbook' ),
					'abandoned' => __( 'Abandoned', 'smartbook' ),
				),
			),
			'sb_progress'      => array(
				'label'        => __( 'Progress (%)', 'smartbook' ),
				'type'         => 'number',
				'numeric_type' => 'int',
				'min'          => 0,
				'max'          => 100,
				'step'         => 1,
			),
			'sb_notes'         => array(
				'label' => __( 'Notes', 'smartbook' ),
				'type'  => 'textarea',
			),
			'sb_summary'       => array(
				'label' => __( 'Summary', 'smartbook' ),
				'type'  => 'textarea',
			),
			'sb_favorite'      => array(
				'label' => __( 'Favorite', 'smartbook' ),
				'type'  => 'checkbox',
			),
			'sb_wishlist'      => array(
				'label' => __( 'On Wishlist', 'smartbook' ),
				'type'  => 'checkbox',
			),
			'sb_borrowed'      => array(
				'label'       => __( 'Borrowed', 'smartbook' ),
				'type'        => 'checkbox',
				'description' => __( 'This copy is on loan (borrowed from or lent to someone else).', 'smartbook' ),
			),
			'sb_borrowed_to'   => array(
				'label'       => __( 'Borrowed To', 'smartbook' ),
				'type'        => 'user_select',
				'description' => __( 'Pick from this site\'s registered users.', 'smartbook' ),
			),
			'sb_borrow_date'   => array(
				'label' => __( 'Borrow Date', 'smartbook' ),
				'type'  => 'date',
			),
			'sb_return_date'   => array(
				'label'       => __( 'Return Date', 'smartbook' ),
				'type'        => 'date',
				'description' => __( 'Expected/due date. Shown on the dashboard as overdue once this date passes.', 'smartbook' ),
			),
			'sb_reminder'      => array(
				'label'       => __( 'Reminder Date', 'smartbook' ),
				'type'        => 'date',
				'description' => __( 'Shown on the dashboard as a reminder starting this date.', 'smartbook' ),
			),
			'sb_returned'      => array(
				'label' => __( 'Returned', 'smartbook' ),
				'type'  => 'checkbox',
			),
			'sb_lost'          => array(
				'label' => __( 'Lost', 'smartbook' ),
				'type'  => 'checkbox',
			),
		);
	}

	/**
	 * Sanitize a raw value for a given field key according to its
	 * declared type. Unknown keys fall back to plain text sanitization.
	 *
	 * @param string $key Meta key, e.g. "sb_isbn".
	 * @param mixed  $raw Raw value (already unslashed by the caller).
	 */
	public static function sanitize( string $key, mixed $raw ): string|int|float {
		$field = self::definitions()[ $key ] ?? array( 'type' => 'text' );

		return match ( $field['type'] ) {
			'textarea' => sanitize_textarea_field( (string) $raw ),
			'select'   => self::sanitize_choice( (string) $raw, $field['options'] ?? array() ),
			'number'   => self::sanitize_number( $raw, $field ),
			'date'     => self::sanitize_date( (string) $raw ),
			'checkbox' => self::sanitize_checkbox( $raw ),
			default    => sanitize_text_field( (string) $raw ),
		};
	}

	/**
	 * Keep only a value that matches one of a select field's known
	 * option keys; anything else is discarded.
	 *
	 * @param string                $value   Value to validate.
	 * @param array<string, string> $options Allowed option value => label pairs.
	 */
	private static function sanitize_choice( string $value, array $options ): string {
		return array_key_exists( $value, $options ) ? $value : '';
	}

	/**
	 * Coerce and clamp a numeric field to its declared type and bounds.
	 *
	 * @param mixed                $raw   Raw value.
	 * @param array<string, mixed> $field Field definition, may contain "numeric_type", "min", "max".
	 */
	private static function sanitize_number( mixed $raw, array $field ): int|float {
		$value = 'float' === ( $field['numeric_type'] ?? 'int' ) ? (float) $raw : (int) $raw;

		if ( isset( $field['min'] ) ) {
			$value = max( $field['min'], $value );
		}

		if ( isset( $field['max'] ) ) {
			$value = min( $field['max'], $value );
		}

		return $value;
	}

	/**
	 * Keep only a strict "Y-m-d" date string; anything else is discarded
	 * rather than stored malformed.
	 *
	 * @param string $raw Raw value.
	 */
	private static function sanitize_date( string $raw ): string {
		$raw = sanitize_text_field( $raw );

		if ( '' === $raw ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d', $raw );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $raw ) {
			return '';
		}

		return $raw;
	}

	/**
	 * Normalize any truthy representation ("1", "true", "yes", "on", or
	 * an actual boolean) to "1", everything else to an empty string.
	 *
	 * @param mixed $raw Raw value.
	 */
	private static function sanitize_checkbox( mixed $raw ): string {
		if ( is_bool( $raw ) ) {
			return $raw ? '1' : '';
		}

		$normalized = strtolower( trim( (string) $raw ) );

		return in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '';
	}

	/**
	 * Field keys grouped into display sections, in render order. A
	 * section with a non-null "gate" is only rendered/saved when the
	 * named Settings\Settings boolean is true -- see visible_sections().
	 * Shared by BookDetailsMetaBox (the edit-screen meta box) and
	 * Admin\Pages\AddBookPage (the custom "Add New Book" form), so both
	 * present the exact same grouping.
	 *
	 * @return array<string, array{title: string, fields: string[], gate: ?string}>
	 */
	public static function sections(): array {
		return array(
			'identification'  => array(
				'title'  => __( 'Identification', 'smartbook' ),
				'fields' => array( 'sb_isbn', 'sb_barcode', 'sb_pages', 'sb_edition', 'sb_language', 'sb_format' ),
				'gate'   => null,
			),
			'condition'       => array(
				'title'  => __( 'Condition & Value', 'smartbook' ),
				'fields' => array( 'sb_condition', 'sb_price', 'sb_purchase_date' ),
				'gate'   => null,
			),
			'reading_tracker' => array(
				'title'  => __( 'Reading Progress', 'smartbook' ),
				'fields' => array( 'sb_status', 'sb_progress' ),
				'gate'   => 'enable_reading_tracker',
			),
			'rating'          => array(
				'title'  => __( 'Lists', 'smartbook' ),
				'fields' => array( 'sb_favorite', 'sb_wishlist' ),
				'gate'   => null,
			),
			'borrow'          => array(
				'title'  => __( 'Borrow Management', 'smartbook' ),
				'fields' => array( 'sb_borrowed', 'sb_borrowed_to', 'sb_borrow_date', 'sb_return_date', 'sb_reminder', 'sb_returned', 'sb_lost' ),
				'gate'   => 'enable_borrow',
			),
			'notes'           => array(
				'title'  => __( 'Notes', 'smartbook' ),
				'fields' => array( 'sb_notes', 'sb_summary' ),
				'gate'   => null,
			),
		);
	}

	/**
	 * sections(), minus any section whose "gate" setting is currently off.
	 *
	 * @return array<string, array{title: string, fields: string[], gate: ?string}>
	 */
	public static function visible_sections(): array {
		return array_filter(
			self::sections(),
			static fn ( array $section ): bool => null === $section['gate'] || sb_option( $section['gate'], true )
		);
	}

	/**
	 * Render a single field's label plus its typed input control, escaping
	 * every dynamic value at the point of output.
	 *
	 * @param string               $key   Meta key, e.g. "sb_isbn".
	 * @param array<string, mixed> $field Field definition from definitions().
	 * @param mixed                $value Current value (empty string for a not-yet-created book).
	 */
	public static function render_field( string $key, array $field, mixed $value ): void {
		$id = esc_attr( $key );

		if ( 'checkbox' === $field['type'] ) {
			printf(
				'<div class="sb-field-group sb-field-group--checkbox"><label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label></div>',
				esc_attr( $id ),
				checked( '1', $value, false ),
				esc_html( $field['label'] )
			);

			return;
		}

		printf(
			'<div class="sb-field-group%s">',
			'textarea' === $field['type'] ? ' sb-field-group--full' : ''
		);

		printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $field['label'] ) );

		switch ( $field['type'] ) {
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%1$s" class="widefat" rows="4">%2$s</textarea>',
					esc_attr( $id ),
					esc_textarea( (string) $value )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%1$s" class="regular-text">', esc_attr( $id ) );

				foreach ( $field['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( $value, $option_value, false ),
						esc_html( $option_label )
					);
				}

				echo '</select>';
				break;

			case 'user_select':
				// Progressively enhanced into a searchable, scrollable
				// combobox by sb-admin.js' sb_initUserSelects() -- the
				// <select> itself stays in the DOM (hidden) and is what
				// actually submits with the form.
				echo '<div class="sb-user-select" data-sb-user-select>';
				printf( '<select id="%1$s" name="%1$s" class="sb-user-select__native">', esc_attr( $id ) );
				printf( '<option value="">%s</option>', esc_html__( '— Select —', 'smartbook' ) );

				foreach ( self::user_options() as $option_value => $option_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( $value, $option_value, false ),
						esc_html( $option_label )
					);
				}

				echo '</select>';
				echo '</div>';
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="regular-text" %3$s />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					self::numeric_attributes( $field )
				);
				break;

			case 'date':
				printf(
					'<input type="date" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}

		echo '</div>';
	}

	/**
	 * Every registered user, alphabetical by display name, for the
	 * "Borrowed To" picker (sb_borrowed_to) -- except the user currently
	 * filling out this form, who can't lend a book to themselves. Keyed
	 * by user id (the option value), not display name: unlike a name,
	 * an id can't drift out of sync when someone changes their display
	 * name later, and Admin\Pages\BorrowRequestController/
	 * BorrowedBooksPage's "approve request" action already stores the
	 * requester's id here. See borrowed_to_display() for turning a
	 * stored id (or a legacy/free-text name -- the book scan page's own
	 * "Borrow" quick action still accepts a plain typed name, since it's
	 * meant to also cover lending to someone with no site account) back
	 * into something displayable.
	 *
	 * @return array<string, string>
	 */
	private static function user_options(): array {
		$users = get_users(
			array(
				'fields'  => array( 'ID', 'display_name' ),
				'exclude' => array( get_current_user_id() ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$options = array();

		foreach ( $users as $user ) {
			$options[ (string) $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/**
	 * Turn a stored "sb_borrowed_to" value into something displayable:
	 * a purely-numeric value is a user id (set by an approved borrow
	 * request), resolved to that user's current display name --
	 * "(deleted user)" if the account is gone -- so a later name change
	 * never leaves this showing a stale name. Anything else is a
	 * free-text name (the book scan page's "Borrow" quick action, or a
	 * CSV import), shown as-is.
	 */
	public static function borrowed_to_display( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}

		if ( ! ctype_digit( $raw ) ) {
			return $raw;
		}

		$user = get_userdata( (int) $raw );

		return $user instanceof WP_User ? $user->display_name : __( '(deleted user)', 'smartbook' );
	}

	/**
	 * Build an already-escaped "min=... max=... step=..." attribute
	 * fragment for a number input, omitting any bound the field doesn't declare.
	 *
	 * @param array<string, mixed> $field Field definition, may contain "min", "max", "step".
	 */
	private static function numeric_attributes( array $field ): string {
		$attributes = array();

		foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
			if ( isset( $field[ $attribute ] ) ) {
				$attributes[] = sprintf( '%s="%s"', $attribute, esc_attr( (string) $field[ $attribute ] ) );
			}
		}

		return implode( ' ', $attributes );
	}
}
