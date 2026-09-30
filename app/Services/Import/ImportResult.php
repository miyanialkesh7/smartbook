<?php
/**
 * Aggregate outcome of an import/restore run.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

/**
 * Running (and, once done() is true, final) counts for one import
 * session, plus the capped list of row-level errors. ImportSession
 * persists this between chunked AJAX requests; ImportRunner produces it.
 */
final class ImportResult {

	/**
	 * Constructor.
	 *
	 * @param int           $total Total rows.
	 * @param int           $processed Rows processed so far.
	 * @param int           $created Rows created.
	 * @param int           $updated Rows updated.
	 * @param int           $skipped Rows skipped.
	 * @param int           $failed Rows that failed.
	 * @param ImportError[] $errors Row-level failures, capped by ImportRunner.
	 * @param bool          $done Whether the run has finished.
	 */
	public function __construct(
		public readonly int $total = 0,
		public readonly int $processed = 0,
		public readonly int $created = 0,
		public readonly int $updated = 0,
		public readonly int $skipped = 0,
		public readonly int $failed = 0,
		public readonly array $errors = array(),
		public readonly bool $done = false
	) {
	}

	/**
	 * From array.
	 *
	 * @param array<string, mixed> $data Array shaped like to_array()'s return.
	 */
	public static function from_array( array $data ): self {
		$errors = array();

		foreach ( (array) ( $data['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) ) {
				$errors[] = ImportError::from_array( $error );
			}
		}

		return new self(
			(int) ( $data['total'] ?? 0 ),
			(int) ( $data['processed'] ?? 0 ),
			(int) ( $data['created'] ?? 0 ),
			(int) ( $data['updated'] ?? 0 ),
			(int) ( $data['skipped'] ?? 0 ),
			(int) ( $data['failed'] ?? 0 ),
			$errors,
			(bool) ( $data['done'] ?? false )
		);
	}

	/**
	 * To array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'total'     => $this->total,
			'processed' => $this->processed,
			'created'   => $this->created,
			'updated'   => $this->updated,
			'skipped'   => $this->skipped,
			'failed'    => $this->failed,
			'errors'    => array_map( static fn ( ImportError $error ): array => $error->to_array(), $this->errors ),
			'done'      => $this->done,
		);
	}
}
