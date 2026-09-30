<?php
/**
 * Chunked import/restore orchestrator.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

use SmartBook\Services\LoggerInterface;
use Throwable;
use WP_Error;

/**
 * Drives one import (CSV/JSON/XML) or restore (Backup) run from an
 * uploaded file to a finished ImportResult, in bounded-size chunks so a
 * large catalog does not have to be processed within a single PHP
 * request's time limit.
 *
 * Two callers share this same engine:
 * - Admin\ImportExportAjaxController calls start()/process_chunk()
 *   repeatedly across several AJAX round trips, driving the on-screen
 *   progress bar (JS present).
 * - Admin\Pages\ImportExportPage's admin-post handlers call run_all(),
 *   which loops the same two methods to completion within one request
 *   (no JS required, matching this plugin's progressive-enhancement
 *   convention -- see assets/js/sb-admin.js's file header).
 *
 * Both paths produce the same ImportResult shape and the same
 * downloadable error log (error_log_csv()), identified by a session
 * token that outlives the run for exactly that purpose.
 */
final class ImportRunner {

	/**
	 * Rows processed per process_chunk() call when driven by AJAX.
	 */
	public const DEFAULT_CHUNK_SIZE = 25;

	/**
	 * Rows processed per process_chunk() call inside run_all(), where
	 * there is no round-trip cost to amortize and a bigger batch means
	 * fewer decode() passes over the file.
	 */
	private const SYNCHRONOUS_CHUNK_SIZE = 200;

	/**
	 * Row-level errors kept per session; a catalog-wide failure (wrong
	 * format, corrupt file) does not need thousands of near-identical
	 * rows recorded to be understood.
	 */
	private const MAX_STORED_ERRORS = 200;

