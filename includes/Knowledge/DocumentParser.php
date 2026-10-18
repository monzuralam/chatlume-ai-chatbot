<?php
/**
 * Text extraction from uploaded documents.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Vendor\Smalot\PdfParser\Config as PdfConfig;
use Chatlume\Vendor\Smalot\PdfParser\Parser as PdfParser;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns PDF, DOCX, TXT, Markdown, CSV, and JSON files into plain text.
 * Files are only read, never executed or kept: each format is checked by
 * its signature (and its MIME type where the server can tell), archives
 * are size-checked before anything is unpacked, XML is read as text
 * without resolving entities, and the extracted text is capped.
 */
final class DocumentParser {

	/**
	 * Accepted extensions and the MIME types a server may report for them.
	 */
	public const TYPES = array(
		'pdf'  => array( 'application/pdf', 'application/x-pdf' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ),
		'txt'  => array( 'text/plain' ),
		'md'   => array( 'text/plain', 'text/markdown', 'text/x-markdown' ),
		'csv'  => array( 'text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel' ),
		'json' => array( 'application/json', 'text/plain', 'text/json' ),
	);

	/**
	 * Most characters kept from one document.
	 */
	public const MAX_CHARS = 1000000;

	/**
	 * Largest unpacked DOCX body, in bytes (guards against zip bombs).
	 */
	private const MAX_UNPACKED = 50 * MB_IN_BYTES;

	/**
	 * Most CSV rows read.
	 */
	private const MAX_ROWS = 20000;

	/**
	 * Lower-case extension of a file name, or '' when not accepted.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function type_of( string $name ): string {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'markdown' === $extension ) {
			$extension = 'md';
		}

		return isset( self::TYPES[ $extension ] ) ? $extension : '';
	}

	/**
	 * Extracts the text of a file.
	 *
	 * @param string $path File path (an upload's temporary file).
	 * @param string $type Accepted extension from type_of().
	 * @return string|WP_Error Plain text, or why it could not be read.
	 */
	public static function parse_file( string $path, string $type ) {
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return self::error( 'chatlume_document_type', __( 'This file type is not supported. Upload a PDF, DOCX, TXT, Markdown, CSV, or JSON file.', 'chatlume-ai-chatbot' ) );
		}

		// Text formats may be reported as any text/* type; their content is checked when read.
		$mime = self::mime( $path );
		$text = ! in_array( $type, array( 'pdf', 'docx' ), true ) && 0 === strpos( $mime, 'text/' );
		if ( '' !== $mime && ! $text && ! in_array( $mime, self::TYPES[ $type ], true ) ) {
			return self::error( 'chatlume_document_type', __( 'The file’s contents do not match its extension.', 'chatlume-ai-chatbot' ) );
		}

		if ( 'docx' === $type ) {
			return self::docx( $path );
		}

		$bytes = self::read( $path );
		if ( null === $bytes ) {
			return self::error( 'chatlume_document_read', __( 'The file could not be read. Please try again.', 'chatlume-ai-chatbot' ) );
		}

