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
	 * admin-post.php action name for downloading a backup.
	 */
	private const BACKUP_ACTION = 'sb_backup_books';

	/**
	 * admin-post.php action name for restoring from a backup.
	 */
	private const RESTORE_ACTION = 'sb_restore_books';

	/**
	 * admin-post.php action name for downloading an import/restore run's
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
	 * @param ImportRunner  $runner  Chunked import/restore engine.
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
		$this->verify_request();

		$requested = isset( $_POST['sb_export_format'] ) ? sanitize_key( wp_unslash( $_POST['sb_export_format'] ) ) : 'csv';
		$format    = $this->formats->exportable()[ $requested ] ?? $this->formats->get( 'csv' );

		$this->stream_download(
			$format->encode( $this->export_rows() ),
			$format->mime_type(),
			'smartbook-export-' . gmdate( 'Y-m-d' ) . '.' . $format->extension()
		);
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
		$this->verify_request();

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$csv   = '' !== $token ? $this->runner->error_log_csv( $token ) : new WP_Error( 'sb_import_session_missing', __( 'This import session has expired.', 'smartbook' ) );

		if ( is_wp_error( $csv ) ) {
			$this->redirect_with_notice( 'error', $csv->get_error_message() );
		}

		$this->stream_download( $csv, 'text/csv', 'smartbook-import-errors-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Shared body for handle_import()/handle_restore(): validate the
	 * upload, resolve its format, run it to completion, then redirect to
	 * a result summary.
	 */
	private function handle_run( string $field, string $mode ): void {
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
		$token = isset( $_GET['sb_result_token'] ) ? sanitize_text_field( wp_unslash( $_GET['sb_result_token'] ) ) : '';

		if ( '' === $token ) {
			return null;
		}

		$mode   = isset( $_GET['sb_result_mode'] ) && 'restore' === $_GET['sb_result_mode'] ? 'restore' : 'import';
		$result = $this->runner->result( $token );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the "php://output" stream opened above, not a file on disk.
		exit;
	}

	/**
	 * Render a finished run's counts, inline error table, and a link to
	 * the full downloadable error log.
	 *
	 * @param array<string, mixed> $result Result array from ImportRunner::result()/run_all().
	 */
	private function render_result_summary( array $result ): void {
		printf( '<div class="sb-notice sb-notice--success sb-import-result"><p>%s</p>', esc_html( $this->summary_message( $result ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce already verified above; UPLOAD_ERR_OK check below is exactly the existence/validity check the sniff is asking for.
		if ( ! isset( $_FILES[ self::FILE_FIELD ] ) || UPLOAD_ERR_OK !== $_FILES[ self::FILE_FIELD ]['error'] ) {
			$this->redirect_with_notice( 'error', __( 'Please choose a CSV file to upload.', 'smartbook' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified above; $_FILES is a server-populated superglobal, not free-form user text, and every value used below is separately validated (filetype) or read straight from disk (tmp_name).
		$file     = $_FILES[ self::FILE_FIELD ];
		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'csv' => 'text/csv' ) );

			foreach ( array( __( 'Row', 'smartbook' ), __( 'Title', 'smartbook' ), __( 'Error', 'smartbook' ) ) as $header ) {
				printf( '<th>%s</th>', esc_html( $header ) );
			}

		$contents = $this->read_uploaded_file( $file['tmp_name'] );

		if ( false === $contents ) {
			$this->redirect_with_notice( 'error', __( 'The uploaded file could not be read.', 'smartbook' ) );
		}

		// An in-memory stream (not a real filesystem path) so fgetcsv() can
		// do RFC4180-correct parsing of quoted multi-line cells (e.g. a
		// multi-line "sb_notes" value), which naive line-splitting would break.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- "php://temp/" is an in-memory stream, not a file on disk.
		$handle = fopen( 'php://temp/', 'r+' );
		fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing into the in-memory stream opened above, not a file on disk.
		rewind( $handle );

		$header = fgetcsv( $handle );

		if ( false === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the in-memory stream opened above, not a file on disk.
			$this->redirect_with_notice( 'error', __( 'The uploaded file is empty.', 'smartbook' ) );
		}

		echo '</div>';
	}

		// Assignment-in-condition is the standard idiom for draining an
		// fgetcsv() stream; false is its own defined "no more rows" signal.
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$data = array_combine( $header, array_pad( $row, count( $header ), '' ) );

	/**
	 * Nonce-signed URL to download a run's full error log.
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

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the in-memory stream opened above, not a file on disk.

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
	 * Read an uploaded file's contents via WP_Filesystem rather than a
	 * direct filesystem call, per WordPress's file-operations guidelines.
	 *
	 * @param string $tmp_name PHP-managed temporary upload path from $_FILES.
	 *
	 * @return string|false File contents, or false on failure.
	 */
	private function read_uploaded_file( string $tmp_name ): string|false {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem->get_contents( $tmp_name );
	}

	/**
	 * Create or update a single book from one parsed CSV row.
	 *
	 * @param array<string, string> $data Column name => raw cell value.
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

		if ( $post_id > 0 ) {
			$existing = get_post( $post_id );

			// The row targets an existing post: only allow the update if it is
			// already a book and the current user is allowed to edit that
			// specific book (not just books in general), otherwise a crafted
			// CSV row could hijack any post ID on the site.
			if ( ! $existing instanceof WP_Post
				|| BookPostType::SLUG !== $existing->post_type
				|| ! current_user_can( 'edit_post', $post_id )
			) {
				return;
			}

			$post_data['ID'] = $post_id;
			wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

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
	 * Prefix a cell with an apostrophe if it starts with a character a
	 * spreadsheet application would interpret as the start of a formula,
	 * preventing CSV formula injection when the file is opened in Excel
	 * or similar.
	 *
	 * @param string $value Cell value.
	 */
	private function csv_safe( string $value ): string {
		if ( '' !== $value && str_contains( '=+-@', $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function notice_page_slug(): string {
		return self::PAGE_SLUG;
	}
}
