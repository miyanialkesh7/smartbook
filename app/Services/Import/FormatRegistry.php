<?php
/**
 * Lookup for every supported import/export format.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

use SmartBook\Services\Import\Formats\BackupFormat;
use SmartBook\Services\Import\Formats\CsvFormat;
use SmartBook\Services\Import\Formats\JsonFormat;
use SmartBook\Services\Import\Formats\XmlFormat;

/**
 * Central place that knows every FormatInterface implementation and how
 * to pick one, either explicitly (export: a "csv"/"json"/"xml" choice on
 * the form) or from an uploaded file's extension (import).
 *
 * "backup" is a distinct registry key from "json" even though
 * BackupFormat also uses the ".json" extension, since a backup's
 * enveloped shape is not interchangeable with a plain JSON export; it is
 * only ever selected explicitly by the Backup/Restore flow, never
 * guessed from a file extension.
 */
final class FormatRegistry {

	/**
	 * Registered formats, keyed by format key.
	 *
	 * @var array<string, FormatInterface>
	 */
	private readonly array $formats;

	/**
	 * Register the built-in formats.
	 */
	public function __construct() {
		$this->formats = array(
			'csv'    => new CsvFormat(),
			'json'   => new JsonFormat(),
			'xml'    => new XmlFormat(),
			'backup' => new BackupFormat(),
		);
	}

	/**
	 * Resolve a format by registry key ("csv", "json", "xml", "backup").
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): ?FormatInterface {
		return $this->formats[ $key ] ?? null;
	}

	/**
	 * The formats offered for manual export, in display order.
	 *
	 * @return array<string, FormatInterface>
	 */
	public function exportable(): array {
		return array(
			'csv'  => $this->formats['csv'],
			'json' => $this->formats['json'],
			'xml'  => $this->formats['xml'],
		);
	}

	/**
	 * Guess the registry key for an uploaded import file from its
	 * extension. Never returns "backup"; Restore selects that format
	 * explicitly rather than by guessing from a ".json" extension shared
	 * with plain JSON exports.
	 *
	 * @param string $extension File extension, without the dot.
	 */
	public function key_for_extension( string $extension ): ?string {
		return match ( strtolower( $extension ) ) {
			'csv'   => 'csv',
			'json'  => 'json',
			'xml'   => 'xml',
			default => null,
		};
	}
}