		return 'pdf' === $type ? self::pdf( $bytes ) : self::parse_text( $bytes, $type );
	}

	/**
	 * Extracts the text of a text-based file (TXT, Markdown, CSV, JSON).
	 *
	 * @param string $bytes File contents.
	 * @param string $type  Extension.
	 * @return string|WP_Error
	 */
	public static function parse_text( string $bytes, string $type ) {
		$text = self::to_utf8( $bytes );
		if ( null === $text ) {
			return self::error( 'chatlume_document_type', __( 'This file does not contain readable text.', 'chatlume-ai-chatbot' ) );
		}

		switch ( $type ) {
			case 'md':
				$text = self::markdown( $text );
				break;
			case 'csv':
				$text = self::csv( $text );
				break;
			case 'json':
				$text = self::json( $text );
				if ( is_wp_error( $text ) ) {
					return $text;
				}
				break;
		}

		return self::finish( $text );
	}

	/**
	 * Text of a PDF.
	 *
	 * @param string $bytes File contents.
	 * @return string|WP_Error
	 */
	public static function pdf( string $bytes ) {
		if ( false === strpos( substr( $bytes, 0, 1024 ), '%PDF-' ) ) {
			return self::error( 'chatlume_document_type', __( 'This file is not a valid PDF.', 'chatlume-ai-chatbot' ) );
		}

		try {
			$config = new PdfConfig();
			$config->setRetainImageContent( false );
			$config->setDecodeMemoryLimit( 64 * MB_IN_BYTES );
			$text = ( new PdfParser( array(), $config ) )->parseContent( $bytes )->getText();
		} catch ( \Throwable $exception ) {
			return self::error( 'chatlume_document_unreadable', __( 'This PDF could not be read. It may be damaged or password-protected.', 'chatlume-ai-chatbot' ) );
		}

		return self::finish( self::to_utf8( (string) $text ) ?? '' );
	}

	/**
	 * Text of a Word document: paragraphs, line breaks, tabs, and table cells.
	 *
	 * @param string $path File path.
	 * @return string|WP_Error
	 */
	public static function docx( string $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return self::error( 'chatlume_document_zip', __( 'DOCX files need the PHP Zip extension, which this server does not have. Save the document as PDF or TXT instead.', 'chatlume-ai-chatbot' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return self::error( 'chatlume_document_type', __( 'This file is not a valid DOCX document.', 'chatlume-ai-chatbot' ) );
		}

		$stat = $zip->statName( 'word/document.xml' );
		if ( false === $stat ) {
			$zip->close();
			return self::error( 'chatlume_document_type', __( 'This file is not a valid DOCX document.', 'chatlume-ai-chatbot' ) );
		}
		if ( (int) $stat['size'] > self::MAX_UNPACKED ) {
			$zip->close();
			return self::error( 'chatlume_document_size', __( 'This document is too large to read.', 'chatlume-ai-chatbot' ) );
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( ! is_string( $xml ) ) {
			return self::error( 'chatlume_document_unreadable', __( 'This DOCX document could not be read.', 'chatlume-ai-chatbot' ) );
		}

		return self::finish( self::word_xml( $xml ) );
	}

	/**
	 * Plain text from WordprocessingML, read as text (no XML parser, so no
	 * entity expansion or external references).
	 *
	 * @param string $xml word/document.xml.
	 * @return string
	 */
	public static function word_xml( string $xml ): string {
		$xml  = (string) preg_replace( '#<w:(?:instrText|delText)\b[^>]*>.*?</w:(?:instrText|delText)>#s', '', $xml );
		$xml  = (string) preg_replace( '#<w:tab\s*/>|</w:tc>#', "\t", $xml );
		$xml  = (string) preg_replace( '#<w:(?:br|cr)\b[^>]*/>#', "\n", $xml );
		$xml  = (string) preg_replace( '#</w:p>#', "\n\n", $xml );
		$text = (string) preg_replace( '#<[^>]+>#', '', $xml );

		// Only the five XML entities and numeric references exist in this XML.
		return html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Markdown as plain text: headings, emphasis, links, images, and code
	 * markers removed; their words kept.
	 *
	 * @param string $text Markdown.
	 * @return string
	 */
	public static function markdown( string $text ): string {
		$text = (string) preg_replace( '/^```.*$/m', '', $text );
		$text = (string) preg_replace( '/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text );
		$text = (string) preg_replace( '/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/u', '$1 ($2)', $text );
		$text = (string) preg_replace( '/^[ \t]{0,3}(?:#{1,6}[ \t]+|>[ \t]?|[-*+][ \t]+(?=\S)|\d+[.)][ \t]+)/mu', '', $text );
		$text = (string) preg_replace( '/^[ \t]*(?:[-*_][ \t]*){3,}$/mu', '', $text );
		$text = (string) preg_replace( '/(\*\*|__|~~|`)(.+?)\1/u', '$2', $text );
		$text = (string) preg_replace( '/(?<![\p{L}\p{N}*])\*(?!\s)(.+?)(?<!\s)\*(?![\p{L}\p{N}*])/u', '$1', $text );

		return wp_strip_all_tags( $text );
	}

	/**
	 * CSV rows as "Column: value" lines, one paragraph per row, so each row
	 * stays together in a chunk.
	 *
	 * @param string $text CSV.
	 * @return string
	 */
	public static function csv( string $text ): string {
		$first     = strtok( $text, "\n" );
		$delimiter = ',';
		foreach ( array( ';', "\t", '|' ) as $candidate ) {
			if ( substr_count( (string) $first, $candidate ) > substr_count( (string) $first, $delimiter ) ) {
				$delimiter = $candidate;
			}
		}

		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream for fgetcsv(), which handles quoted line breaks.
		if ( false === $stream ) {
			return $text;
		}
		fwrite( $stream, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory stream.
		rewind( $stream );

		$header = null;
		$rows   = array();
		for ( $read = 0; $read < self::MAX_ROWS; $read++ ) {
			$row = fgetcsv( $stream, 0, $delimiter, '"', '\\' );
			if ( false === $row ) {
				break;
			}
			$row = array_map( static fn( $cell ): string => trim( (string) $cell ), $row );
			if ( array() === array_filter( $row, 'strlen' ) ) {
				continue;
			}
			if ( null === $header ) {
				$header = $row;
				continue;
			}
			$parts = array();
			foreach ( $row as $index => $cell ) {
				if ( '' === $cell ) {
					continue;
				}
				$label   = trim( (string) ( $header[ $index ] ?? '' ) );
				$parts[] = '' !== $label ? $label . ': ' . $cell : $cell;
			}
			$rows[] = implode( "\n", $parts );
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream.

		// A file with a single row has no header to label it.
		if ( array() === $rows && null !== $header ) {
			return implode( "\n", $header );
		}

		return implode( "\n\n", $rows );
	}

	/**
	 * JSON as "path: value" lines; each object in a list becomes a paragraph.
	 *
	 * @param string $text JSON.
	 * @return string|WP_Error
	 */
	public static function json( string $text ) {
		$data = json_decode( $text, true, 64 );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return self::error( 'chatlume_document_type', __( 'This file is not valid JSON.', 'chatlume-ai-chatbot' ) );
		}

		$lines = array();
		self::flatten( $data, '', $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Adds a JSON value's lines.
	 *
	 * @param mixed    $value  Value.
	 * @param string   $path   Path so far.
	 * @param string[] $lines  Lines (by reference).
	 * @return void
	 */
	private static function flatten( $value, string $path, array &$lines ): void {
		if ( count( $lines ) > self::MAX_ROWS * 5 ) {
			return;
		}

		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			foreach ( $value as $key => $item ) {
				$child = $is_list ? $path : ( '' === $path ? (string) $key : $path . ' › ' . $key );
				self::flatten( $item, $child, $lines );
				if ( $is_list && is_array( $item ) ) {
					$lines[] = '';
				}
			}
			return;
		}

		if ( null === $value || '' === $value ) {
			return;
		}
		$text    = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
		$lines[] = '' !== $path ? $path . ': ' . $text : $text;
	}

	/**
	 * Text as UTF-8 (UTF-16 and Windows-1252 files are converted), or null
	 * for binary data.
	 *
	 * @param string $bytes Bytes.
	 * @return string|null
	 */
	public static function to_utf8( string $bytes ): ?string {
		if ( "\xEF\xBB\xBF" === substr( $bytes, 0, 3 ) ) {
			$bytes = substr( $bytes, 3 );
		} elseif ( "\xFF\xFE" === substr( $bytes, 0, 2 ) || "\xFE\xFF" === substr( $bytes, 0, 2 ) ) {
			$bytes = (string) mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', "\xFF\xFE" === substr( $bytes, 0, 2 ) ? 'UTF-16LE' : 'UTF-16BE' );
		}

		if ( false !== strpos( substr( $bytes, 0, 8192 ), "\0" ) ) {
			return null;
		}

		return mb_check_encoding( $bytes, 'UTF-8' ) ? $bytes : (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
	}

	/**
	 * Normalized whitespace, capped length.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function finish( string $text ): string {
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$text = (string) preg_replace( '/[^\S\n]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/u", "\n\n", $text );

		return trim( mb_substr( trim( $text ), 0, self::MAX_CHARS, 'UTF-8' ) );
	}

	/**
	 * MIME type the server detects, or '' when it cannot tell.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function mime( string $path ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return '';
		}
		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );

		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * File contents through the WordPress filesystem API.
	 *
	 * @param string $path Path.
	 * @return string|null
	 */
	private static function read( string $path ): ?string {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() ) {
				return null;
			}
		}

		$bytes = $wp_filesystem->get_contents( $path );

		return is_string( $bytes ) ? $bytes : null;
	}

	/**
	 * A 400 error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
