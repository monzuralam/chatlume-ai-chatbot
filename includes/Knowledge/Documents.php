<?php
/**
 * Uploaded documents in the knowledge base.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Data\KnowledgeRepository;
use Chatlume\Settings\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an uploaded file into knowledge chunks. Only the extracted text is
 * stored; the file itself is read from PHP's temporary upload and never
 * saved, so nothing can be downloaded or executed from the site. A short
 * record per document (name, type, size, chunks, language, date) is kept
 * in a non-autoloaded option for the Knowledge page.
 */
class Documents {

	/**
	 * Records option (not autoloaded).
	 */
	public const OPTION = 'chatlume_documents';

	/**
	 * Source type of document chunks.
	 */
	public const TYPE = 'document';

	/**
	 * Most documents kept.
	 */
	public const MAX_DOCUMENTS = 200;

	/**
	 * Storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository|null $repository Storage (tests).
	 */
	public function __construct( ?KnowledgeRepository $repository = null ) {
		$this->repository = $repository ?? new KnowledgeRepository();
	}

	/**
	 * Largest accepted upload in bytes: the setting, within the server's limit.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		$setting = max( 1, (int) Settings::get( 'knowledge.max_upload_mb', 10 ) ) * MB_IN_BYTES;
		$server  = (int) wp_max_upload_size();

		return $server > 0 ? min( $setting, $server ) : $setting;
	}

	/**
	 * Document records, newest first.
	 *
	 * @return array<int, array{id: string, name: string, type: string, size: int, chars: int, chunks: int, language: string, uploaded_at: string}>
	 */
	public static function all(): array {
		$records = get_option( self::OPTION, array() );

		return is_array( $records ) ? array_values( array_filter( $records, 'is_array' ) ) : array();
	}

	/**
	 * Adds an uploaded file.
	 *
	 * @param array<string, mixed> $file     Entry from $_FILES (via the REST request).
	 * @param string               $language Language code, '' for every language, or 'auto'.
	 * @return array<string, mixed>|WP_Error Record, or why the file was not added.
	 */
	public function add( array $file, string $language = 'auto' ) {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$too_big = in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return self::error( $too_big ? 'chatlume_document_size' : 'chatlume_document_upload', $too_big ? self::size_message() : __( 'The file could not be uploaded. Please try again.', 'chatlume-ai-chatbot' ) );
		}

		$name = sanitize_file_name( wp_basename( (string) ( $file['name'] ?? '' ) ) );
		$type = DocumentParser::type_of( $name );
		if ( '' === $type ) {
			return self::error( 'chatlume_document_type', __( 'This file type is not supported. Upload a PDF, DOCX, TXT, Markdown, CSV, or JSON file.', 'chatlume-ai-chatbot' ) );
		}

		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $path || ! $this->is_upload( $path ) ) {
			return self::error( 'chatlume_document_upload', __( 'The file could not be uploaded. Please try again.', 'chatlume-ai-chatbot' ) );
		}

		$size = (int) filesize( $path );
		if ( $size > self::max_bytes() ) {
			return self::error( 'chatlume_document_size', self::size_message() );
		}
		if ( count( self::all() ) >= self::MAX_DOCUMENTS ) {
			return self::error( 'chatlume_document_limit', __( 'You can keep up to 200 documents. Delete one first.', 'chatlume-ai-chatbot' ) );
		}

		$text = DocumentParser::parse_file( $path, $type );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		if ( '' === trim( $text ) ) {
			return self::error(
				'chatlume_document_empty',
				'pdf' === $type
					? __( 'No text was found in this PDF. Scanned pages are images; save the document as a text PDF first.', 'chatlume-ai-chatbot' )
					: __( 'No text was found in this file.', 'chatlume-ai-chatbot' )
			);
		}

		$id    = bin2hex( random_bytes( 6 ) );
		$title = mb_substr( trim( (string) pathinfo( $name, PATHINFO_FILENAME ) ), 0, 200 );
		$title = '' !== $title ? str_replace( array( '-', '_' ), ' ', $title ) : $name;

		/** This filter is documented in includes/Knowledge/Indexer.php */
		$source = apply_filters(
			'chatlume_knowledge_sources',
			array(
				'title'    => $title,
				'url'      => '',
				'language' => self::language( $language ),
				'text'     => $text,
			),
			null
		);
		$text   = is_array( $source ) ? trim( (string) ( $source['text'] ?? '' ) ) : '';
		if ( '' === $text ) {
			return self::error( 'chatlume_document_skipped', __( 'This document was left out by a filter on your site.', 'chatlume-ai-chatbot' ) );
		}

		$language = Language::primary( (string) ( $source['language'] ?? '' ) );
		$chunks   = $this->repository->replace(
			self::TYPE,
			$id,
			mb_substr( (string) ( $source['title'] ?? $title ), 0, 300 ),
			esc_url_raw( (string) ( $source['url'] ?? '' ) ),
			$language,
			Chunker::split( $text )
		);
		Embeddings::schedule();

		$record = array(
			'id'          => $id,
			'name'        => $name,
			'type'        => $type,
			'size'        => $size,
			'chars'       => mb_strlen( $text, 'UTF-8' ),
			'chunks'      => $chunks,
			'language'    => $language,
			'uploaded_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		$records = self::all();
		array_unshift( $records, $record );
		update_option( self::OPTION, $records, false );

		return $record;
	}

	/**
	 * Removes a document and its chunks.
	 *
	 * @param string $id Document ID.
	 * @return bool Whether it existed.
	 */
	public function delete( string $id ): bool {
		$records = self::all();
		$kept    = array_values(
			array_filter(
				$records,
				static function ( array $record ) use ( $id ): bool {
					return ! hash_equals( (string) $record['id'], $id );
				}
			)
		);
		if ( count( $kept ) === count( $records ) ) {
			return false;
		}

		$this->repository->delete( self::TYPE, $id );
		update_option( self::OPTION, $kept, false );

		return true;
	}

	/**
	 * Whether a path is a file PHP received in this request.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	protected function is_upload( string $path ): bool {
		return is_uploaded_file( $path );
	}

	/**
	 * Language of a new document: as chosen, or for 'auto' the site's
	 * language ('' on multilingual sites, so it serves every language).
	 *
	 * @param string $language Requested language.
	 * @return string
	 */
	private static function language( string $language ): string {
		if ( 'auto' === $language ) {
			return Language::multilingual() ? '' : Language::site();
		}

		return Language::primary( $language );
	}

	/**
	 * "The file is larger than N MB."
	 *
	 * @return string
	 */
	private static function size_message(): string {
		return sprintf(
			/* translators: %s: Maximum file size, for example "10 MB". */
			__( 'The file is larger than %s. Choose a smaller file or raise the limit in the knowledge settings.', 'chatlume-ai-chatbot' ),
			size_format( self::max_bytes() )
		);
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
