<?php
/**
 * REST: quick answers (admin).
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-only CRUD under chatlume/v1:
 * - GET    /quick-answers       List with search and pagination (X-WP-Total, X-WP-TotalPages).
 * - POST   /quick-answers       Create.
 * - GET    /quick-answers/{id}  Read.
 * - PATCH  /quick-answers/{id}  Update (any subset of fields).
 * - DELETE /quick-answers/{id}  Delete.
 */
class QuickAnswersController {

	/**
	 * Most rows in one import.
	 */
	public const MAX_IMPORT = 2000;

	/**
	 * Repository.
	 *
	 * @var QuickAnswerRepository
	 */
	private QuickAnswerRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param QuickAnswerRepository|null $repository Repository.
	 */
	public function __construct( ?QuickAnswerRepository $repository = null ) {
		$this->repository = $repository ?? new QuickAnswerRepository();
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/quick-answers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => $permission,
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 200,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'language' => array(
							'type'              => 'string',
							'maxLength'         => 20,
							'pattern'           => '^$|' . ChatController::LANGUAGE_PATTERN,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $permission,
					'args'                => self::fields( true ),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/quick-answers/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $permission,
					'args'                => self::id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $permission,
					'args'                => self::id_arg() + self::fields( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $permission,
					'args'                => self::id_arg(),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/quick-answers/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_items' ),
				'permission_callback' => $permission,
				'args'                => array(
					'rows' => array(
						'type'              => 'array',
						'required'          => true,
						'minItems'          => 1,
						'maxItems'          => self::MAX_IMPORT,
						'items'             => array( 'type' => 'object' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * POST /quick-answers/import: rows from a CSV file. A row whose question
	 * already exists in the same language updates that quick answer;
	 * invalid rows are skipped and reported with their row number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function import_items( WP_REST_Request $request ): WP_REST_Response {
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		foreach ( array_values( (array) $request->get_param( 'rows' ) ) as $index => $row ) {
			$values = self::import_row( is_array( $row ) ? $row : array() );
			if ( is_string( $values ) ) {
				++$result['skipped'];
				if ( count( $result['errors'] ) < 20 ) {
					$result['errors'][] = array(
						'row'     => $index + 1,
						'message' => $values,
					);
				}
				continue;
			}

			$existing = $this->repository->find_by_question( $values['question'], $values['language'] );
			if ( $existing > 0 ) {
				$this->repository->update( $existing, $values );
				++$result['updated'];
			} elseif ( $this->repository->create( $values ) > 0 ) {
				++$result['created'];
			} else {
				++$result['skipped'];
			}
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Clean values of one imported row, or why it was skipped.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>|string Values (keywords and enabled only when given), or why the row was skipped.
	 */
	private static function import_row( array $row ) {
		$question = mb_substr( sanitize_text_field( (string) ( $row['question'] ?? '' ) ), 0, 500 );
		$answer   = mb_substr( sanitize_textarea_field( (string) ( $row['answer'] ?? '' ) ), 0, 5000 );
		if ( '' === trim( $question ) || '' === trim( $answer ) ) {
			return __( 'A question and an answer are both needed.', 'chatlume-ai-chatbot' );
		}

		$language = str_replace( '_', '-', trim( sanitize_text_field( (string) ( $row['language'] ?? '' ) ) ) );
		if ( '' !== $language && ! preg_match( '/' . ChatController::LANGUAGE_PATTERN . '/', $language ) ) {
			return __( 'The language is not a valid language code, such as en or pt-BR.', 'chatlume-ai-chatbot' );
		}

		$values = array(
			'question' => trim( $question ),
			'answer'   => trim( $answer ),
			'language' => $language,
		);
		// Missing columns leave an existing quick answer's value unchanged.
		if ( array_key_exists( 'keywords', $row ) ) {
			$values['keywords'] = mb_substr( sanitize_text_field( (string) $row['keywords'] ), 0, 500 );
		}
		if ( array_key_exists( 'enabled', $row ) && '' !== $row['enabled'] ) {
			$enabled           = $row['enabled'];
			$values['enabled'] = is_string( $enabled )
				? ! in_array( strtolower( trim( $enabled ) ), array( '0', 'no', 'false', 'off' ), true )
				: (bool) $enabled;
		}

		return $values;
	}

	/**
	 * Admin capability (filterable through `chatlume_capability`).
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( Plugin::current_user_can_manage() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage quick answers.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /quick-answers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $this->repository->search(
			array(
				'search'   => (string) $request->get_param( 'search' ),
				'language' => $request->get_param( 'language' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => $per_page,
			)
		);

		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / max( 1, $per_page ) ) ) );

		return $response;
	}

	/**
	 * POST /quick-answers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$id = $this->repository->create( $this->values( $request ) );

		if ( $id <= 0 ) {
			return new WP_Error( 'chatlume_save_failed', __( 'The quick answer could not be saved.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( $this->repository->find( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * GET /quick-answers/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$item = $this->repository->find( (int) $request->get_param( 'id' ) );

		return null === $item ? self::not_found() : new WP_REST_Response( $item );
	}

	/**
	 * PATCH /quick-answers/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->repository->find( $id ) ) {
			return self::not_found();
		}

		$this->repository->update( $id, $this->values( $request ) );

		return new WP_REST_Response( $this->repository->find( $id ) );
	}

	/**
	 * DELETE /quick-answers/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->repository->delete( $id ) ) {
			return self::not_found();
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Values present in the request (only sent fields, for partial updates).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function values( WP_REST_Request $request ): array {
		$values = array();
		$params = array_merge( (array) $request->get_body_params(), (array) $request->get_json_params() );

		foreach ( array( 'question', 'answer', 'keywords', 'language', 'enabled' ) as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				// get_param() returns the sanitized value.
				$values[ $field ] = $request->get_param( $field );
			}
		}

		if ( isset( $values['language'] ) ) {
			$values['language'] = str_replace( '_', '-', (string) $values['language'] );
		}

		return $values;
	}

	/**
	 * Field arguments.
	 *
	 * @param bool $create Whether question and answer are required.
	 * @return array<string, array<string, mixed>>
	 */
	private static function fields( bool $create ): array {
		return array(
			'question' => array(
				'type'              => 'string',
				'required'          => $create,
				'minLength'         => 1,
				'maxLength'         => 500,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'answer'   => array(
				'type'              => 'string',
				'required'          => $create,
				'minLength'         => 1,
				'maxLength'         => 5000,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'keywords' => array(
				'type'              => 'string',
				'maxLength'         => 500,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'language' => array(
				'type'              => 'string',
				'maxLength'         => 20,
				'pattern'           => '^$|' . ChatController::LANGUAGE_PATTERN,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'enabled'  => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * ID argument.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * 404 error.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'chatlume_not_found', __( 'Quick answer not found.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
	}
}
