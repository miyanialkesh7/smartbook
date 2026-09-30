<?php
/**
 * Transient-backed storage for one import/restore session.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import;

/**
 * Thin key-value repository over WordPress transients, keyed by a random
 * token. A chunked import spans several independent AJAX requests (or,
 * for the no-JS fallback, several loop iterations within one request),
 * so its progress has to live somewhere between requests; a transient
 * is the natural fit and expires on its own if a session is abandoned.
 */
final class ImportSession {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'sb_import_';

	/**
	 * How long an abandoned session's data (and, transitively, its
	 * downloadable error log) stays available before expiring.
	 */
	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Generate a new, unguessable session token.
	 */
	public function generate_token(): string {
		return wp_generate_password( 20, false, false );
	}

	/**
	 * Persist a session's state.
	 *
	 * @param string               $token Import session token.
	 * @param array<string, mixed> $data Session state.
	 */
	public function save( string $token, array $data ): void {
		set_transient( self::PREFIX . $token, $data, self::TTL );
	}

	/**
	 * Read a session's state, or null if it does not exist or has expired.
	 *
	 * @param string $token Import session token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $token ): ?array {
		$data = get_transient( self::PREFIX . $token );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Remove a session's state.
	 *
	 * @param string $token Import session token.
	 */
	public function delete( string $token ): void {
		delete_transient( self::PREFIX . $token );
	}
}