	/**
	 * Constructor.
	 *
	 * @param FormatRegistry    $formats    Available import formats.
	 * @param DuplicateDetector $duplicates Finds the existing book a row matches.
	 * @param ImportSession     $sessions   Persists the state of chunked runs.
	 * @param UploadedFileStore $uploads    Stores uploaded files between requests.
	 * @param LoggerInterface   $logger     Logger for per-row failures.
	 */
	public function __construct(
		private readonly FormatRegistry $formats,
		private readonly DuplicateDetector $duplicates,
		private readonly ImportSession $sessions,
		private readonly UploadedFileStore $uploads,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * Store the uploaded file, parse it once to determine the row count,
	 * and open a new session for it.
	 *
	 * @param array<string, mixed> $file        One $_FILES entry.
	 * @param string               $format_key  FormatRegistry key ("csv", "json", "xml", "backup").
	 * @param array<string, mixed> $raw_options Raw duplicate-handling options (see ImportOptions).
	 * @param string               $mode        "import" or "restore", carried through for display only.
	 *
	 * @return array{token: string, total: int}|WP_Error
	 */
	public function start( array $file, string $format_key, array $raw_options, string $mode = 'import' ): array|WP_Error {
		$format = $this->formats->get( $format_key );

		if ( null === $format ) {
			return new WP_Error( 'sb_import_format', __( 'Unsupported import format.', 'smartbook' ) );
		}

		$stored = $this->uploads->store( $file, array( $format->extension() ) );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$rows = $this->decode( $format, $stored['path'] );

		if ( is_wp_error( $rows ) ) {
			$this->uploads->delete( $stored['path'] );

			return $rows;
		}

		$total = count( $rows );

		if ( 0 === $total ) {
			$this->uploads->delete( $stored['path'] );

			return new WP_Error( 'sb_import_empty', __( 'No rows were found in the uploaded file.', 'smartbook' ) );
		}

		$token   = $this->sessions->generate_token();
		$options = ImportOptions::from_array( $raw_options );

		$this->sessions->save(
			$token,
			array(
				'file'     => $stored['path'],
				'format'   => $format_key,
				'mode'     => $mode,
				'filename' => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
				'options'  => $options->to_array(),
				'total'    => $total,
				'offset'   => 0,
				'created'  => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'errors'   => array(),
				'user_id'  => get_current_user_id(),
			)
		);

		return array(
			'token' => $token,
			'total' => $total,
		);
	}

	/**
	 * Process the next batch of rows for a session.
	 *
	 * @param string $token Import session token.
	 * @param int    $limit Maximum number of items.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_chunk( string $token, int $limit = self::DEFAULT_CHUNK_SIZE ): array|WP_Error {
		$session = $this->owned_session( $token );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$format = $this->formats->get( (string) $session['format'] );

		if ( null === $format ) {
			return new WP_Error( 'sb_import_format', __( 'Unsupported import format.', 'smartbook' ) );
		}

		$rows = $this->decode( $format, (string) $session['file'] );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$options = ImportOptions::from_array( (array) $session['options'] );
		$offset  = (int) $session['offset'];
		$slice   = array_slice( $rows, $offset, max( 1, $limit ) );

		foreach ( $slice as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// +2: one for the header row, one to convert a 0-based index
			// to the 1-based row number a spreadsheet application shows.
			$this->process_row( $session, $row, $options, $offset + (int) $index + 2 );
		}

		$new_offset        = $offset + count( $slice );
		$session['offset'] = $new_offset;
		$done              = $new_offset >= (int) $session['total'];

		$this->sessions->save( $token, $session );

		if ( $done ) {
			$this->uploads->delete( (string) $session['file'] );
		}

		return $this->to_response( $token, $session, $done );
	}

	/**
	 * Run an entire import/restore to completion within the current
	 * request; the no-JS fallback for start()/process_chunk().
	 *
	 * @param array<string, mixed> $file One $_FILES entry.
	 * @param string               $format_key Format registry key.
	 * @param array<string, mixed> $raw_options Raw duplicate-handling options.
	 * @param string               $mode Import mode.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_all( array $file, string $format_key, array $raw_options, string $mode = 'import' ): array|WP_Error {
		$started = $this->start( $file, $format_key, $raw_options, $mode );

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$chunk = array();

		do {
			$chunk = $this->process_chunk( $started['token'], self::SYNCHRONOUS_CHUNK_SIZE );

			if ( is_wp_error( $chunk ) ) {
				return $chunk;
			}
		} while ( ! $chunk['done'] );

		return $chunk;
	}

	/**
	 * Current (or final) progress/result for a session, without
	 * processing any further rows.
	 *
	 * @param string $token Import session token.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function result( string $token ): array|WP_Error {
		$session = $this->owned_session( $token );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$done = (int) $session['offset'] >= (int) $session['total'];

		return $this->to_response( $token, $session, $done );
	}

	/**
	 * Render a session's recorded errors as a downloadable CSV string.
	 *
	 * @param string $token Import session token.
	 */
	public function error_log_csv( string $token ): string|WP_Error {
		$session = $this->owned_session( $token );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory php://temp stream, or this plugin's own stored upload; not user-controlled paths.
		$handle = fopen( 'php://temp', 'w+' );

		fputcsv( $handle, array( 'Row', 'Title', 'Message' ) );

		foreach ( (array) $session['errors'] as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}

			fputcsv( $handle, array( $error['row'] ?? '', $error['title'] ?? '', $error['message'] ?? '' ) );
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream, or this plugin's own stored upload; not user-controlled paths.
		fclose( $handle );

		return false !== $content ? $content : '';
	}

	/**
	 * Resolve, dedupe-match, and apply a single row, mutating $session's
	 * running counts and (on failure) its capped error list in place.
	 *
	 * @param array<string, mixed> &$session Session state, by reference.
	 * @param array<string, mixed> $row Decoded row.
	 * @param ImportOptions        $options Options.
	 * @param int                  $row_number One-based row number.
	 */
	private function process_row( array &$session, array $row, ImportOptions $options, int $row_number ): void {
		try {
			$existing_id = $this->duplicates->find_existing_id( $row );

			if ( $existing_id > 0 && ImportOptions::STRATEGY_SKIP === $options->duplicate_strategy ) {
				++$session['skipped'];
				return;
			}

			$is_update = $existing_id > 0 && ImportOptions::STRATEGY_CREATE !== $options->duplicate_strategy;

			// The row matched an existing book: only allow the update if the current
			// user may edit that specific book (not just books in general), otherwise
			// a crafted ID or title could overwrite someone else's book.
			if ( $is_update && ! current_user_can( 'edit_post', $existing_id ) ) {
				++$session['failed'];
				$this->record_error( $session, $row_number, $row, __( 'You are not allowed to edit the matching book.', 'smartbook' ) );
				return;
			}

			$result = BookRowSchema::apply_row( $row, $is_update ? $existing_id : 0 );

			if ( is_wp_error( $result ) ) {
				++$session['failed'];
				$this->record_error( $session, $row_number, $row, $result->get_error_message() );
				return;
			}

			++$session[ $is_update ? 'updated' : 'created' ];
		} catch ( Throwable $exception ) {
			++$session['failed'];
			$this->record_error( $session, $row_number, $row, $exception->getMessage() );
			$this->logger->error(
				'SmartBook import row {row} failed: {message}',
				array(
					'row'     => $row_number,
					'message' => $exception->getMessage(),
				)
			);
		}
	}

	/**
	 * Append a capped row-level error to a session's error list.
	 *
	 * @param array<string, mixed> &$session Session state, by reference.
	 * @param int                  $row_number One-based row number.
	 * @param array<string, mixed> $row Decoded row.
	 * @param string               $message Message text.
	 */
	private function record_error( array &$session, int $row_number, array $row, string $message ): void {
		if ( count( $session['errors'] ) >= self::MAX_STORED_ERRORS ) {
			return;
		}

		$session['errors'][] = ( new ImportError( $row_number, isset( $row['title'] ) ? (string) $row['title'] : '', $message ) )->to_array();
	}

	/**
	 * Decode a stored file's current contents, translating a parse
	 * failure into a WP_Error instead of letting the exception escape.
	 *
	 * @param FormatInterface $format Format handler.
	 * @param string          $path File path.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function decode( FormatInterface $format, string $path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- in-memory php://temp stream, or this plugin's own stored upload; not user-controlled paths.
		$content = file_exists( $path ) ? file_get_contents( $path ) : false;

		if ( false === $content ) {
			return new WP_Error( 'sb_import_file_missing', __( 'The uploaded file is no longer available; please try again.', 'smartbook' ) );
		}

		try {
			return $format->decode( $content );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'sb_import_parse', $exception->getMessage() );
		}
	}

	/**
	 * Load a session and verify it exists and belongs to the current user.
	 *
	 * @param string $token Import session token.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function owned_session( string $token ): array|WP_Error {
		$session = $this->sessions->get( $token );

		if ( null === $session ) {
			return new WP_Error( 'sb_import_session_missing', __( 'This import session has expired. Please start again.', 'smartbook' ) );
		}

		if ( get_current_user_id() !== (int) $session['user_id'] ) {
			return new WP_Error( 'sb_import_forbidden', __( 'You do not have permission to access this import session.', 'smartbook' ) );
		}

		return $session;
	}

	/**
	 * Build the array both process_chunk() and result() return.
	 *
	 * @param string               $token Import session token.
	 * @param array<string, mixed> $session Session state.
	 * @param bool                 $done Whether the run has finished.
	 *
	 * @return array<string, mixed>
	 */
	private function to_response( string $token, array $session, bool $done ): array {
		$result = ImportResult::from_array(
			array(
				'total'     => $session['total'],
				'processed' => $session['offset'],
				'created'   => $session['created'],
				'updated'   => $session['updated'],
				'skipped'   => $session['skipped'],
				'failed'    => $session['failed'],
				'errors'    => $session['errors'],
				'done'      => $done,
			)
		);

		return $result->to_array() + array(
			'token'    => $token,
			'filename' => $session['filename'] ?? '',
			'mode'     => $session['mode'] ?? 'import',
		);
	}
}
