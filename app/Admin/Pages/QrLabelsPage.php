<?php
/**
 * The SmartBook QR labels page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Services\QrCodeManager;

/**
 * QR-specific configuration for the shared label-printing flow (see
 * AbstractLabelsPage). Reachable from the books table's "Print QR
 * Labels" bulk action, its per-row "Print Label" action, and the QR
 * Code meta box's "Print Label" link — all three simply link here with
 * the relevant "sb_book_id[]" values.
 */
final class QrLabelsPage extends AbstractLabelsPage {

	/**
	 * Constructor.
	 *
	 * @param QrCodeManager $qr_codes QR code storage/lifecycle manager.
	 */
	public function __construct( private readonly QrCodeManager $qr_codes ) {
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_slug(): string {
		return 'sb_qr_labels';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function page_title(): string {
		return __( 'QR Labels', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function selection_intro(): string {
		return __( 'Choose which books to print QR labels for.', 'smartbook' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Book post ID.
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
}
