<?php
/**
 * Shared rendering for the "Add New Book" / "Edit Book" custom forms.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use SmartBook\Admin\Support\RedirectsWithNotice;
use SmartBook\Core\Contracts\Hookable;
use SmartBook\MetaBoxes\BookFields;
use SmartBook\Services\Import\BookRowSchema;

use function sb_asset_url;
use function sb_asset_version;

/**
 * AddBookPage and EditBookPage are the same form -- title, status, cover
 * image, gallery, description, every BookFields meta field, and the
 * taxonomy pickers -- differing only in their default field values
 * (blank vs. an existing book's data), what happens on submit (create
 * vs. update), and how each is reached. This base class owns every part
 * that's identical between them; a subclass only supplies the small
 * per-page specifics (slugs/labels/current values) and its own
 * register_hooks()/handle_save(), since *those* genuinely differ
 * (create via BookRowSchema::apply_row() with no target vs. update with
 * one, reached via a post-new.php vs. post.php redirect).
 */
abstract class AbstractBookFormPage implements Hookable {

	use RedirectsWithNotice;

	/**
	 * Admin page slug, e.g. "sb_add_book".
	 */
	abstract protected function page_slug(): string;

	/**
	 * admin-post.php action name for this form's own submission.
	 */
	abstract protected function save_action(): string;

	/**
	 * Nonce action name.
	 */
	abstract protected function nonce_action(): string;

	/**
	 * Nonce hidden field name.
	 */
	abstract protected function nonce_name(): string;

	/**
	 * Name of the client-side draft cookie (see sb-admin.js'
	 * sb_initFormDraft()) that mirrors this form's field values.
	 */
	abstract protected function draft_cookie(): string;

	/**
	 * The <form> element's DOM id.
	 */
	abstract protected function form_id(): string;

	/**
	 * Page heading (<h1> text).
	 */
	abstract protected function heading(): string;

	/**
	 * Submit button text.
	 */
	abstract protected function submit_label(): string;

	/**
	 * Whether the current user may view/submit this form.
	 */
	abstract protected function can_access(): bool;

	/**
	 * Current "title" value; '' for a not-yet-created book.
	 */
	abstract protected function current_title(): string;

	/**
	 * Current "post_status" value.
	 */
	abstract protected function current_status(): string;

	/**
	 * Current "post_content" (description) value.
	 */
	abstract protected function current_content(): string;

	/**
	 * Current featured image attachment id, 0 if none.
	 */
	abstract protected function current_cover_id(): int;

	/**
	 * Current gallery attachment ids, comma-separated, '' if none.
	 */
	abstract protected function current_gallery_ids(): string;

	/**
	 * Current value of one BookFields meta key.
	 */
	abstract protected function current_field_value( string $key ): mixed;

	/**
	 * Currently assigned term names for one taxonomy slug.
	 *
	 * @return string[]
	 */
	abstract protected function current_terms( string $taxonomy ): array;

	/**
	 * {@inheritDoc}
	 */
	protected function notice_page_slug(): string {
		return $this->page_slug();
	}

	/**
	 * Extra hidden fields a concrete subclass needs (e.g. EditBookPage's
	 * "book_id"); none by default.
	 */
	protected function render_extra_hidden_fields(): void {
	}

	/**
	 * Extra content rendered after the field sections, inside the same
	 * meta box shell (e.g. EditBookPage's QR code/barcode panel); none by
	 * default.
	 */
	protected function render_side_panel(): void {
	}

	/**
	 * Extra action link(s) rendered inside the <h1>, WordPress's own
	 * "page-title-action" convention (e.g. EditBookPage's "View
	 * Book"/"Preview" link to the live front-end page); none by default,
	 * since AddBookPage has no front-end page yet to link to.
	 */
	protected function render_header_actions(): void {
	}

