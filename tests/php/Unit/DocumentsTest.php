<?php
/**
 * Tests for document uploads.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Data\KnowledgeRepository;
use Chatlume\Knowledge\DocumentParser;
use Chatlume\Knowledge\Documents;
use Chatlume\REST\KnowledgeController;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Parsing every format, upload validation, records, and the REST routes.
 */
class DocumentsTest extends TestCase {

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private array $files = array();

	/**
	 * Stored chunks: "type:id" => [title, language, chunks].
	 *
	 * @var array<string, array>
	 */
	public array $stored = array();

	protected function set_up(): void {
		parent::set_up();
		global $wp_filesystem;

		$wp_filesystem = new class() {
			/**
			 * Reads a file.
			 *
			 * @param string $path Path.
			 * @return string|false
			 */
			public function get_contents( $path ) {
				return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test double of WP_Filesystem.
			}
		};

		$this->stored = array();
		Functions\when( 'sanitize_file_name' )->alias(
			static function ( $name ) {
				return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $name );
			}
		);
		Functions\when( 'wp_basename' )->alias(
			static function ( $path ) {
				return basename( (string) $path );
			}
		);
		Functions\when( 'wp_max_upload_size' )->justReturn( 64 * MB_IN_BYTES );
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return round( $bytes / MB_IN_BYTES ) . ' MB';
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
	}

	protected function tear_down(): void {
		global $wp_filesystem;
		$wp_filesystem = null;
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		parent::tear_down();
	}

	/**
	 * Writes a temporary file.
	 *
	 * @param string $bytes Contents.
	 * @return string Path.
	 */
	private function temp( string $bytes ): string {
		$path          = (string) tempnam( sys_get_temp_dir(), 'cl' );
		$this->files[] = $path;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $path;
	}

	/**
	 * A one-page PDF showing a line of text in Helvetica.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function pdf( string $text ): string {
		$stream  = 'BT /F1 18 Tf 72 700 Td (' . $text . ') Tj ET';
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		);

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $index => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}

		return $pdf . 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	/**
	 * A DOCX file with the given document.xml body.
	 *
	 * @param string|null $body WordprocessingML body, or null for a zip without it.
	 * @return string Path.
	 */
	private function docx( ?string $body ): string {
		$path          = sys_get_temp_dir() . '/cl-' . bin2hex( random_bytes( 4 ) ) . '.docx';
		$this->files[] = $path;
		$zip           = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types/>' );
		if ( null !== $body ) {
			$zip->addFromString( 'word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>' );
		}
		$zip->close();

		return $path;
	}

	/**
	 * Documents with an in-memory repository and test uploads accepted.
	 *
	 * @return Documents
	 */
	private function documents(): Documents {
		$test       = $this;
		$repository = new class( $test ) extends KnowledgeRepository {
			/**
			 * Test case.
			 *
			 * @var DocumentsTest
			 */
			private DocumentsTest $test;

			/**
			 * Constructor.
			 *
			 * @param DocumentsTest $test Test case.
			 */
			public function __construct( DocumentsTest $test ) {
				$this->test = $test;
			}

			/** {@inheritDoc} */
			public function replace( string $type, string $id, string $title, string $url, string $language, array $chunks ): int {
				$this->test->stored[ $type . ':' . $id ] = array( $title, $language, $chunks );
				return count( $chunks );
			}

			/** {@inheritDoc} */
			public function delete( string $type, string $id ): void {
				unset( $this->test->stored[ $type . ':' . $id ] );
			}
		};

		return new class( $repository ) extends Documents {
			/** {@inheritDoc} */
			protected function is_upload( string $path ): bool {
				return file_exists( $path );
			}
		};
	}

	/**
	 * An upload entry.
	 *
	 * @param string $name  File name.
	 * @param string $bytes Contents.
	 * @return array<string, mixed>
	 */
	private function upload( string $name, string $bytes ): array {
		$path = $this->temp( $bytes );

		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'size'     => strlen( $bytes ),
			'error'    => UPLOAD_ERR_OK,
		);
	}

	public function test_accepted_types_by_extension(): void {
		$this->assertSame( 'pdf', DocumentParser::type_of( 'Price list.PDF' ) );
		$this->assertSame( 'docx', DocumentParser::type_of( 'policy.docx' ) );
		$this->assertSame( 'md', DocumentParser::type_of( 'README.markdown' ) );
		$this->assertSame( '', DocumentParser::type_of( 'tool.exe' ) );
		$this->assertSame( '', DocumentParser::type_of( 'shell.php' ) );
		$this->assertSame( '', DocumentParser::type_of( 'old.doc' ) );
	}

	public function test_text_is_read_as_utf8(): void {
		$this->assertSame( 'Hello', DocumentParser::parse_text( "\xEF\xBB\xBFHello", 'txt' ) );
		$this->assertSame( 'Café crème', DocumentParser::parse_text( "Caf\xE9 cr\xE8me", 'txt' ) );
		$this->assertSame( 'Hi ক', DocumentParser::parse_text( "\xFF\xFE" . mb_convert_encoding( 'Hi ক', 'UTF-16LE', 'UTF-8' ), 'txt' ) );
		$this->assertSame( "One two.\n\nThree.", DocumentParser::parse_text( "One   two.\r\n\r\n\r\n\r\nThree.  ", 'txt' ) );

		$binary = DocumentParser::parse_text( "MZ\0\0binary", 'txt' );
		$this->assertInstanceOf( WP_Error::class, $binary );
	}

	public function test_markdown_keeps_words_and_drops_syntax(): void {
		$text = DocumentParser::parse_text( "# Returns\n\n**Free** returns within *30 days*. See [our policy](https://example.test/policy).\n\n- Keep the `receipt`\n- ![box](box.png) Original box\n\n```\ncode line\n```\n<b>Done</b>", 'md' );

		$this->assertSame( "Returns\n\nFree returns within 30 days. See our policy (https://example.test/policy).\n\nKeep the receipt\nbox Original box\n\ncode line\n\nDone", $text );
	}

	public function test_csv_rows_become_labeled_paragraphs(): void {
		$text = DocumentParser::parse_text( "Product;Price;Notes\nMug;12;\"Handmade,\nglazed\"\n\nPlate;20;", 'csv' );

		$this->assertSame( "Product: Mug\nPrice: 12\nNotes: Handmade,\nglazed\n\nProduct: Plate\nPrice: 20", $text );
	}

	public function test_json_is_flattened_and_validated(): void {
		$text = DocumentParser::parse_text( '{"store":{"name":"Ava","open":true},"faq":[{"q":"Delivery?","a":"3 days"},{"q":"Returns?","a":"30 days"}],"empty":null}', 'json' );

		$this->assertSame( "store › name: Ava\nstore › open: yes\nfaq › q: Delivery?\nfaq › a: 3 days\n\nfaq › q: Returns?\nfaq › a: 30 days", $text );
		$this->assertInstanceOf( WP_Error::class, DocumentParser::parse_text( '{"broken": ', 'json' ) );
	}

	public function test_docx_paragraphs_tables_and_no_entity_expansion(): void {
		$path = $this->docx(
			'<w:p><w:r><w:t>Shipping &amp; returns</w:t></w:r></w:p>'
			. '<w:p><w:r><w:t>Line one</w:t><w:br/><w:t>Line two</w:t></w:r></w:p>'
			. '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Size</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Price</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
			. '<w:p><w:r><w:instrText>HYPERLINK "x"</w:instrText><w:t>&lol;</w:t></w:r></w:p>'
		);

		$text = DocumentParser::parse_file( $path, 'docx' );

		$this->assertIsString( $text );
		$this->assertStringContainsString( 'Shipping & returns', $text );
		$this->assertStringContainsString( "Line one\nLine two", $text );
		$this->assertStringContainsString( 'Size', $text );
		$this->assertStringContainsString( 'Price', $text );
		$this->assertStringNotContainsString( 'HYPERLINK', $text );
		// Unknown entities are left as text, never expanded.
		$this->assertStringContainsString( '&lol;', $text );

		$this->assertInstanceOf( WP_Error::class, DocumentParser::parse_file( $this->docx( null ), 'docx' ) );
		$this->assertInstanceOf( WP_Error::class, DocumentParser::parse_file( $this->temp( 'not a zip' ), 'docx' ) );
	}

	public function test_pdf_text_is_extracted(): void {
		$text = DocumentParser::parse_file( $this->temp( self::pdf( 'Opening hours are 9 to 5' ) ), 'pdf' );

		$this->assertSame( 'Opening hours are 9 to 5', $text );
		$this->assertInstanceOf( WP_Error::class, DocumentParser::pdf( 'GIF89a not a pdf' ) );
		$this->assertSame( 'chatlume_document_unreadable', DocumentParser::pdf( "%PDF-1.4\ngarbage" )->get_error_code() );
	}

	public function test_contents_must_match_the_extension(): void {
		$pdf_named_txt = DocumentParser::parse_file( $this->temp( self::pdf( 'Hello' ) ), 'txt' );
		$this->assertSame( 'chatlume_document_type', $pdf_named_txt->get_error_code() );

		$text_named_pdf = DocumentParser::parse_file( $this->temp( 'plain words' ), 'pdf' );
		$this->assertInstanceOf( WP_Error::class, $text_named_pdf );
	}

	public function test_adding_a_document_stores_only_its_chunks(): void {
		$file   = $this->upload( 'Shipping_policy.md', "# Shipping\n\nWe ship worldwide in **3 days**." );
		$record = $this->documents()->add( $file );

		$this->assertIsArray( $record );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $record['id'] );
		$this->assertSame( 'Shipping_policy.md', $record['name'] );
		$this->assertSame( 'md', $record['type'] );
		$this->assertSame( 1, $record['chunks'] );
		$this->assertSame( 'en', $record['language'] );
		$this->assertSame( array( 'Shipping policy', 'en', array( "Shipping\n\nWe ship worldwide in 3 days." ) ), $this->stored[ 'document:' . $record['id'] ] );
		$this->assertSame( array( $record ), Documents::all() );
		$this->assertFalse( $this->autoload[ Documents::OPTION ] );

		$record = $this->documents()->add( $this->upload( 'tarifs.txt', 'Livraison gratuite.' ), 'fr' );
		$this->assertSame( 'fr', $record['language'] );
		$this->assertSame( 'tarifs.txt', Documents::all()[0]['name'] );
	}

	public function test_uploads_are_validated(): void {
		$documents = $this->documents();

		$this->assertSame( 'chatlume_document_type', $documents->add( $this->upload( 'run.php', '<?php echo 1;' ) )->get_error_code() );
		$this->assertSame(
			'chatlume_document_upload',
			$documents->add(
				array(
					'name'     => 'a.txt',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_OK,
				)
			)->get_error_code()
		);
		$this->assertSame(
			'chatlume_document_size',
			$documents->add(
				array(
					'name'  => 'a.txt',
					'error' => UPLOAD_ERR_INI_SIZE,
				)
			)->get_error_code()
		);
		$this->assertSame( 'chatlume_document_empty', $documents->add( $this->upload( 'blank.txt', "  \n " ) )->get_error_code() );

		$this->options['chatlume_settings'] = array( 'knowledge' => array( 'max_upload_mb' => 1 ) );
		$big                                = $documents->add( $this->upload( 'big.txt', str_repeat( 'a', MB_IN_BYTES + 1 ) ) );
		$this->assertSame( 'chatlume_document_size', $big->get_error_code() );
		$this->assertStringContainsString( '1 MB', $big->get_error_message() );
		$this->assertSame( array(), $this->stored );

		$this->options[ Documents::OPTION ] = array_fill( 0, Documents::MAX_DOCUMENTS, array( 'id' => 'x' ) );
		$this->assertSame( 'chatlume_document_limit', $documents->add( $this->upload( 'one.txt', 'More.' ) )->get_error_code() );
	}

	public function test_the_server_limit_wins_over_the_setting(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 2 * MB_IN_BYTES );
		$this->options['chatlume_settings'] = array( 'knowledge' => array( 'max_upload_mb' => 50 ) );

		$this->assertSame( 2 * MB_IN_BYTES, Documents::max_bytes() );
	}

	public function test_deleting_removes_record_and_chunks(): void {
		$documents = $this->documents();
		$record    = $documents->add( $this->upload( 'faq.txt', 'Questions and answers.' ) );

		$this->assertTrue( $documents->delete( $record['id'] ) );
		$this->assertSame( array(), Documents::all() );
		$this->assertSame( array(), $this->stored );
		$this->assertFalse( $documents->delete( $record['id'] ) );
	}

	public function test_rest_routes(): void {
		$documents  = $this->documents();
		$controller = new class( $documents ) extends KnowledgeController {
			/**
			 * Documents.
			 *
			 * @var Documents
			 */
			private Documents $store;

			/**
			 * Constructor.
			 *
			 * @param Documents $store Documents.
			 */
			public function __construct( Documents $store ) {
				$this->store = $store;
			}

			/** {@inheritDoc} */
			protected function documents(): Documents {
				return $this->store;
			}
		};

		$request = new class( array( 'language' => 'auto' ) ) extends WP_REST_Request {
			/**
			 * Uploaded files.
			 *
			 * @var array
			 */
			public array $files = array();

			/**
			 * Files.
			 *
			 * @return array
			 */
			public function get_file_params() {
				return $this->files;
			}
		};

		$this->assertSame( 'chatlume_document_upload', $controller->upload_document( $request )->get_error_code() );

		$request->files = array( 'file' => $this->upload( 'hours.txt', 'Open 9 to 5.' ) );
		$response       = $controller->upload_document( $request );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$list = $controller->get_documents()->get_data();
		$this->assertSame( $id, $list['items'][0]['id'] );
		$this->assertSame( array( 'pdf', 'docx', 'txt', 'md', 'csv', 'json' ), $list['types'] );
		$this->assertSame( 10 * MB_IN_BYTES, $list['max_bytes'] );

		$this->assertSame( array( 'deleted' => true ), $controller->delete_document( new WP_REST_Request( array( 'id' => $id ) ) )->get_data() );
		$missing = $controller->delete_document( new WP_REST_Request( array( 'id' => $id ) ) );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}
}
