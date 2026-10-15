<?php
/**
 * REST: conversation log for the admin.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Data\ConversationLog;
use Chatlume\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin routes under chatlume/v1:
 * - GET    /conversations              Filtered, paginated list.
 * - DELETE /conversations              Bulk delete (ids).
 * - GET    /conversations/export       Conversations with messages for CSV/JSON export.
 * - GET    /conversations/{id}         One transcript.
 * - DELETE /conversations/{id}         Delete one conversation.
 */
class ConversationsController {

	/**
	 * Most conversations deleted per request.
	 */
	private const MAX_BULK = 500;

	/**
	 * Log.
	 *
	 * @var ConversationLog
	 */
	private ConversationLog $log;

	/**
	 * Constructor.
	 *
	 * @param ConversationLog|null $log Log (replaceable in tests).
	 */
	public function __construct( ?ConversationLog $log = null ) {
		$this->log = $log ?? new ConversationLog();
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$admin = array( $this, 'permissions_check' );

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => $admin,
					'args'                => self::filter_args() + array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 25,
							'minimum'           => 1,
							'maximum'           => ConversationLog::MAX_PER_PAGE,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => $admin,
					'args'                => array(
						'ids' => array(
							'type'              => 'array',
							'required'          => true,
							'minItems'          => 1,
							'maxItems'          => self::MAX_BULK,
							'items'             => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'sanitize_callback' => array( self::class, 'sanitize_ids' ),
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/conversations/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $admin,
				'args'                => self::filter_args(),
			)
		);

		$id = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/conversations/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $admin,
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $admin,
					'args'                => $id,
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
			__( 'Sorry, you are not allowed to view Chatlume conversations.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /conversations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$found    = $this->log->search( self::filters_from( $request ), $page, $per_page );

		return new WP_REST_Response(
			array(
				'items'     => $found['items'],
				'total'     => $found['total'],
				'page'      => $page,
				'per_page'  => $per_page,
				'languages' => $this->log->languages(),
			)
		);
	}

	/**
	 * GET /conversations/export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( $this->log->export( self::filters_from( $request ) ) );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * GET /conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$transcript = $this->log->transcript( (int) $request->get_param( 'id' ) );

		if ( null === $transcript ) {
			return new WP_Error( 'chatlume_not_found', __( 'This conversation could not be found. It may have been deleted.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $transcript );
	}

	/**
	 * DELETE /conversations/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		$deleted = $this->log->delete( array( (int) $request->get_param( 'id' ) ) );

		if ( 0 === $deleted ) {
			return new WP_Error( 'chatlume_not_found', __( 'This conversation could not be found. It may have been deleted.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'deleted' => $deleted ) );
	}

	/**
	 * DELETE /conversations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_items( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'deleted' => $this->log->delete( (array) $request->get_param( 'ids' ) ) ) );
	}

	/**
	 * Positive integer IDs.
	 *
	 * @param mixed $value Raw list.
	 * @return int[]
	 */
	public static function sanitize_ids( $value ): array {
		// Not absint(): it would turn -2 into 2, a different conversation.
		$ids = array_map( 'intval', is_array( $value ) ? $value : array() );

		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		);
	}

	/**
	 * Filter values from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, string>
	 */
	private static function filters_from( WP_REST_Request $request ): array {
		$filters = array();
		foreach ( array_keys( self::filter_args() ) as $name ) {
			$filters[ $name ] = (string) $request->get_param( $name );
		}

		return $filters;
	}

	/**
	 * Filter argument definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filter_args(): array {
		$date = array(
			'type'              => 'string',
			'default'           => '',
			'pattern'           => '^(\d{4}-\d{2}-\d{2})?$',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return array(
			'search'    => array(
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 200,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'date_from' => $date,
			'date_to'   => $date,
			'rating'    => array(
				'type'              => 'string',
				'default'           => 'any',
				'enum'              => array( 'any', 'up', 'down', 'none' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'source'    => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array_merge( array( '' ), ConversationLog::SOURCES ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'lead'      => array(
				'type'              => 'string',
				'default'           => 'any',
				'enum'              => array( 'any', 'yes', 'no' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'language'  => array(
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 20,
				'pattern'           => '^[A-Za-z0-9_-]*$',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}
}