	/**
	 * Enqueue the WordPress media library and the shared cover
	 * image/gallery picker script, only on this one screen --
	 * wp_enqueue_media() pulls in a sizeable set of Backbone
	 * views/templates that no other SmartBook page needs.
	 */
	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $this->page_slug() !== $page ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'sb-book-form',
			sb_asset_url( 'js/sb-book-form.js' ),
			array( 'sb-admin', 'media-editor' ),
			sb_asset_version( 'js/sb-book-form.js' ),
			true
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! $this->can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smartbook' ) );
		}

		echo '<div class="wrap sb-admin-page">';
		printf( '<h1>%s', esc_html( $this->heading() ) );
		$this->render_header_actions();
		echo '</h1>';

		$this->render_notice();

		printf(
			'<form method="post" action="%1$s" id="%2$s" class="sb-book-form" data-sb-draft-cookie="%3$s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $this->form_id() ),
			esc_attr( $this->draft_cookie() )
		);
		wp_nonce_field( $this->nonce_action(), $this->nonce_name() );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $this->save_action() ) );
		$this->render_extra_hidden_fields();

		echo '<div class="sb-meta-box">';
		$this->render_primary_section();
		$this->render_taxonomy_section();
		$this->render_field_sections();
		$this->render_side_panel();
		echo '</div>';

		submit_button( $this->submit_label() );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=sb_books' ) ),
			esc_html__( 'Cancel', 'smartbook' )
		);

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Set the book's featured image from the cover picker's attachment
	 * id (sb_cover_image_id), if the field was submitted at all. An
	 * empty/invalid id explicitly clears the featured image rather than
	 * being ignored -- necessary so EditBookPage's "Remove" button
	 * actually removes a previously-set cover; harmless on AddBookPage,
	 * where there is never a previous cover to clear.
	 */
	protected function maybe_attach_cover( int $post_id ): void {
		if ( ! isset( $_POST['sb_cover_image_id'] ) ) {
			return;
		}

		$attachment_id = absint( $_POST['sb_cover_image_id'] );

		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			delete_post_thumbnail( $post_id );
			return;
		}

		set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * Store the gallery picker's attachment ids (sb_gallery_ids, comma-
	 * separated) as the "sb_gallery" post meta -- the same "plain scalar,
	 * comma-separated for a list" convention BookRowSchema already uses
	 * for taxonomy columns. An empty submission clears the meta entirely
	 * (see maybe_attach_cover()'s doc comment for why that matters for
	 * EditBookPage specifically). Not part of BookFields: the gallery is
	 * specific to this form, not (yet) covered by CSV import/export or
	 * the legacy edit-screen meta box.
	 */
	protected function maybe_attach_gallery( int $post_id ): void {
		if ( ! isset( $_POST['sb_gallery_ids'] ) ) {
			return;
		}

		$raw = wp_unslash( $_POST['sb_gallery_ids'] );
		$ids = array_map( 'absint', explode( ',', (string) $raw ) );
		$ids = array_values( array_filter( $ids, 'wp_attachment_is_image' ) );

		if ( array() === $ids ) {
			delete_post_meta( $post_id, 'sb_gallery' );
			return;
		}

		update_post_meta( $post_id, 'sb_gallery', implode( ',', $ids ) );
	}

	/**
	 * Expire the client-side draft cookie now that the book it was
	 * shadowing has actually been saved, so a later fresh visit doesn't
	 * repopulate the form with stale leftovers. Uses the same cookie path
	 * WordPress's own cookies use (localized to JS as sbAdmin.cookiePath
	 * -- see AdminAssetLoader::enqueue()) so this actually reaches the
	 * cookie the browser holds.
	 */
	protected function clear_draft_cookie(): void {
		$path = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';

		setcookie( $this->draft_cookie(), '', time() - HOUR_IN_SECONDS, $path );
	}

	/**
	 * Read every BookFields/taxonomy value out of $_POST into the shape
	 * BookRowSchema::apply_row() expects. Shared by AddBookPage's create
	 * and EditBookPage's update -- both submit the identical field set.
	 *
	 * @return array<string, mixed>
	 */
	protected function collect_posted_row(): array {
		$data = array();

		foreach ( BookFields::definitions() as $key => $field ) {
			if ( 'checkbox' === $field['type'] ) {
				$data[ $key ] = isset( $_POST[ $key ] ) ? '1' : '';
				continue;
			}

			if ( isset( $_POST[ $key ] ) ) {
				$data[ $key ] = wp_unslash( $_POST[ $key ] );
			}
		}

		foreach ( array_keys( BookRowSchema::taxonomy_columns() ) as $column ) {
			$data[ $column ] = isset( $_POST[ $column ] ) ? wp_unslash( $_POST[ $column ] ) : array();
		}

		return $data;
	}

	/**
	 * Render the title/status/cover/gallery/description section: a
	 * "hero" row (cover beside title/status, the two things that most
	 * define a book at a glance) followed by the usual field grid for
	 * description/gallery.
	 */
	private function render_primary_section(): void {
		printf( '<h3 class="sb-meta-box__section-title">%s</h3>', esc_html__( 'Book', 'smartbook' ) );

		echo '<div class="sb-book-form__hero">';

		echo '<div class="sb-book-form__cover">';
		$this->render_media_picker(
			'sb_cover_image_id',
			$this->current_cover_id() > 0 ? (string) $this->current_cover_id() : '',
			__( 'Cover Image', 'smartbook' ),
			__( 'Select Image', 'smartbook' ),
			'single'
		);
		echo '</div>';

		echo '<div class="sb-book-form__hero-fields">';

		echo '<div class="sb-field-group sb-field-group--full">';
		printf( '<label for="sb-book-form-title">%s</label>', esc_html__( 'Title', 'smartbook' ) );
		printf(
			'<input type="text" id="sb-book-form-title" name="post_title" class="widefat sb-book-form__title-input" value="%s" required="required" />',
			esc_attr( $this->current_title() )
		);
		echo '</div>';

		echo '<div class="sb-field-group">';
		printf( '<label for="sb-book-form-status">%s</label>', esc_html__( 'Status', 'smartbook' ) );
		echo '<select id="sb-book-form-status" name="post_status" class="regular-text">';

		foreach ( $this->status_options() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $this->current_status(), $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '</div>';

		echo '</div>'; // .sb-book-form__hero-fields
		echo '</div>'; // .sb-book-form__hero

		echo '<div class="sb-meta-box__grid">';

		echo '<div class="sb-field-group sb-field-group--full">';
		printf( '<label for="sb-book-form-content">%s</label>', esc_html__( 'Description', 'smartbook' ) );
		printf(
			'<textarea id="sb-book-form-content" name="post_content" class="widefat" rows="4">%s</textarea>',
			esc_textarea( $this->current_content() )
		);
		echo '</div>';

		$this->render_media_picker(
			'sb_gallery_ids',
			$this->current_gallery_ids(),
			__( 'Gallery', 'smartbook' ),
			__( 'Add Images', 'smartbook' ),
			'multiple'
		);

		echo '</div>';
	}

	/**
	 * Render a WP media library picker: a hidden input holding the
	 * selected attachment id(s) (comma-separated for "multiple"), a
	 * thumbnail preview, and a button that opens the wp.media frame (see
	 * sb-book-form.js' sb_initMediaPickers()). No file ever passes
	 * through this form directly -- only ids of attachments already in
	 * the media library -- so save handling just needs to read and
	 * validate them (maybe_attach_cover()/maybe_attach_gallery()).
	 *
	 * @param string $field_name  Hidden input name, e.g. "sb_cover_image_id".
	 * @param string $value       Current value: an id, comma-separated ids, or ''.
	 * @param string $label       Translated field label.
	 * @param string $button_text Translated "select"/"add" button text.
	 * @param string $mode        "single" or "multiple".
	 */
	private function render_media_picker( string $field_name, string $value, string $label, string $button_text, string $mode ): void {
		printf(
			'<div class="sb-field-group%s">',
			'multiple' === $mode ? ' sb-field-group--full' : ''
		);
		printf( '<span class="sb-field-group__label">%s</span>', esc_html( $label ) );

		printf(
			'<div class="sb-media-picker" data-sb-media-picker data-sb-media-mode="%s">',
			esc_attr( $mode )
		);
		printf(
			'<input type="hidden" class="sb-media-picker__value" name="%1$s" value="%2$s" />',
			esc_attr( $field_name ),
			esc_attr( $value )
		);
		echo '<div class="sb-media-picker__preview"></div>';
		echo '<p>';
		printf( '<button type="button" class="button sb-media-picker__select">%s</button> ', esc_html( $button_text ) );

		if ( 'single' === $mode ) {
			printf(
				'<button type="button" class="button-link sb-media-picker__remove%s">%s</button>',
				'' === $value ? ' sb-hidden' : '',
				esc_html__( 'Remove', 'smartbook' )
			);
		}

		echo '</p>';
		echo '</div>';

		if ( 'multiple' === $mode ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Optional. Additional photos for this book.', 'smartbook' ) );
		} else {
			printf( '<p class="description">%s</p>', esc_html__( 'Optional.', 'smartbook' ) );
		}

		echo '</div>';
	}

	/**
	 * Render the taxonomy section: one picker per taxonomy, each a
	 * checkbox per existing term (pre-checked against current_terms()),
	 * a search box to filter that list client-side when it has any terms
	 * (see sb-admin.js' sb_initTaxonomyPickers()), and a small "+ Add
	 * new" control that appends a fresh checked checkbox client-side for
	 * a name typed on the spot. Either way, what reaches the server is
	 * the same "array of term names" shape BookRowSchema/the CSV import
	 * format already expect, so a name that doesn't match an existing
	 * term is created automatically by BookRowSchema::apply_row() on
	 * save -- nothing new to persist here.
	 */
	private function render_taxonomy_section(): void {
		printf( '<h3 class="sb-meta-box__section-title">%s</h3>', esc_html__( 'Authors & Categorization', 'smartbook' ) );
		echo '<div class="sb-meta-box__grid">';

		$taxonomies = BookRowSchema::taxonomy_columns();

		foreach ( $this->taxonomy_labels() as $column => $label ) {
			$this->render_taxonomy_field( $column, $label, $taxonomies[ $column ] );
		}

		echo '</div>';
	}

	/**
	 * Render a single taxonomy's picker.
	 *
	 * @param string $column   Column name, e.g. "authors" (BookRowSchema::taxonomy_columns() key).
	 * @param string $label    Translated field label.
	 * @param string $taxonomy Taxonomy slug (BookRowSchema::taxonomy_columns() value).
	 */
	private function render_taxonomy_field( string $column, string $label, string $taxonomy ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$terms   = is_array( $terms ) ? $terms : array();
		$checked = $this->current_terms( $taxonomy );

		echo '<div class="sb-field-group sb-taxonomy-picker" data-sb-taxonomy-picker>';
		printf( '<span class="sb-field-group__label">%s</span>', esc_html( $label ) );

		if ( array() !== $terms ) {
			echo '<div class="sb-taxonomy-picker__search-wrap">';
			printf(
				'<input type="text" class="sb-taxonomy-picker__search" placeholder="%1$s" aria-label="%2$s" />',
				esc_attr__( 'Search…', 'smartbook' ),
				esc_attr(
					sprintf(
						/* translators: %s: taxonomy field label, e.g. "Genres". */
						__( 'Search %s', 'smartbook' ),
						$label
					)
				)
			);
			printf(
				'<button type="button" class="sb-taxonomy-picker__search-clear sb-hidden" aria-label="%s">&times;</button>',
				esc_attr__( 'Clear search', 'smartbook' )
			);
			echo '</div>';
		}

		echo '<ul class="sb-taxonomy-picker__list">';

		foreach ( $terms as $term ) {
			printf(
				'<li><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label></li>',
				esc_attr( $column ),
				esc_attr( $term->name ),
				checked( in_array( $term->name, $checked, true ), true, false ),
				esc_html( $term->name )
			);
		}

		echo '</ul>';

		if ( array() === $terms ) {
			printf( '<p class="sb-taxonomy-picker__empty description">%s</p>', esc_html__( 'No existing terms yet -- add one below.', 'smartbook' ) );
		}

		printf(
			'<button type="button" class="button-link sb-taxonomy-picker__toggle">%s</button>',
			esc_html__( '+ Add new', 'smartbook' )
		);

		echo '<div class="sb-taxonomy-picker__add sb-hidden">';
		printf(
			'<input type="text" class="regular-text sb-taxonomy-picker__input" data-sb-taxonomy-field="%1$s" placeholder="%2$s" />',
			esc_attr( $column ),
			esc_attr__( 'New term name', 'smartbook' )
		);
		printf( '<button type="button" class="button sb-taxonomy-picker__add-button">%s</button>', esc_html__( 'Add', 'smartbook' ) );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render every BookFields section (Identification, Condition & Value,
	 * Reading Progress, ...), pre-filled via current_field_value().
	 */
	private function render_field_sections(): void {
		$fields = BookFields::definitions();

		foreach ( BookFields::visible_sections() as $section ) {
			printf( '<h3 class="sb-meta-box__section-title">%s</h3>', esc_html( $section['title'] ) );
			echo '<div class="sb-meta-box__grid">';

			foreach ( $section['fields'] as $key ) {
				BookFields::render_field( $key, $fields[ $key ], $this->current_field_value( $key ) );
			}

			echo '</div>';
		}
	}

	/**
	 * Post status choices offered by this form.
	 *
	 * @return array<string, string>
	 */
	protected function status_options(): array {
		return array(
			'publish' => __( 'Published', 'smartbook' ),
			'draft'   => __( 'Draft', 'smartbook' ),
			'pending' => __( 'Pending Review', 'smartbook' ),
			'private' => __( 'Private', 'smartbook' ),
		);
	}

	/**
	 * Taxonomy column => translated label, in render order. Column names
	 * match BookRowSchema::taxonomy_columns() exactly.
	 *
	 * @return array<string, string>
	 */
	private function taxonomy_labels(): array {
		return array(
			'authors'     => __( 'Authors', 'smartbook' ),
			'genres'      => __( 'Genres', 'smartbook' ),
			'publishers'  => __( 'Publishers', 'smartbook' ),
			'languages'   => __( 'Languages', 'smartbook' ),
			'collections' => __( 'Collections', 'smartbook' ),
			'series'      => __( 'Series', 'smartbook' ),
			'shelves'     => __( 'Shelves', 'smartbook' ),
		);
	}
}
