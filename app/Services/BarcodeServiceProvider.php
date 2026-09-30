<?php
/**
 * Barcode service provider.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Core\AbstractServiceProvider;
use SmartBook\Core\Contracts\ContainerInterface;
use SmartBook\PostTypes\BookPostType;
use WP_Post;

use function sb_option;

/**
 * Binds the barcode generator/manager and wires their lifecycle hooks:
 * assign a value and generate the image automatically when a book is
 * saved, clean up the stored file when a book is permanently deleted,
 * and handle the manual "Regenerate" action from BarcodeMetaBox.
 */
final class BarcodeServiceProvider extends AbstractServiceProvider {

	/**
	 * Admin-post.php action name for the manual regenerate button.
	 */
	public const REGENERATE_ACTION = 'sb_regenerate_barcode';

	/**
	 * {@inheritDoc}
	 *
	 * @param ContainerInterface $container Application service container.
	 */
	public function register( ContainerInterface $container ): void {
		$container->singleton( BarcodeGenerator::class, static fn (): BarcodeGenerator => new BarcodeGenerator() );

		$container->singleton(
			BarcodeManager::class,
			static fn ( ContainerInterface $container ): BarcodeManager => new BarcodeManager(
				$container->make( BarcodeGenerator::class ),
				$container->make( LoggerInterface::class )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * No-op when the "enable_barcode" setting is off (Settings\Settings):
	 * no barcode gets auto-generated on save, no cleanup runs on delete,
	 * and the manual regenerate action isn't reachable. Existing
	 * generated files are left in place (re-enabling picks up right
	 * where it left off) rather than deleted here.
	 */
	public function boot( ContainerInterface $container ): void {
		if ( ! sb_option( 'enable_barcode', true ) ) {
			return;
		}

		$manager = $container->make( BarcodeManager::class );

		add_action(
			'save_post_' . BookPostType::SLUG,
			static function ( int $post_id, WP_Post $post ) use ( $manager ): void {
				if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
					return;
				}

				if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
					return;
				}

				$manager->ensure_generated( $post_id );
			},
			20,
			2
		);

		add_action(
			'delete_post',
			static function ( int $post_id ) use ( $manager ): void {
				$post = get_post( $post_id );

				if ( $post instanceof WP_Post && BookPostType::SLUG === $post->post_type ) {
					$manager->delete_for_post( $post_id );
				}
			}
		);

		add_action(
			'admin_post_' . self::REGENERATE_ACTION,
			static function () use ( $manager ): void {
				// The nonce action string is per-post ("..._{$post_id}"), so
				// post_id has to be read before it can be verified below;
				// it is only used for that nonce string and the capability
				// check, both of which gate the actual regenerate() call.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

				if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
					wp_die( esc_html__( 'You do not have permission to perform this action.', 'smartbook' ) );
				}

				check_admin_referer( 'sb_regenerate_barcode_' . $post_id );

				$manager->regenerate( $post_id );

				wp_safe_redirect( (string) get_edit_post_link( $post_id, 'raw' ) );
				exit;
			}
		);
	}
}
