<?php
/**
 * The SmartBook labels hub page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

use SmartBook\PostTypes\BookPostType;

use function sb_option;

/**
 * The visible "Labels" sidebar entry: a landing page linking to
 * whichever label-print flows are enabled (QrLabelsPage,
 * BarcodeLabelsPage, AllLabelsPage, BookCardsPage) -- each still its own
 * hidden page (see AdminMenu), reached from here instead of the Books
 * list table's former row/bulk print-label actions, which this page
 * replaces as the one place to go print labels from.
 */
final class LabelsPage {

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smartbook' ) );
		}

		echo '<div class="wrap sb-admin-page">';
		printf( '<h1>%s</h1>', esc_html__( 'Labels', 'smartbook' ) );
		printf( '<p>%s</p>', esc_html__( 'Print QR code and/or barcode labels, or fuller book detail cards, for your books.', 'smartbook' ) );

		echo '<div class="sb-panel">';
		echo '<div class="sb-quick-links">';

		if ( sb_option( 'enable_qr', true ) ) {
			$this->render_link( __( 'QR Labels', 'smartbook' ), admin_url( 'admin.php?page=sb_qr_labels' ), 'camera' );
		}

		if ( sb_option( 'enable_barcode', true ) ) {
			$this->render_link( __( 'Barcode Labels', 'smartbook' ), admin_url( 'admin.php?page=sb_barcode_labels' ), 'align-center' );
		}

		if ( sb_option( 'enable_qr', true ) && sb_option( 'enable_barcode', true ) ) {
			$this->render_link( __( 'All Labels', 'smartbook' ), admin_url( 'admin.php?page=sb_all_labels' ), 'index-card' );
		}

		if ( sb_option( 'enable_qr', true ) ) {
			$this->render_link( __( 'Book Cards', 'smartbook' ), admin_url( 'admin.php?page=sb_book_cards' ), 'id-alt' );
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render a single link card, matching DashboardPage's "Quick Links"
	 * style (same classes, so it picks up the same CSS with no additions).
	 */
	private function render_link( string $label, string $url, string $icon ): void {
		printf(
			'<a class="sb-quick-links__item" href="%1$s"><span class="sb-quick-links__icon dashicons dashicons-%2$s" aria-hidden="true"></span><span class="sb-quick-links__label">%3$s</span><span class="sb-quick-links__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>',
			esc_url( $url ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}
}
