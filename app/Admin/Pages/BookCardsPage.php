<?php
/**
 * The SmartBook book cards page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use SmartBook\MetaBoxes\BookFields;
use SmartBook\Services\QrCodeManager;
use SmartBook\Taxonomies\AuthorTaxonomy;
use SmartBook\Taxonomies\GenreTaxonomy;
use WP_Post;

/**
 * A richer alternative to the plain QR sticker label (see
 * AbstractLabelsPage): a "library card"-style layout per book -- cover
 * thumbnail, title, authors, genre, ISBN, and format -- next to its QR
 * code, for printing a fuller book-info card instead of a small
 * sticker. Shares the same selection-checklist/print-sheet flow and
 * book lookup as every other label kind; only the card layout itself
 * differs (render_label()).
 */
final class BookCardsPage extends AbstractLabelsPage {

	/**
	 * @param QrCodeManager $qr_codes QR code storage/lifecycle manager.
	 */
	public function __construct( private readonly QrCodeManager $qr_codes ) {
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_slug(): string {
		return 'sb_book_cards';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_title(): string {
		return __( 'Book Cards', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function selection_intro(): string {
		return __( 'Choose which books to print detail cards for.', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function sheet_modifier_class(): string {
		return 'sb-labels-grid--cards';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function images( int $post_id ): array {
		$this->qr_codes->ensure_generated( $post_id );

		return array(
			array(
				'url' => $this->qr_codes->url_for( $post_id ),
				'alt' => __( 'QR code linking to this book', 'smartbook' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * A "library card" layout instead of the default image + title +
	 * shelf stack: cover thumbnail and bibliographic details on the
	 * left, the QR code on the right.
	 */
	protected function render_label( WP_Post $book ): void {
		$qr = $this->images( $book->ID )[0] ?? array(
			'url' => '',
			'alt' => '',
		);

		echo '<div class="sb-book-card">';

		if ( has_post_thumbnail( $book ) ) {
			echo get_the_post_thumbnail( $book, 'thumbnail', array( 'class' => 'sb-book-card__cover' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_post_thumbnail() output is already safe.
		} else {
			echo '<span class="sb-book-card__cover sb-book-card__cover--placeholder" aria-hidden="true">&#128214;</span>';
		}

		echo '<div class="sb-book-card__body">';
		printf( '<span class="sb-book-card__title">%s</span>', esc_html( get_the_title( $book ) ) );

		$authors = $this->term_names( $book->ID, AuthorTaxonomy::SLUG );

		if ( '' !== $authors ) {
			printf( '<span class="sb-book-card__authors">%s</span>', esc_html( $authors ) );
		}

		$this->render_meta_line( __( 'Genre', 'smartbook' ), $this->term_names( $book->ID, GenreTaxonomy::SLUG ) );
		$this->render_meta_line( __( 'ISBN', 'smartbook' ), $this->isbn( $book->ID ) );
		$this->render_meta_line( __( 'Format', 'smartbook' ), $this->format_label( $book->ID ) );
		echo '</div>';

		if ( '' !== $qr['url'] ) {
			printf(
				'<img src="%1$s" alt="%2$s" class="sb-book-card__qr" />',
				esc_url( $qr['url'] ),
				esc_attr( $qr['alt'] )
			);
		}

		echo '</div>';
	}

	/**
	 * Render one "Label: value" line, omitted entirely when the value is empty.
	 */
	private function render_meta_line( string $label, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		printf(
			'<span class="sb-book-card__meta"><strong>%s:</strong> %s</span>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * The book's ISBN-10, '' if unset.
	 */
	private function isbn( int $post_id ): string {
		return (string) get_post_meta( $post_id, 'sb_isbn', true );
	}

	/**
	 * The "sb_format" field's translated option label, '' when unset.
	 */
	private function format_label( int $post_id ): string {
		$value   = (string) get_post_meta( $post_id, 'sb_format', true );
		$options = BookFields::definitions()['sb_format']['options'] ?? array();

		return ( '' !== $value && isset( $options[ $value ] ) ) ? $options[ $value ] : '';
	}
}
