<?php
/**
 * The SmartBook "All Labels" page (QR code + barcode combined).
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use SmartBook\Services\BarcodeManager;
use SmartBook\Services\QrCodeManager;

/**
 * Combined-label configuration for the shared label-printing flow (see
 * AbstractLabelsPage): each printed label carries both the QR code and
 * the barcode (plus its human-readable value) for a book, instead of
 * needing two separate print runs for the two label kinds. Reachable
 * from the books table's "Print All Labels" bulk action and per-row
 * action. Only registered (AdminMenu) when both "enable_qr" and
 * "enable_barcode" are on -- with just one of the two enabled, this
 * would only ever duplicate that single-type page.
 */
final class AllLabelsPage extends AbstractLabelsPage {

	/**
	 * @param QrCodeManager  $qr_codes QR code storage/lifecycle manager.
	 * @param BarcodeManager $barcodes Barcode storage/lifecycle manager.
	 */
	public function __construct(
		private readonly QrCodeManager $qr_codes,
		private readonly BarcodeManager $barcodes
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_slug(): string {
		return 'sb_all_labels';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_title(): string {
		return __( 'All Labels', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function selection_intro(): string {
		return __( 'Choose which books to print QR code + barcode labels for.', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function images( int $post_id ): array {
		$this->qr_codes->ensure_generated( $post_id );
		$this->barcodes->ensure_generated( $post_id );

		return array(
			array(
				'url' => $this->qr_codes->url_for( $post_id ),
				'alt' => __( 'QR code linking to this book', 'smartbook' ),
			),
			array(
				'url' => $this->barcodes->url_for( $post_id ),
				'alt' => __( 'Barcode for this book', 'smartbook' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Same as BarcodeLabelsPage: also print the human-readable barcode
	 * value under the images.
	 */
	protected function extra_label_content( int $post_id ): string {
		$value = $this->barcodes->value_for( $post_id );

		if ( '' === $value ) {
			return '';
		}

		return sprintf( '<code class="sb-label__code">%s</code>', esc_html( $value ) );
	}
}
