<?php
/**
 * REST: providers, models, connection tests, and API keys.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Plugin;
use Chatlume\Providers\AbstractProvider;
use Chatlume\Providers\ApiKeys;
use Chatlume\Providers\CoreAiProvider;
use Chatlume\Providers\ProviderError;
use Chatlume\Providers\ProviderRegistry;
use Chatlume\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-only routes under chatlume/v1:
 * - GET    /providers                 Providers with status, safe key info, and roles.
 * - GET    /providers/{id}/models     Live model list (12-hour cache; refresh=true bypasses it).
 * - POST   /providers/{id}/test       Connection test (optionally with an unsaved key, URL, or model).
 * - POST   /providers/{id}/key        Save an API key (encrypted).
 * - DELETE /providers/{id}/key        Remove the saved API key.
 *
 * Provider settings and roles are saved through POST /settings. API keys are
 * accepted here but never returned: responses only contain the last four characters.
 */
class ProvidersController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );
		$id_arg     = array(
			'id' => array(
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_provider_id' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/providers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_providers' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/providers/(?P<id>[a-z0-9-]+)/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_models' ),
				'permission_callback' => $permission,
				'args'                => $id_arg + array(
					'refresh' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'kind'    => array(
						'type'              => 'string',
						'default'           => 'all',
						'enum'              => array( 'all', 'chat', 'embedding' ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/providers/(?P<id>[a-z0-9-]+)/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => $permission,
				'args'                => $id_arg + array(
					'model'    => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 200,
						'sanitize_callback' => array( $this, 'sanitize_model' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'api_key'  => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 500,
						'sanitize_callback' => array( $this, 'sanitize_api_key' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'base_url' => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 500,
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => array( $this, 'validate_optional_url' ),
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/providers/(?P<id>[a-z0-9-]+)/key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_key' ),
					'permission_callback' => $permission,
					'args'                => $id_arg + array(
						'api_key' => array(
							'type'              => 'string',
							'required'          => true,
							'minLength'         => 1,
							'maxLength'         => 500,
							'sanitize_callback' => array( $this, 'sanitize_api_key' ),
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_key' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
			)
		);
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
			__( 'Sorry, you are not allowed to manage Chatlume providers.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Validates a provider ID against the registry.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public function validate_provider_id( $value ) {
		if ( is_string( $value ) && in_array( $value, ProviderRegistry::ids(), true ) ) {
			return true;
		}

		return new WP_Error( 'rest_invalid_param', __( 'Unknown provider.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
	}

	/**
	 * Validates an optional http(s) URL.
	 *
	 * @param mixed $value Value.
	 * @return true|WP_Error
	 */
	public function validate_optional_url( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The base URL must be text.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$value = trim( $value );
		if ( '' === $value || ( false !== filter_var( $value, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) ) {
			return true;
		}

		return new WP_Error( 'rest_invalid_param', __( 'The base URL must start with http:// or https://.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
	}

	/**
	 * Keeps only characters that appear in model IDs.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function sanitize_model( $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9._:\/@+\-]/', '', trim( (string) $value ) );
	}

	/**
	 * API keys are opaque tokens: printable ASCII without spaces.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function sanitize_api_key( $value ): string {
		return (string) preg_replace( '/[^\x21-\x7E]/', '', (string) $value );
	}

	/**
	 * All providers with status and roles.
	 *
	 * @return WP_REST_Response
	 */
	public function get_providers(): WP_REST_Response {
		$tests     = ProviderRegistry::last_tests();
		$providers = array();

		foreach ( ProviderRegistry::all() as $id => $provider ) {
			$info = $provider instanceof AbstractProvider ? $provider->get_info() : array(
				'description'       => '',
				'links'             => array(),
				'key_required'      => true,
				'default_base_url'  => '',
				'base_url_editable' => false,
				'initials'          => strtoupper( substr( $id, 0, 2 ) ),
				'kind'              => 'extension',
			);

			$key    = ApiKeys::status( $id );
			$config = Settings::get( 'providers.configs.' . $id, array() );
			$test   = $tests[ $id ] ?? null;

			if ( $provider instanceof CoreAiProvider ) {
				// Connected in Settings > Connectors: WordPress holds the key.
				$state = ! $provider->is_connected() ? 'needs_connector' : ( ! is_array( $test ) ? 'untested' : ( empty( $test['ok'] ) ? 'error' : 'ready' ) );
			} elseif ( ! $key['readable'] ) {
				$state = 'key_unreadable';
			} elseif ( $info['key_required'] && 'none' === $key['source'] ) {
				$state = 'needs_key';
			} elseif ( ! $info['key_required'] && '' === ( $info['default_base_url'] ?? '' ) && '' === (string) ( $config['base_url'] ?? '' ) ) {
				$state = 'needs_url';
			} elseif ( ! is_array( $test ) ) {
				$state = 'untested';
			} else {
				$state = empty( $test['ok'] ) ? 'error' : 'ready';
			}

			$providers[] = array(
				'id'                => $id,
				'label'             => $provider->get_label(),
				'description'       => $info['description'],
				'initials'          => $info['initials'],
				'kind'              => $info['kind'],
				// An object even when empty (an empty PHP array would become a JSON list).
				'links'             => (object) $info['links'],
				'key_required'      => $info['key_required'],
				'default_base_url'  => $info['default_base_url'],
				'base_url_editable' => $info['base_url_editable'],
				'supports'          => array(
					'streaming'            => $provider->supports( 'streaming' ),
					'embeddings'           => $provider->supports( 'embeddings' ),
					'embedding_dimensions' => $provider->supports( 'embedding_dimensions' ),
					'tools'                => $provider->supports( 'tools' ),
				),
				'fields'            => array_keys( $provider->get_settings_schema() ),
				'key'               => $key,
				'state'             => $state,
				'last_test'         => $test,
			);
		}

		return new WP_REST_Response(
			array(
				'providers' => $providers,
				'roles'     => array(
					'primary'    => ProviderRegistry::role( 'primary' ),
					'fallback'   => ProviderRegistry::role( 'fallback' ),
					'embeddings' => ProviderRegistry::role( 'embeddings' ),
				),
			)
		);
	}

	/**
	 * Live models for a provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_models( WP_REST_Request $request ) {
		$provider = ProviderRegistry::get( (string) $request->get_param( 'id' ) );

		try {
			$models = $provider instanceof AbstractProvider
				? $provider->list_models( (bool) $request->get_param( 'refresh' ) )
				: $provider->list_models();
		} catch ( ProviderError $error ) {
			return self::error_response( $error );
		}

		$kind = (string) $request->get_param( 'kind' );
		if ( 'all' !== $kind ) {
			$models = array_values(
				array_filter(
					$models,
					static function ( $model ) use ( $kind ) {
						return ( $model['kind'] ?? '' ) === $kind;
					}
				)
			);
		}

		return new WP_REST_Response( array( 'models' => $models ) );
	}

	/**
	 * Tests a provider with its saved settings, or with values not saved yet.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_provider( WP_REST_Request $request ): WP_REST_Response {
		$id        = (string) $request->get_param( 'id' );
		$overrides = array();

		if ( '' !== (string) $request->get_param( 'api_key' ) ) {
			$overrides['api_key']   = (string) $request->get_param( 'api_key' );
			$overrides['key_error'] = false;
		}
		$base_url   = untrailingslashit( (string) $request->get_param( 'base_url' ) );
		$saved_base = untrailingslashit( (string) Settings::get( 'providers.configs.' . $id . '.base_url', '' ) );
		// A URL is an override only when it differs from the saved one (the panel always sends its current value).
		if ( '' !== $base_url && $base_url !== $saved_base ) {
			$overrides['base_url'] = $base_url;
		}
		if ( '' !== (string) $request->get_param( 'model' ) ) {
			$overrides['default_model'] = (string) $request->get_param( 'model' );
		}

		$provider = ProviderRegistry::get( $id, $overrides );
		$result   = $provider->test_connection();

		// Only tests of the saved configuration describe the provider's status.
		if ( ! isset( $overrides['api_key'] ) && ! isset( $overrides['base_url'] ) ) {
			ProviderRegistry::record_test( $id, $result );
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Saves an API key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_key( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ProviderRegistry::get( $id ) instanceof CoreAiProvider ) {
			return new WP_Error( 'chatlume_core_provider', __( 'This provider uses the key saved in Settings > Connectors.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		$result = ApiKeys::save( $id, (string) $request->get_param( 'api_key' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'key' => ApiKeys::status( $id ) ) );
	}

	/**
	 * Removes a saved API key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_key( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		ApiKeys::delete( $id );

		return new WP_REST_Response( array( 'key' => ApiKeys::status( $id ) ) );
	}

	/**
	 * REST error for a provider failure, with the admin explanation attached.
	 *
	 * @param ProviderError $error Error.
	 * @return WP_Error
	 */
	private static function error_response( ProviderError $error ): WP_Error {
		$details = $error->to_array();

		return new WP_Error(
			'chatlume_provider_' . $error->type(),
			$error->admin_title(),
			array(
				// 502: the upstream provider failed; the request itself was valid.
				'status'   => ProviderError::NOT_CONFIGURED === $error->type() ? 400 : 502,
				'provider' => $details,
			)
		);
	}
}
