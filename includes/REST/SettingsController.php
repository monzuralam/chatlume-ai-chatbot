<?php
/**
 * REST: settings.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Plugin;
use Chatlume\Settings\Settings;
use Chatlume\Settings\SettingsSchema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET/POST chatlume/v1/settings (admin only).
 */
class SettingsController {

	/**
	 * Route base.
	 */
	public const ROUTE = '/settings';

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => SettingsSchema::rest_args(),
				),
			)
		);
	}

	/**
	 * Only users with the Chatlume capability (filterable) may read or change settings.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( Plugin::current_user_can_manage() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage Chatlume settings.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns all settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item(): WP_REST_Response {
		return new WP_REST_Response( Settings::get_all() );
	}

	/**
	 * Applies a partial update and returns all settings.
	 *
	 * @param WP_REST_Request $request Request. Section arguments are already validated and sanitized.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$partial = array();

		foreach ( array_keys( SettingsSchema::definition() ) as $section ) {
			$value = $request->get_param( $section );
			if ( is_array( $value ) ) {
				$partial[ $section ] = $value;
			}
		}

		$result = Settings::update( $partial );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( $result );
	}
}
