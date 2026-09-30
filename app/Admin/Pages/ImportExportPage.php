<?php
/**
 * The SmartBook import/export page.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartBook\Admin\Support\RedirectsWithNotice;
use SmartBook\Core\Contracts\Hookable;
use SmartBook\PostTypes\BookPostType;
use SmartBook\Services\Import\BookRowSchema;
use SmartBook\Services\Import\FormatRegistry;
use SmartBook\Services\Import\ImportOptions;
use SmartBook\Services\Import\ImportRunner;
use WP_Error;
use WP_Post;

use function sb_asset_url;
use function sb_asset_version;

/**
 * Four flows built on the same underlying machinery:
 *
 * - Export: download every book as CSV, JSON, or XML (Formats\*, chosen
 *   on the form).
 * - Import: upload a CSV/JSON/XML file to create or update books,
 *   format detected from the file extension.
 * - Backup: download a complete, self-describing JSON snapshot
 *   (Formats\BackupFormat) suitable for Restore.
 * - Restore: upload a backup file to create or update books from it.
 *
 * Import and Restore both run through Services\Import\ImportRunner,
 * which does duplicate detection (Services\Import\DuplicateDetector)
 * and per-row error collection. The admin-post handlers below
 * (handle_import()/handle_restore()) call ImportRunner::run_all() and
 * process the whole file synchronously within one request -- this is
 * the no-JS baseline, consistent with every other progressive
 * enhancement in assets/js/sb-admin.js. When JavaScript is available,
 * assets/js/sb-import-export.js intercepts the same forms and instead
 * drives ImportRunner::start()/process_chunk() across several AJAX
 * requests (see Admin\ImportExportAjaxController), which is what
 * animates the on-screen progress bar; both paths produce an identical
 * ImportResult and the same downloadable error log.
 *
 * Every handler runs behind a nonce check and a capability check
 * (verify_request()); import/restore values are sanitized per-field via
 * BookFields::sanitize() (through BookRowSchema::apply_row()); exported
 * CSV cell values are neutralized against spreadsheet formula injection
 * (Formats\CsvFormat).
 */
final class ImportExportPage implements Hookable {

	use RedirectsWithNotice;

	/**
	 * Admin-post.php action name for exporting.
	 */
	private const EXPORT_ACTION = 'sb_export_books';

	/**
	 * Admin-post.php action name for importing.
	 */
	private const IMPORT_ACTION = 'sb_import_books';

	/**
	 * Admin-post.php action name for downloading a backup.
	 */
	private const BACKUP_ACTION = 'sb_backup_books';

	/**
	 * Admin-post.php action name for restoring from a backup.
	 */
	private const RESTORE_ACTION = 'sb_restore_books';

	/**
	 * Admin-post.php action name for downloading an import/restore run's
	 * error log. Public: Admin\ImportExportAjaxController builds the same
	 * nonce-signed download link for the AJAX/progress-bar path, and
	 * reuses this rather than a second copy of the action name.
	 */
	public const DOWNLOAD_LOG_ACTION = 'sb_download_import_log';

	/**
	 * Shared nonce action for every action on this page, including the
	 * download link Admin\ImportExportAjaxController builds.
	 */
	public const NONCE_ACTION = 'sb_import_export_nonce';

	/**
	 * Nonce hidden field name, also used by Admin\ImportExportAjaxController.
	 */
	public const NONCE_NAME = 'sb_import_export_nonce_field';

	/**
	 * Name of the Import tab's file upload field.
	 */
	private const IMPORT_FILE_FIELD = 'sb_import_file';

	/**
	 * Name of the Restore tab's file upload field.
	 */
	private const RESTORE_FILE_FIELD = 'sb_restore_file';

	/**
	 * Admin page slug, used to redirect back with a result notice.
	 */
	private const PAGE_SLUG = 'sb_import_export';

