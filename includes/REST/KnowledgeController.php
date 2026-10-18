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
use Chatlume\Knowledge\Embeddings;
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
 * a full rebuild. GET chatlume/v1/knowledge/sources: what is indexed. The
 * chosen sources themselves are saved through /settings.
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
		return array(
			'index'      => ( new KnowledgeRepository() )->stats(),
			'queue'      => array(
				'status'   => (string) $queue['status'],
				'total'    => (int) $queue['total'],
				'done'     => (int) $queue['done'],
				'started'  => self::iso( (string) $queue['started'] ),
				'finished' => self::iso( (string) $queue['finished'] ),
			),
			'fulltext'   => 'yes' === get_option( Schema::FULLTEXT_OPTION, 'no' ),
			'embeddings' => self::embeddings(),
			'post_types' => DisplayController::post_types(),
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
