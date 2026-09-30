<?php
/**
 * Full-catalog backup format.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import\Formats;

use RuntimeException;
use SmartBook\Services\Import\FormatInterface;

/**
 * Wraps the same row shape as JsonFormat in an envelope identifying the
 * file as a SmartBook backup (plugin name, version, generation time,
 * row count), so Restore can refuse to accept an arbitrary JSON export
 * or an unrelated file that merely happens to parse as JSON.
 */
final class BackupFormat implements FormatInterface {

	/**
	 * Value of the envelope's "plugin" key, checked on decode.
	 */
	private const PLUGIN_KEY = 'smartbook';

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type(): string {
		return 'application/json';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $rows Rows to process.
	 */
	public function encode( array $rows ): string {
		$envelope = array(
			'plugin'       => self::PLUGIN_KEY,
			'type'         => 'backup',
			'version'      => defined( 'SB_VERSION' ) ? SB_VERSION : '',
			'generated_at' => gmdate( 'c' ),
			'site_url'     => home_url(),
			'count'        => count( $rows ),
			'books'        => array_values( $rows ),
		);

		$encoded = wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false !== $encoded ? $encoded : '{}';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $content Raw contents of the uploaded file.
	 *
	 * @throws RuntimeException When the file is not a valid SmartBook backup.
	 */
	public function decode( string $content ): array {
		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded )
			|| ! isset( $decoded['plugin'], $decoded['books'] )
			|| self::PLUGIN_KEY !== $decoded['plugin']
			|| ! is_array( $decoded['books'] )
		) {
			throw new RuntimeException( __( 'This file is not a valid SmartBook backup.', 'smartbook' ) );
		}

		$rows = array();

		foreach ( $decoded['books'] as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}
}