	/**
	 * Row-level errors shown inline before pointing to the full download.
	 */
	private const INLINE_ERROR_LIMIT = 50;

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
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::BACKUP_ACTION, array( $this, 'handle_backup' ) );
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( $this, 'handle_restore' ) );
		add_action( 'admin_post_' . self::DOWNLOAD_LOG_ACTION, array( $this, 'handle_download_log' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue this page's progress-bar/tabs script, only on this one screen.
	 */
	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		wp_enqueue_script(
			'sb-import-export',
			sb_asset_url( 'js/sb-import-export.js' ),
			array( 'sb-admin' ),
			sb_asset_version( 'js/sb-import-export.js' ),
			true
		);

		wp_localize_script(
			'sb-import-export',
			'sbImportExport',
			array(
				'startAction' => 'sb_import_start',
				'chunkAction' => 'sb_import_chunk',
				'i18n'        => array(
					'preparing'   => __( 'Uploading and preparing…', 'smartbook' ),
					/* translators: 1: number of rows processed so far, 2: total number of rows. */
					'processing'  => __( 'Processing %1$d of %2$d…', 'smartbook' ),
					'done'        => __( 'Done.', 'smartbook' ),
					'error'       => __( 'Something went wrong.', 'smartbook' ),
					/* translators: 1: created count, 2: updated count, 3: skipped count, 4: failed count. */
					'summary'     => __( '%1$d created, %2$d updated, %3$d skipped, %4$d failed.', 'smartbook' ),
					'downloadLog' => __( 'Download Error Log (CSV)', 'smartbook' ),
					'row'         => __( 'Row', 'smartbook' ),
					'title'       => __( 'Title', 'smartbook' ),
					'errorColumn' => __( 'Error', 'smartbook' ),
				),
			)
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smartbook' ) );
		}

		$result     = $this->requested_result();
		$active_tab = null !== $result ? (string) $result['mode'] : 'export';

		echo '<div class="wrap sb-admin-page">';
		printf( '<h1>%s</h1>', esc_html__( 'Import / Export', 'smartbook' ) );

		$this->render_notice();

		if ( null !== $result ) {
			$this->render_result_summary( $result );
		}

		printf( '<div class="sb-tabs" data-sb-tabs data-sb-active-tab="%s">', esc_attr( $active_tab ) );

		$this->render_export_section();
		$this->render_import_section();
		$this->render_backup_section();
		$this->render_restore_section();

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Stream every book as a download in the chosen format.
	 */
	public function handle_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_request() checks the nonce and capability first.
		$this->verify_request();

		$requested = isset( $_POST['sb_export_format'] ) ? sanitize_key( wp_unslash( $_POST['sb_export_format'] ) ) : 'csv';
		$format    = $this->formats->exportable()[ $requested ] ?? $this->formats->get( 'csv' );

		$this->stream_download(
			$format->encode( $this->export_rows() ),
			$format->mime_type(),
			'smartbook-export-' . gmdate( 'Y-m-d' ) . '.' . $format->extension()
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Download a complete backup snapshot.
	 */
	public function handle_backup(): void {
		$this->verify_request();

		$format = $this->formats->get( 'backup' );

		$this->stream_download(
			$format->encode( $this->export_rows() ),
			$format->mime_type(),
			'smartbook-backup-' . gmdate( 'Y-m-d' ) . '.json'
		);
	}

	/**
	 * Parse an uploaded file and create/update books from it (no-JS baseline).
	 */
	public function handle_import(): void {
		$this->verify_request();
		$this->handle_run( self::IMPORT_FILE_FIELD, 'import' );
	}

	/**
	 * Parse an uploaded backup file and create/update books from it (no-JS baseline).
	 */
	public function handle_restore(): void {
		$this->verify_request();
		$this->handle_run( self::RESTORE_FILE_FIELD, 'restore' );
	}

	/**
	 * Stream a finished import/restore run's row-level errors as CSV.
	 */
	public function handle_download_log(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- verify_request() checks the nonce and capability first.
		$this->verify_request();

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$csv   = '' !== $token ? $this->runner->error_log_csv( $token ) : new WP_Error( 'sb_import_session_missing', __( 'This import session has expired.', 'smartbook' ) );

		if ( is_wp_error( $csv ) ) {
			$this->redirect_with_notice( 'error', $csv->get_error_message() );
		}

		$this->stream_download( $csv, 'text/csv', 'smartbook-import-errors-' . gmdate( 'Y-m-d' ) . '.csv' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Shared body for handle_import()/handle_restore(): validate the
	 * upload, resolve its format, run it to completion, then redirect to
	 * a result summary.
	 *
	 * @param string $field Field definition.
	 * @param string $mode Import mode.
	 */
	private function handle_run( string $field, string $mode ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- only called from handle_import()/handle_restore() after verify_request(); the uploaded file is validated by ImportRunner/UploadedFileStore.
		if ( ! isset( $_FILES[ $field ] ) || UPLOAD_ERR_OK !== $_FILES[ $field ]['error'] ) {
			$this->redirect_with_notice( 'error', __( 'Please choose a file to upload.', 'smartbook' ) );
		}

		$file = $_FILES[ $field ];

		if ( 'restore' === $mode ) {
			$format_key = 'backup';
		} else {
			$extension  = strtolower( (string) pathinfo( (string) wp_unslash( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
			$format_key = $this->formats->key_for_extension( $extension );

			if ( null === $format_key ) {
				$this->redirect_with_notice( 'error', __( 'Unsupported file type. Please upload a CSV, JSON, or XML file.', 'smartbook' ) );
			}
		}

		$options = array(
			'duplicate_strategy' => isset( $_POST['duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_strategy'] ) ) : ImportOptions::STRATEGY_UPDATE,
		);

		$result = $this->runner->run_all( $file, $format_key, $options, $mode );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'sb_result_mode'  => $mode,
					'sb_result_token' => $result['token'],
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	}

	/**
	 * Every book, in the shared row shape, for Export/Backup.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function export_rows(): array {
		$posts = get_posts(
			array_merge(
				array(
					'post_type'      => BookPostType::SLUG,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				),
				BookPostType::author_scope_args()
			)
		);

		return array_map( static fn ( WP_Post $post ): array => BookRowSchema::row_for_post( $post ), $posts );
	}

	/**
	 * Send $content to the browser as a file download and terminate the request.
	 *
	 * @param string $content Body of the file to send.
	 * @param string $mime_type MIME type.
	 * @param string $filename Filename.
	 */
	private function stream_download( string $content, string $mime_type, string $filename ): never {
		nocache_headers();
		header( 'Content-Type: ' . $mime_type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $content ) );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Read a completed import/restore run's result from the query
	 * string, if any (set by handle_run()'s redirect).
	 *
	 * @return array<string, mixed>|null
	 */
	private function requested_result(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only display of a result token; the stored session belongs to the current user (checked in ImportRunner).
		$token = isset( $_GET['sb_result_token'] ) ? sanitize_text_field( wp_unslash( $_GET['sb_result_token'] ) ) : '';

		if ( '' === $token ) {
			return null;
		}

		$mode   = isset( $_GET['sb_result_mode'] ) && 'restore' === $_GET['sb_result_mode'] ? 'restore' : 'import';
		$result = $this->runner->result( $token );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		$result['mode'] = $mode;

		return $result;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Render a finished run's counts, inline error table, and a link to
	 * the full downloadable error log.
	 *
	 * @param array<string, mixed> $result Result array from ImportRunner::result()/run_all().
	 */
	private function render_result_summary( array $result ): void {
		printf( '<div class="sb-notice sb-notice--success sb-import-result"><p>%s</p>', esc_html( $this->summary_message( $result ) ) );

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type hint for static analysis.
		/** @var array<int, array<string, mixed>> $errors */
		$errors = $result['errors'];

		if ( array() !== $errors ) {
			echo '<div class="sb-table-scroll"><table class="widefat striped sb-import-result__errors">';
			echo '<thead><tr>';

			foreach ( array( __( 'Row', 'smartbook' ), __( 'Title', 'smartbook' ), __( 'Error', 'smartbook' ) ) as $header ) {
				printf( '<th>%s</th>', esc_html( $header ) );
			}

			echo '</tr></thead><tbody>';

			foreach ( array_slice( $errors, 0, self::INLINE_ERROR_LIMIT ) as $error ) {
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
					esc_html( (string) ( $error['row'] ?? '' ) ),
					esc_html( (string) ( $error['title'] ?? '' ) ),
					esc_html( (string) ( $error['message'] ?? '' ) )
				);
			}

			echo '</tbody></table></div>';

			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $this->download_log_url( (string) $result['token'] ) ),
				esc_html__( 'Download Error Log (CSV)', 'smartbook' )
			);
		}

		echo '</div>';
	}

	/**
	 * Build the "N processed — X created, Y updated, ..." summary sentence.
	 *
	 * @param array<string, mixed> $result Result array from ImportRunner::result()/run_all().
	 */
	private function summary_message( array $result ): string {
		$mode_label = 'restore' === $result['mode'] ? __( 'Restore', 'smartbook' ) : __( 'Import', 'smartbook' );

		return sprintf(
			/* translators: 1: "Import" or "Restore", 2: total rows, 3: created count, 4: updated count, 5: skipped count, 6: failed count. */
			__( '%1$s complete: %2$d row(s) processed — %3$d created, %4$d updated, %5$d skipped, %6$d failed.', 'smartbook' ),
			$mode_label,
			(int) $result['total'],
			(int) $result['created'],
			(int) $result['updated'],
			(int) $result['skipped'],
			(int) $result['failed']
		);
	}

	/**
	 * Nonce-signed URL to download a run's full error log.
	 *
	 * @param string $token Import session token.
	 */
	private function download_log_url( string $token ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DOWNLOAD_LOG_ACTION,
					'token'  => $token,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION,
			self::NONCE_NAME
		);
	}

	/**
	 * Render the Export tab.
	 */
	private function render_export_section(): void {
		echo '<div class="sb-tabs__panel" data-sb-tab-panel="export">';
		printf( '<h2>%s</h2>', esc_html__( 'Export', 'smartbook' ) );
		printf( '<p>%s</p>', esc_html__( 'Download every book and its details in your choice of format.', 'smartbook' ) );

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::EXPORT_ACTION ) );

		echo '<fieldset class="sb-choice-group">';
		printf( '<legend>%s</legend>', esc_html__( 'Format', 'smartbook' ) );
		echo '<div class="sb-choice-group__options">';

		foreach ( array(
			'csv'  => __( 'CSV', 'smartbook' ),
			'json' => __( 'JSON', 'smartbook' ),
			'xml'  => __( 'XML', 'smartbook' ),
		) as $key => $label ) {
			printf(
				'<label><input type="radio" name="sb_export_format" value="%1$s"%2$s /> %3$s</label>',
				esc_attr( $key ),
				checked( 'csv', $key, false ),
				esc_html( $label )
			);
		}

		echo '</div>';
		echo '</fieldset>';

		submit_button( __( 'Export', 'smartbook' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the Import tab.
	 */
	private function render_import_section(): void {
		echo '<div class="sb-tabs__panel" data-sb-tab-panel="import">';
		printf( '<h2>%s</h2>', esc_html__( 'Import', 'smartbook' ) );
		printf( '<p>%s</p>', esc_html__( 'Upload a CSV, JSON, or XML file to create or update books. The format is detected from the file extension.', 'smartbook' ) );

		printf(
			'<form method="post" action="%s" enctype="multipart/form-data" data-sb-import-form data-sb-mode="import">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::IMPORT_ACTION ) );

		echo '<div class="sb-field-group">';
		printf( '<label for="sb-import-file">%s</label>', esc_html__( 'File', 'smartbook' ) );
		printf(
			'<input type="file" id="sb-import-file" name="%s" accept=".csv,.json,.xml" required="required" />',
			esc_attr( self::IMPORT_FILE_FIELD )
		);
		echo '</div>';

		$this->render_duplicate_strategy_field();

		submit_button( __( 'Import', 'smartbook' ) );
		$this->render_progress_markup();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the Backup tab.
	 */
	private function render_backup_section(): void {
		echo '<div class="sb-tabs__panel" data-sb-tab-panel="backup">';
		printf( '<h2>%s</h2>', esc_html__( 'Backup', 'smartbook' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Download a complete snapshot of your library — every book, field, and taxonomy assignment — as a single JSON file you can restore from later.', 'smartbook' )
		);

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::BACKUP_ACTION ) );
		submit_button( __( 'Download Backup', 'smartbook' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the Restore tab.
	 */
	private function render_restore_section(): void {
		echo '<div class="sb-tabs__panel" data-sb-tab-panel="restore">';
		printf( '<h2>%s</h2>', esc_html__( 'Restore', 'smartbook' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Upload a SmartBook backup file to create or update books from it. Restoring never deletes a book that is missing from the backup.', 'smartbook' )
		);

		printf(
			'<form method="post" action="%s" enctype="multipart/form-data" data-sb-import-form data-sb-mode="restore">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::RESTORE_ACTION ) );

		echo '<div class="sb-field-group">';
		printf( '<label for="sb-restore-file">%s</label>', esc_html__( 'Backup file', 'smartbook' ) );
		printf(
			'<input type="file" id="sb-restore-file" name="%s" accept=".json" required="required" />',
			esc_attr( self::RESTORE_FILE_FIELD )
		);
		echo '</div>';

		$this->render_duplicate_strategy_field();

		submit_button( __( 'Restore', 'smartbook' ) );
		$this->render_progress_markup();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the duplicate-handling radio group shared by Import and Restore.
	 */
	private function render_duplicate_strategy_field(): void {
		echo '<fieldset class="sb-choice-group">';
		printf( '<legend>%s</legend>', esc_html__( 'When a matching book already exists (matched by ID, ISBN-10, or exact title)', 'smartbook' ) );
		echo '<div class="sb-choice-group__options">';

		foreach ( array(
			ImportOptions::STRATEGY_UPDATE => __( 'Update the existing book', 'smartbook' ),
			ImportOptions::STRATEGY_SKIP   => __( 'Skip it', 'smartbook' ),
			ImportOptions::STRATEGY_CREATE => __( 'Always create a new book', 'smartbook' ),
		) as $value => $label ) {
			printf(
				'<label><input type="radio" name="duplicate_strategy" value="%1$s"%2$s /> %3$s</label>',
				esc_attr( $value ),
				checked( ImportOptions::STRATEGY_UPDATE, $value, false ),
				esc_html( $label )
			);
		}

		echo '</div>';
		echo '</fieldset>';
	}

	/**
	 * Render the (initially hidden) progress bar/results markup that
	 * sb-import-export.js populates once JavaScript takes over a form's
	 * submission. Left empty and unused when JavaScript never runs.
	 */
	private function render_progress_markup(): void {
		echo '<div class="sb-import-progress sb-hidden" data-sb-import-progress>';
		echo '<div class="sb-progress-bar"><div class="sb-progress-bar__track"><div class="sb-progress-bar__fill" data-sb-import-fill style="width:0%"></div></div><span class="sb-progress-bar__label" data-sb-import-label>0%</span></div>';
		echo '<p class="sb-import-progress__status" data-sb-import-status></p>';
		echo '<div class="sb-import-progress__results" data-sb-import-results></div>';
		echo '</div>';
	}

	/**
	 * Verify the nonce and capability for every action on this page,
	 * dying on failure exactly like a core admin-post handler.
	 */
	private function verify_request(): void {
		if ( ! current_user_can( BookPostType::CAP_EDIT_BOOKS ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'smartbook' ) );
		}

		$nonce = isset( $_REQUEST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE_NAME ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'smartbook' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function notice_page_slug(): string {
		return self::PAGE_SLUG;
	}
}
