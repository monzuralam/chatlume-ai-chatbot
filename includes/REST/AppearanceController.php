<?php
/**
 * REST: the widget Customizer.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Appearance\AppearanceStore;
use Chatlume\Appearance\FontCatalog;
use Chatlume\Appearance\FontUploads;
use Chatlume\Appearance\PreviewMode;
use Chatlume\Appearance\SiteBrand;
use Chatlume\Appearance\ThemeSchema;
use Chatlume\Appearance\TokenResolver;
use Chatlume\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /appearance, POST and DELETE /appearance/draft, POST
 * /appearance/publish, GET /appearance/versions, and POST
 * /appearance/versions/{id}/restore. Admin only.
 */
class AppearanceController {

	/**
	 * Largest accepted theme, in bytes of JSON.
	 */
	private const MAX_THEME_BYTES = 32768;

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/draft',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $permission,
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_draft' ),
					'permission_callback' => $permission,
					'args'                => array(
						'theme' => array(
							'type'              => 'object',
							'required'          => true,
							'validate_callback' => array( self::class, 'validate_theme' ),
							'sanitize_callback' => array( ThemeSchema::class, 'sanitize' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'discard' ),
					'permission_callback' => $permission,
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'publish' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/preview-token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_token' ),
				'permission_callback' => $permission,
				'args'                => array(
					'mode' => array(
						'type'              => 'string',
						'default'           => 'frame',
						'enum'              => PreviewMode::MODES,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/brand',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_brand' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_presets' ),
					'permission_callback' => $permission,
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_preset' ),
					'permission_callback' => $permission,
					'args'                => array(
						'name'  => array(
							'type'              => 'string',
							'required'          => true,
							'minLength'         => 1,
							'maxLength'         => 40,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'theme' => array(
							'type'              => 'object',
							'required'          => true,
							'validate_callback' => array( self::class, 'validate_theme' ),
							'sanitize_callback' => array( ThemeSchema::class, 'sanitize' ),
						),
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/presets/(?P<id>[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_preset' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[a-f0-9]{12}$',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/fonts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_font' ),
				'permission_callback' => $permission,
				'args'                => array(
					'name'   => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 60,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'weight' => array(
						'type'              => 'string',
						'default'           => '400',
						'enum'              => FontUploads::WEIGHTS,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'style'  => array(
						'type'              => 'string',
						'default'           => 'normal',
						'enum'              => array( 'normal', 'italic' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/fonts/(?P<id>[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_font' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[a-f0-9]{12}$',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/versions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_versions' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/appearance/versions/(?P<id>[a-f0-9-]{36})/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[a-f0-9-]{36}$',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
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
			__( 'Sorry, you are not allowed to change the widget appearance.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * A theme must be an object of reasonable size.
	 *
	 * @param mixed $value Theme.
	 * @return true|WP_Error
	 */
	public static function validate_theme( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'chatlume_invalid_theme', __( 'The appearance settings are not valid.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		if ( strlen( (string) wp_json_encode( $value ) ) > self::MAX_THEME_BYTES ) {
			return new WP_Error( 'chatlume_theme_too_large', __( 'The appearance settings are too large.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * GET /appearance: draft, published theme, defaults, and versions.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item(): WP_REST_Response {
		return self::state();
	}

	/**
	 * POST /appearance/draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_draft( WP_REST_Request $request ): WP_REST_Response {
		AppearanceStore::save_draft( (array) $request->get_param( 'theme' ) );

		return self::state();
	}

	/**
	 * DELETE /appearance/draft.
	 *
	 * @return WP_REST_Response
	 */
	public function discard(): WP_REST_Response {
		AppearanceStore::discard();

		return self::state();
	}

	/**
	 * POST /appearance/publish.
	 *
	 * @return WP_REST_Response
	 */
	public function publish(): WP_REST_Response {
		AppearanceStore::publish();

		return self::state();
	}

	/**
	 * GET /appearance/versions.
	 *
	 * @return WP_REST_Response
	 */
	public function get_versions(): WP_REST_Response {
		return self::no_store( new WP_REST_Response( AppearanceStore::versions() ) );
	}

	/**
	 * POST /appearance/versions/{id}/restore: loads a version into the draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		if ( null === AppearanceStore::restore( (string) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'chatlume_not_found', __( 'This version no longer exists.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return self::state();
	}

	/**
	 * POST /appearance/preview-token: a 30-minute token for a draft preview
	 * on the site, valid only for the current admin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function preview_token( WP_REST_Request $request ): WP_REST_Response {
		$token = PreviewMode::token( get_current_user_id(), (string) $request->get_param( 'mode' ) );

		return self::no_store(
			new WP_REST_Response(
				array(
					'param'   => PreviewMode::PARAM,
					'token'   => $token['token'],
					'expires' => $token['expires'],
				)
			)
		);
	}

	/**
	 * GET /appearance/brand: the site's icon, logo, palette, and fonts.
	 *
	 * @return WP_REST_Response
	 */
	public function get_brand(): WP_REST_Response {
		return self::no_store( new WP_REST_Response( SiteBrand::detect() ) );
	}

	/**
	 * GET /appearance/presets.
	 *
	 * @return WP_REST_Response
	 */
	public function get_presets(): WP_REST_Response {
		return self::no_store( new WP_REST_Response( AppearanceStore::presets() ) );
	}

	/**
	 * POST /appearance/presets: saves a theme as a preset.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_preset( WP_REST_Request $request ) {
		$preset = AppearanceStore::save_preset( (string) $request->get_param( 'name' ), (array) $request->get_param( 'theme' ) );
		if ( null === $preset ) {
			return new WP_Error( 'chatlume_preset_limit', __( 'You can keep up to 20 presets. Delete one first.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		return self::state();
	}

	/**
	 * DELETE /appearance/presets/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_preset( WP_REST_Request $request ) {
		if ( ! AppearanceStore::delete_preset( (string) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'chatlume_not_found', __( 'This preset no longer exists.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return self::state();
	}

	/**
	 * POST /appearance/fonts: a woff2 upload (multipart field "font").
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_font( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['font'] ) || ! is_array( $files['font'] ) ) {
			return new WP_Error( 'chatlume_font_upload_failed', __( 'Choose a .woff2 font file to upload.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$font = FontUploads::store(
			$files['font'],
			(string) $request->get_param( 'name' ),
			(string) $request->get_param( 'weight' ),
			(string) $request->get_param( 'style' )
		);
		if ( is_wp_error( $font ) ) {
			return $font;
		}

		return self::state();
	}

	/**
	 * DELETE /appearance/fonts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_font( WP_REST_Request $request ) {
		if ( ! FontUploads::delete( (string) $request->get_param( 'id' ) ) ) {
			return new WP_Error( 'chatlume_not_found', __( 'This font no longer exists.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return self::state();
	}

	/**
	 * The Customizer's state.
	 *
	 * @return WP_REST_Response
	 */
	private static function state(): WP_REST_Response {
		return self::no_store(
			new WP_REST_Response(
				array(
					'draft'       => AppearanceStore::draft(),
					'published'   => TokenResolver::published_theme(),
					'has_changes' => AppearanceStore::has_changes(),
					'defaults'    => ThemeSchema::defaults(),
					'versions'    => AppearanceStore::versions(),
					'presets'     => AppearanceStore::presets(),
					'fonts'       => array(
						'options'     => array_values( FontCatalog::options() ),
						'uploaded'    => FontCatalog::uploaded(),
						'site_script' => FontCatalog::site_script(),
					),
				)
			)
		);
	}

	/**
	 * Never cached.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
