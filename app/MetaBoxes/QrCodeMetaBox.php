<?php
/**
 * The "QR Code" meta box.
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
use SmartBook\Services\QrCodeManager;
use SmartBook\Services\QrCodeServiceProvider;
use WP_Post;

use function sb_option;

/**
 * Side-column meta box showing a book's generated QR code image, a
 * button to force-regenerate it, and a link to its printable label.
 *
 * Generation itself happens automatically (see
 * Services\QrCodeServiceProvider); this box only displays the result
 * and offers a manual override.
 */
final class QrCodeMetaBox implements Hookable {

	/**
	 * Meta box DOM id.
	 */
	private const BOX_ID = 'sb_qr_code';

	/**
	 * Constructor.
	 *
	 * @param QrCodeManager $manager QR code storage/lifecycle manager.
	 */
	public function __construct( private readonly QrCodeManager $manager ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * No-op when the "enable_qr" setting is off (Settings\Settings), so
	 * this meta box simply never appears on the book edit screen.
	 */
	public function register_hooks(): void {
		if ( ! sb_option( 'enable_qr', true ) ) {
			return;
		}

		add_action( 'add_meta_boxes', array( $this, 'add' ) );
	}

	/**
	 * Register the meta box on the book edit screen.
	 */
	public function add(): void {
		add_meta_box(
			self::BOX_ID,
			__( 'QR Code', 'smartbook' ),
			array( $this, 'render' ),
			BookPostType::SLUG,
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post currently being edited.
	 */
	public function render( WP_Post $post ): void {
		$url = $this->manager->url_for( $post->ID );

		echo '<div class="sb-qr-meta-box">';

		if ( '' !== $url ) {
			printf(
				'<img src="%1$s" alt="%2$s" class="sb-qr-meta-box__image" width="150" height="150" />',
				esc_url( $url ),
				esc_attr__( 'QR code linking to this book', 'smartbook' )
			);
		} else {
			printf(
				'<p class="sb-qr-meta-box__empty">%s</p>',
				esc_html__( 'No QR code has been generated yet. Publish or save this book to generate one.', 'smartbook' )
			);
		}

		if ( 'auto-draft' !== $post->post_status ) {
			printf(
				'<p><a class="button button-small" href="%s">%s</a></p>',
				esc_url( $this->regenerate_url( $post->ID ) ),
				esc_html__( 'Regenerate QR Code', 'smartbook' )
			);
		}

		if ( '' !== $url ) {
			printf(
				'<p><a class="button button-small" href="%s">%s</a></p>',
				esc_url( $this->print_label_url( $post->ID ) ),
				esc_html__( 'Print Label', 'smartbook' )
			);
		}

		echo '</div>';
	}

	/**
	 * Nonce-protected admin-post.php URL for the manual regenerate action.
	 *
	 * @param int $post_id Book post ID.
	 */
	private function regenerate_url( int $post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => QrCodeServiceProvider::REGENERATE_ACTION,
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'sb_regenerate_qr_code_' . $post_id
		);
	}

	/**
	 * URL to this book's single-label print sheet.
	 *
	 * @param int $post_id Book post ID.
	 */
	private function print_label_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page'            => 'sb_qr_labels',
				'sb_book_id'      => array( $post_id ),
				'sb_print_labels' => '1',
			),
			admin_url( 'admin.php' )
		);
	}
}
