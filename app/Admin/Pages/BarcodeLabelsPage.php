<?php
/**
 * The SmartBook barcode labels page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Services\BarcodeManager;

/**
 * Barcode-specific configuration for the shared label-printing flow
 * (see AbstractLabelsPage). Reachable from the books table's "Print
 * Barcode Labels" bulk action, its per-row "Print Label" action, and
 * the Barcode meta box's "Print Label" link.
 */
final class BarcodeLabelsPage extends AbstractLabelsPage {

	/**
	 * Constructor.
	 *
	 * @param BarcodeManager $barcodes Barcode storage/lifecycle manager.
	 */
	public function __construct( private readonly BarcodeManager $barcodes ) {
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_slug(): string {
		return 'sb_barcode_labels';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_title(): string {
		return __( 'Barcode Labels', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function selection_intro(): string {
		return __( 'Choose which books to print barcode labels for.', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Book post ID.
	 */
	protected function images( int $post_id ): array {
		$this->barcodes->ensure_generated( $post_id );

		return array(
			array(
				'url' => $this->barcodes->url_for( $post_id ),
				'alt' => __( 'Barcode for this book', 'smartbook' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Barcode labels also print the human-readable value under the
	 * bars, standard practice for physical barcode labels.
	 *
	 * @param int $post_id Book post ID.
	 */
	protected function extra_label_content( int $post_id ): string {
		$value = $this->barcodes->value_for( $post_id );

		if ( '' === $value ) {
			return '';
		}

		return sprintf( '<code class="sb-label__code">%s</code>', esc_html( $value ) );
	}
}
