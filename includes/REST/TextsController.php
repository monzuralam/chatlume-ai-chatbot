<?php
/**
 * REST: the Widget texts page.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Compat\Multilingual;
use Chatlume\Frontend\WidgetStrings;
use Chatlume\Frontend\WidgetTexts;
use Chatlume\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and saves the widget texts of one language at a time.
 */
class TextsController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$language = array(
			'type'              => 'string',
			'maxLength'         => 20,
			'pattern'           => '^[a-z0-9_-]*$',
			'default'           => '',
			'validate_callback' => array( $this, 'validate_language' ),
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/texts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array( 'language' => $language ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'language' => array_merge( $language, array( 'required' => true ) ),
						'labels'   => array(
							'type'     => 'object',
							'default'  => array(),
							'maxItems' => 200,
						),
						'content'  => array(
							'type'    => 'object',
							'default' => array(),
						),
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
			__( 'Sorry, you are not allowed to change the widget texts.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Accepts '' (the main language) or one of the site's languages.
	 *
	 * @param mixed           $value   Value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter.
	 * @return true|WP_Error
	 */
	public function validate_language( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( '' === $value || Multilingual::known( (string) $value ) ) {
			return true;
		}

		return new WP_Error( 'rest_invalid_param', __( 'This language is not one of your site’s languages.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
	}

	/**
	 * GET /texts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response {
		return self::state( self::code( $request ) );
	}

	/**
	 * POST /texts: replaces one language's texts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response {
		$code = self::code( $request );
		WidgetTexts::save( $code, $request->get_param( 'labels' ), $request->get_param( 'content' ) );

		return self::state( $code );
	}

	/**
	 * Requested language, or the main one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function code( WP_REST_Request $request ): string {
		$code = (string) $request->get_param( 'language' );

		return Multilingual::known( $code ) ? $code : Multilingual::main();
	}

	/**
	 * Everything the page shows for a language.
	 *
	 * @param string $code Language code.
	 * @return WP_REST_Response
	 */
	private static function state( string $code ): WP_REST_Response {
		$stored = WidgetTexts::stored( $code );
		$main   = Multilingual::main();

		$labels = array();
		foreach ( array_diff_key( self::defaults( $code ), array_flip( WidgetTexts::FIXED_LABELS ) ) as $key => $default ) {
			$labels[ $key ] = array(
				'default' => $default,
				'value'   => $stored['labels'][ $key ] ?? '',
			);
		}

		$content = array();
		if ( $main !== $code ) {
			foreach ( WidgetTexts::sources() as $source ) {
				$content[] = $source + array( 'value' => $stored['content'][ $source['path'] ] ?? '' );
			}
		}

		$response = new WP_REST_Response(
			array(
				'plugin'    => Multilingual::plugin(),
				'languages' => Multilingual::languages(),
				'main'      => $main,
				'language'  => $code,
				'labels'    => $labels,
				'content'   => $content,
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Built-in labels as visitors of a language see them.
	 *
	 * @param string $code Language code.
	 * @return array<string, string>
	 */
	private static function defaults( string $code ): array {
		$locale   = Multilingual::locale( $code );
		$switched = Multilingual::active() && '' !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale );
		$defaults = WidgetStrings::all();
		if ( $switched ) {
			restore_previous_locale();
		}

		return $defaults;
	}
}
