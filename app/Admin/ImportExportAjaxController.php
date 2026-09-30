<?php
/**
 * AJAX endpoints driving the import/restore progress bar.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin;

use SmartBook\Admin\Pages\ImportExportPage;
use SmartBook\Core\Contracts\Hookable;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\Import\FormatRegistry;
use SmartBook\Services\Import\ImportOptions;
use SmartBook\Services\Import\ImportRunner;

/**
 * The progressive-enhancement counterpart to
 * Admin\Pages\ImportExportPage's synchronous admin-post handlers:
 * assets/js/sb-import-export.js calls "sb_import_start" once per
 * upload and then "sb_import_chunk" repeatedly, animating a progress
 * bar as ImportRunner works through the file in bounded batches. Both
 * endpoints share the exact same ImportRunner used by the no-JS
 * fallback, so an import behaves identically either way.
 *
 * Reuses the "sb_admin_nonce" nonce that Assets\AdminAssetLoader
 * already localizes to every SmartBook admin screen, rather than
 * introducing a second nonce just for these two actions.
 */
final class ImportExportAjaxController implements Hookable {

	/**
	 * Shared admin AJAX nonce action, localized by Assets\AdminAssetLoader.
	 */
	private const NONCE_ACTION = 'sb_admin_nonce';

	/**
	 * Constructor.
	 *
	 * @param ImportRunner   $runner Chunked import/restore engine.
	 * @param FormatRegistry $formats Available CSV/JSON/XML/Backup formats.
	 */
	public function __construct(
		private readonly ImportRunner $runner,
		private readonly FormatRegistry $formats
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_sb_import_start', array( $this, 'handle_start' ) );
		add_action( 'wp_ajax_sb_import_chunk', array( $this, 'handle_chunk' ) );
	}

	/**
	 * Store an uploaded file and open a new chunked import/restore session.
	 */
	public function handle_start(): void {
		$this->verify_request();

		$mode  = isset( $_POST['mode'] ) && 'restore' === $_POST['mode'] ? 'restore' : 'import';
		$field = 'restore' === $mode ? 'sb_restore_file' : 'sb_import_file';

		if ( ! isset( $_FILES[ $field ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a file to upload.', 'smartbook' ) ) );
		}

		$file = $_FILES[ $field ];

		if ( 'restore' === $mode ) {
			$format_key = 'backup';
		} else {
			$extension  = strtolower( (string) pathinfo( (string) wp_unslash( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
			$format_key = $this->formats->key_for_extension( $extension );

			if ( null === $format_key ) {
				wp_send_json_error( array( 'message' => __( 'Unsupported file type. Please upload a CSV, JSON, or XML file.', 'smartbook' ) ) );
			}
		}

		$options = array(
			'duplicate_strategy' => isset( $_POST['duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_strategy'] ) ) : ImportOptions::STRATEGY_UPDATE,
		);

		$result = $this->runner->start( $file, $format_key, $options, $mode );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process the next batch of rows for a session started by handle_start().
	 */
	public function handle_chunk(): void {
		$this->verify_request();

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Missing import session.', 'smartbook' ) ) );
		}

		$result = $this->runner->process_chunk( $token );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$result['download_log_url'] = $this->download_log_url( (string) $result['token'] );

		wp_send_json_success( $result );
	}

	/**
	 * Nonce-signed URL to download a run's full error log, equivalent to
	 * the one Admin\Pages\ImportExportPage renders for the no-JS path --
	 * built with add_query_arg()/wp_create_nonce() rather than
	 * wp_nonce_url(), since this URL goes into a JSON response for
	 * sb-import-export.js to assign directly to an anchor's .href, not
	 * into an HTML attribute; wp_nonce_url() HTML-escapes its return
	 * value (& becomes &amp;), which is correct for the latter and wrong
	 * for the former.
	 *
	 * @param string $token Import session token.
	 */
	private function download_log_url( string $token ): string {
		return add_query_arg(
			array(
				'action'                     => ImportExportPage::DOWNLOAD_LOG_ACTION,
				'token'                      => $token,
				ImportExportPage::NONCE_NAME => wp_create_nonce( ImportExportPage::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Shared nonce + capability check for both endpoints. Sends a JSON
	 * error response (rather than the default wp_die() HTML page) and
	 * terminates the request on failure, since the caller is always fetch().
	 */
	private function verify_request(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'smartbook' ) ), 403 );
		}

		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'smartbook' ) ), 403 );
		}
	}
}
