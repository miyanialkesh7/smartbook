<?php
/**
 * Import/restore duplicate-handling options.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

/**
 * How ImportRunner should resolve a row that DuplicateDetector matched
 * against an existing book.
 */
final class ImportOptions {

	/**
	 * Leave the existing book untouched; the row is counted as skipped.
	 */
	public const STRATEGY_SKIP = 'skip';

	/**
	 * Overwrite the existing book's fields with the row's values.
	 */
	public const STRATEGY_UPDATE = 'update';

	/**
	 * Ignore the match and always create a new book.
	 */
	public const STRATEGY_CREATE = 'create';

	/**
	 * Every recognized strategy value.
	 *
	 * @var string[]
	 */
	private const STRATEGIES = array( self::STRATEGY_SKIP, self::STRATEGY_UPDATE, self::STRATEGY_CREATE );

	/**
	 * Constructor.
	 *
	 * @param string $duplicate_strategy What to do when a row matches an existing book.
	 */
	public function __construct(
		public readonly string $duplicate_strategy = self::STRATEGY_UPDATE
	) {
	}

	/**
	 * Build from raw (e.g. $_POST or a decoded session array) input,
	 * falling back to the default strategy for anything unrecognized.
	 *
	 * @param array<string, mixed> $raw Raw input.
	 */
	public static function from_array( array $raw ): self {
		$strategy = isset( $raw['duplicate_strategy'] ) ? (string) $raw['duplicate_strategy'] : self::STRATEGY_UPDATE;

		return new self( in_array( $strategy, self::STRATEGIES, true ) ? $strategy : self::STRATEGY_UPDATE );
	}

	/**
	 * To array.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array( 'duplicate_strategy' => $this->duplicate_strategy );
	}
}
