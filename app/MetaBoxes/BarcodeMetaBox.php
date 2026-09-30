<?php
/**
 * The "Barcode" meta box.
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
use SmartBook\Services\BarcodeManager;
use SmartBook\Services\BarcodeServiceProvider;
use WP_Post;

use function sb_option;

/**
 * Side-column meta box showing a book's generated Code128 barcode
 * image and value, a button to force-regenerate it, and a link to its
 * printable label.
 *
 * Generation itself happens automatically (see
 * Services\BarcodeServiceProvider); this box only displays the result
 * and offers a manual override. The barcode *value* is edited via the
 * "Barcode" field in BookDetailsMetaBox (it is a regular BookFields
 * field); this box is read-only display plus the regenerate-image action.
 */
final class BarcodeMetaBox implements Hookable {

	/**
	 * Meta box DOM id.
	 */
	private const BOX_ID = 'sb_barcode';

	/**
	 * Constructor.
	 *
	 * @param BarcodeManager $manager Barcode storage/lifecycle manager.
	 */
	public function __construct( private readonly BarcodeManager $manager ) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * No-op when the "enable_barcode" setting is off (Settings\Settings),
	 * so this meta box simply never appears on the book edit screen.
	 */
	public function register_hooks(): void {
		if ( ! sb_option( 'enable_barcode', true ) ) {
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
			__( 'Barcode', 'smartbook' ),
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
		$has_barcode = $this->manager->has_barcode( $post->ID );

		echo '<div class="sb-barcode-meta-box">';

		if ( $has_barcode ) {
			printf(
				'<img src="%1$s" alt="%2$s" class="sb-barcode-meta-box__image" />',
				esc_url( $this->manager->url_for( $post->ID ) ),
				esc_attr__( 'Barcode linking to this book', 'smartbook' )
			);
			printf(
				'<p class="sb-barcode-meta-box__value"><code>%s</code></p>',
				esc_html( $this->manager->value_for( $post->ID ) )
			);
		} else {
			printf(
				'<p class="sb-barcode-meta-box__empty">%s</p>',
				esc_html__( 'No barcode has been generated yet. Publish or save this book to generate one.', 'smartbook' )
			);
		}

		if ( 'auto-draft' !== $post->post_status ) {
			printf(
				'<p><a class="button button-small" href="%s">%s</a></p>',
				esc_url( $this->regenerate_url( $post->ID ) ),
				esc_html__( 'Regenerate Barcode', 'smartbook' )
			);
		}

		if ( $has_barcode ) {
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
					'action'  => BarcodeServiceProvider::REGENERATE_ACTION,
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'sb_regenerate_barcode_' . $post_id
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
				'page'            => 'sb_barcode_labels',
				'sb_book_id'      => array( $post_id ),
				'sb_print_labels' => '1',
			),
			admin_url( 'admin.php' )
		);
	}
}
