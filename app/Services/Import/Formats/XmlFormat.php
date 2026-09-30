<?php
/**
 * XML import/export format.
 *
 * @package SmartBook
 */

declare(strict_types=1);

namespace SmartBook\Services\Import\Formats;

use RuntimeException;
use SimpleXMLElement;
use SmartBook\Services\Import\BookRowSchema;
use SmartBook\Services\Import\FormatInterface;

/**
 * Reads and writes the row shape described by BookRowSchema as XML:
 * a <books> root containing one <book> per row, taxonomy columns
 * (string[]) as a wrapper element containing one <term> child per value.
 */
final class XmlFormat implements FormatInterface {

	/**
	 * Root element name.
	 */
	private const ROOT_ELEMENT = 'books';

	/**
	 * Per-row element name.
	 */
	private const ROW_ELEMENT = 'book';

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'xml';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime_type(): string {
		return 'application/xml';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $rows Rows to process.
	 */
	public function encode( array $rows ): string {
		$taxonomy_columns = BookRowSchema::taxonomy_columns();

		$xml = new SimpleXMLElement( '<?xml version="1.0" encoding="UTF-8"?><' . self::ROOT_ELEMENT . '/>' );

		foreach ( $rows as $row ) {
			$book = $xml->addChild( self::ROW_ELEMENT );

			foreach ( $row as $column => $value ) {
				if ( ! is_string( $column ) || ! $this->is_valid_tag( $column ) ) {
					continue;
				}

				if ( array_key_exists( $column, $taxonomy_columns ) ) {
					$group = $book->addChild( $column );

					foreach ( (array) $value as $term ) {
						$group->addChild( 'term', (string) $term );
					}

					continue;
				}

				$book->addChild( $column, (string) $value );
			}
		}

		$output = $xml->asXML();

		return false !== $output ? $output : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $content Raw contents of the uploaded file.
	 *
	 * @throws RuntimeException When the XML file cannot be parsed.
	 */
	public function decode( string $content ): array {
		$previous_state = libxml_use_internal_errors( true );

		// Deliberately omit LIBXML_NOENT/LIBXML_DTDLOAD/LIBXML_DTDATTR:
		// resolving entities or an external DTD from attacker-controlled
		// XML is how XXE and "billion laughs" attacks work. Without
		// those flags libxml never fetches or expands anything external,
		// regardless of what the uploaded file's DOCTYPE claims.
		$xml = simplexml_load_string( $content, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		if ( false === $xml ) {
			throw new RuntimeException( esc_html__( 'The XML file could not be parsed.', 'smartbook' ) );
		}

		$taxonomy_columns = BookRowSchema::taxonomy_columns();
		$rows             = array();

		foreach ( $xml->{self::ROW_ELEMENT} as $book ) {
			$data = array();

			foreach ( $book->children() as $child ) {
				$name = $child->getName();

				if ( array_key_exists( $name, $taxonomy_columns ) ) {
					$data[ $name ] = array();

					foreach ( $child->term as $term ) {
						$data[ $name ][] = (string) $term;
					}

					continue;
				}

				$data[ $name ] = (string) $child;
			}

			$rows[] = $data;
		}

		return $rows;
	}

	/**
	 * Whether a column name is safe to use as an XML element name (guards
	 * against malformed export data producing invalid XML).
	 *
	 * @param string $name Name.
	 */
	private function is_valid_tag( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name );
	}
}
