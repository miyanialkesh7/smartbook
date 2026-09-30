<?php
/**
 * The "Book Details" meta box.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\MetaBoxes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Core\Contracts\Hookable;
use SmartBook\PostTypes\BookPostType;
use WP_Post;

/**
 * Renders and persists the "Book Details" meta box on the "sb_book"
 * edit screen: sixteen sb_-prefixed meta fields (defined in BookFields)
 * covering identification, condition/value, reading progress, and
 * free-text notes.
 *
 * Every write goes through a nonce check plus a capability check
 * (can_save()) and per-field sanitization (BookFields::sanitize()); every
 * read goes through esc_attr()/esc_html()/esc_textarea() at the point
 * of output (BookFields::render_field()).
 */
final class BookDetailsMetaBox implements Hookable {

	/**
	 * Meta box DOM id.
	 */
	private const BOX_ID = 'sb_book_details';

	/**
	 * Nonce action name.
	 */
	private const NONCE_ACTION = 'sb_book_details_save';

	/**
	 * Nonce hidden field name.
	 */
	private const NONCE_NAME = 'sb_book_details_nonce';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post_' . BookPostType::SLUG, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box on the book edit screen.
	 */
	public function add(): void {
		add_meta_box(
			self::BOX_ID,
			__( 'Book Details', 'smartbook' ),
			array( $this, 'render' ),
			BookPostType::SLUG,
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box, grouped into sections for readability.
	 *
	 * @param WP_Post $post Post currently being edited.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$fields = BookFields::definitions();

		echo '<div class="sb-meta-box">';

		foreach ( BookFields::visible_sections() as $section ) {
			printf( '<h3 class="sb-meta-box__section-title">%s</h3>', esc_html( $section['title'] ) );
			echo '<div class="sb-meta-box__grid">';

			foreach ( $section['fields'] as $key ) {
				BookFields::render_field( $key, $fields[ $key ], get_post_meta( $post->ID, $key, true ) );
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Persist the submitted meta box values.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object being saved.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! $this->can_save( $post_id ) ) {
			return;
		}

		$fields = BookFields::definitions();

		// Only save fields belonging to a currently-visible section: a
		// disabled section's fields are removed from the form entirely,
		// and a checkbox field sends no value at all when its containing
		// section wasn't even rendered, which save_field()'s "checkbox"
		// branch would otherwise read as "unchecked" and persist as such,
		// silently wiping stored data every time the feature is off.
		foreach ( BookFields::visible_sections() as $section ) {
			foreach ( $section['fields'] as $key ) {
				$this->save_field( $post_id, $key, $fields[ $key ] );
			}
		}
	}

	/**
	 * Whether the current request is allowed to persist meta box values:
	 * a valid nonce, not an autosave, not a revision, and the current
	 * user holds the "edit_post" meta capability for this book (which,
	 * thanks to BookPostType's map_meta_cap, resolves to the custom
	 * "edit_sb_book"/"edit_others_sb_books" capabilities).
	 *
	 * @param int $post_id Post ID being saved.
	 */
	private function can_save( int $post_id ): bool {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Sanitize and persist a single field.
	 *
	 * @param int                  $post_id Post ID being saved.
	 * @param string               $key     Meta key, e.g. "sb_isbn".
	 * @param array<string, mixed> $field   Field definition from BookFields::definitions().
	 */
	private function save_field( int $post_id, string $key, array $field ): void {
		// Checkboxes send no value at all when unchecked, unlike every
		// other input type, so they need their own presence check. The
		// nonce and capability check already happened in can_save() before
		// save() ever calls this method, and BookFields::sanitize()
		// dispatches to the correct sanitizer per field type -- neither is
		// visible to the sniff's static, single-method analysis.
		if ( 'checkbox' === $field['type'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in can_save(), see save().
			update_post_meta( $post_id, $key, BookFields::sanitize( $key, isset( $_POST[ $key ] ) ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in can_save(), see save().
		if ( ! isset( $_POST[ $key ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in can_save(); value is sanitized immediately below via BookFields::sanitize().
		update_post_meta( $post_id, $key, BookFields::sanitize( $key, wp_unslash( $_POST[ $key ] ) ) );
	}
}
