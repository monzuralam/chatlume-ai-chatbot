<?php
/**
 * REST: knowledge index status and sources.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Cron\IndexQueue;
use Chatlume\Data\KnowledgeRepository;
use Chatlume\Installer\Schema;
use Chatlume\Knowledge\DocumentParser;
use Chatlume\Knowledge\Documents;
use Chatlume\Knowledge\Embeddings;
use Chatlume\Knowledge\ProductSync;
use Chatlume\Plugin;
use Chatlume\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET chatlume/v1/knowledge/status: index size, rebuild progress, and
 * the post types to choose from. POST chatlume/v1/knowledge/reindex: starts
 * a full rebuild. GET chatlume/v1/knowledge/sources: what is indexed.
 * GET/POST chatlume/v1/knowledge/documents and DELETE
 * chatlume/v1/knowledge/documents/{id}: uploaded documents. The chosen
 * sources themselves are saved through /settings.
 */
class KnowledgeController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/knowledge/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/knowledge/reindex',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reindex' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/knowledge/documents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_documents' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_document' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'language' => array(
							'type'              => 'string',
							'default'           => 'auto',
							'maxLength'         => 20,
							'pattern'           => '^(auto|[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*)?$',
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/knowledge/documents/(?P<id>[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_document' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'pattern'  => '^[a-f0-9]{12}$',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/knowledge/sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sources' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'type'     => array(
						'type'              => 'string',
						'default'           => '',
						'enum'              => array( '', 'post', 'business', 'document', 'product' ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 200,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
	}

	/**
	 * Only Chatlume managers.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( Plugin::current_user_can_manage() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage the knowledge base.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /knowledge/status. Runs the next batch itself when WP-Cron is late.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		$queue = ( new IndexQueue() )->maybe_run();
		( new Embeddings() )->maybe_run();

		return self::no_store( self::status( $queue ) );
	}

	/**
	 * POST /knowledge/reindex.
	 *
	 * @return WP_REST_Response
	 */
	public function reindex(): WP_REST_Response {
		return self::no_store( self::status( ( new IndexQueue() )->start() ) );
	}

	/**
	 * GET /knowledge/documents: records, accepted types, and the size limit.
	 *
	 * @return WP_REST_Response
	 */
	public function get_documents(): WP_REST_Response {
		return self::no_store(
			array(
				'items'        => Documents::all(),
				'max_bytes'    => Documents::max_bytes(),
				'server_bytes' => (int) wp_max_upload_size(),
				'types'        => array_keys( DocumentParser::TYPES ),
				'docx'         => class_exists( '\ZipArchive' ),
			)
		);
	}

	/**
	 * POST /knowledge/documents: one file (multipart field "file").
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_document( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'chatlume_document_upload', __( 'Choose a file to upload.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$record = $this->documents()->add( $files['file'], (string) $request->get_param( 'language' ) );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$response = self::no_store( $record );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * DELETE /knowledge/documents/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_document( WP_REST_Request $request ) {
		if ( ! $this->documents()->delete( (string) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'chatlume_document_missing', __( 'This document no longer exists.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return self::no_store( array( 'deleted' => true ) );
	}

	/**
	 * Document storage (replaceable in tests).
	 *
	 * @return Documents
	 */
	protected function documents(): Documents {
		return new Documents();
	}

	/**
	 * GET /knowledge/sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_sources( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new KnowledgeRepository() )->sources(
			array(
				'type'     => (string) $request->get_param( 'type' ),
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);

		$response = self::no_store( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	/**
	 * Status payload.
	 *
	 * @param array<string, mixed> $queue Queue state.
	 * @return array<string, mixed>
	 */
	private static function status( array $queue ): array {
		$index    = ( new KnowledgeRepository() )->stats();
		$fulltext = 'yes' === get_option( Schema::FULLTEXT_OPTION, 'no' );

		return array(
			'index'       => $index,
			'size'        => self::size( $index, $fulltext ),
			'queue'       => array(
				'status'   => (string) $queue['status'],
				'total'    => (int) $queue['total'],
				'done'     => (int) $queue['done'],
				'started'  => self::iso( (string) $queue['started'] ),
				'finished' => self::iso( (string) $queue['finished'] ),
			),
			'fulltext'    => $fulltext,
			'embeddings'  => self::embeddings(),
			'post_types'  => DisplayController::post_types(),
			'woocommerce' => ProductSync::active(),
			'chosen'      => array(
				'included' => DisplayController::pages( array_map( 'intval', (array) Settings::get( 'knowledge.include_ids', array() ) ) ),
				'excluded' => DisplayController::pages( array_map( 'intval', (array) Settings::get( 'knowledge.exclude_ids', array() ) ) ),
			),
		);
	}

	/**
	 * How the index size compares with what answers quickly on typical
	 * shared hosting: about 5,000 passages with FULLTEXT search (measured
	 * well under the 300 ms budget), 2,000 without it, and 64 MB of text and
	 * vectors.
	 *
	 * @param array<string, mixed> $index    Index stats.
	 * @param bool                 $fulltext Whether FULLTEXT search is available.
	 * @return array{level: string, chunks_limit: int, bytes_limit: int}
	 */
	public static function size( array $index, bool $fulltext ): array {
		$chunks_limit = $fulltext ? 5000 : 2000;
		$bytes_limit  = 64 * MB_IN_BYTES;
		$share        = max( (int) $index['chunks'] / $chunks_limit, (int) $index['bytes'] / $bytes_limit );

		if ( $share > 1 ) {
			$level = 'high';
		} elseif ( $share >= 0.8 ) {
			$level = 'warn';
		} else {
			$level = 'ok';
		}

		return array(
			'level'        => $level,
			'chunks_limit' => $chunks_limit,
			'bytes_limit'  => $bytes_limit,
		);
	}

	/**
	 * Embeddings progress. Never includes keys or provider responses.
	 *
	 * @return array<string, mixed>
	 */
	private static function embeddings(): array {
		$state = Embeddings::state();
		$model = Embeddings::model_key();

		return array(
			'switched_on' => (bool) Settings::get( 'knowledge.use_embeddings', true ),
			'available'   => null !== $model,
			'model'       => (string) $model,
			'pending'     => Embeddings::pending(),
			'status'      => $state['status'],
			'error'       => $state['error'],
			'message'     => $state['message'],
			'retry_at'    => $state['retry_at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $state['retry_at'] ) : '',
		);
	}

	/**
	 * UTC "Y-m-d H:i:s" as ISO 8601, or ''.
	 *
	 * @param string $mysql Time.
	 * @return string
	 */
	private static function iso( string $mysql ): string {
		return '' === $mysql ? '' : str_replace( ' ', 'T', $mysql ) . 'Z';
	}

	/**
	 * A response that is never cached.
	 *
	 * @param mixed $data Data.
	 * @return WP_REST_Response
	 */
	private static function no_store( $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
