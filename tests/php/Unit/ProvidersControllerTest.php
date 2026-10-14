<?php
/**
 * Tests for the providers REST controller.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Providers\ApiKeys;
use Chatlume\Providers\ProviderRegistry;
use Chatlume\REST\ProvidersController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Routes, permissions, safe output, tests, and key handling.
 */
class ProvidersControllerTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'wp_salt' )->alias(
			static function ( $scheme = 'auth' ) {
				return 'salt-' . $scheme;
			}
		);
	}

	public function test_routes_require_the_capability(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $endpoints ) use ( &$routes ) {
				$routes[ $route ] = isset( $endpoints['methods'] ) ? array( $endpoints ) : $endpoints;
				return true;
			}
		);

		$controller = new ProvidersController();
		$controller->register_routes();

		$this->assertSame(
			array( '/providers', '/providers/(?P<id>[a-z0-9-]+)/models', '/providers/(?P<id>[a-z0-9-]+)/test', '/providers/(?P<id>[a-z0-9-]+)/key' ),
			array_keys( $routes )
		);
		foreach ( $routes as $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( array( $controller, 'permissions_check' ), $endpoint['permission_callback'] );
				$this->assertArrayHasKey( 'args', $endpoint );
			}
		}

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, $controller->permissions_check() );
	}

	public function test_validates_provider_ids_and_sanitizes_input(): void {
		$controller = new ProvidersController();

		$this->assertTrue( $controller->validate_provider_id( 'openai' ) );
		$this->assertInstanceOf( WP_Error::class, $controller->validate_provider_id( 'nope' ) );
		$this->assertSame( 'sk-abc123', $controller->sanitize_api_key( " sk-abc 123\n" ) );
		$this->assertSame( 'vendor/model:tag', $controller->sanitize_model( 'vendor/model:tag<>' ) );
		$this->assertTrue( $controller->validate_optional_url( '' ) );
		$this->assertTrue( $controller->validate_optional_url( 'http://127.0.0.1:11434/v1' ) );
		$this->assertInstanceOf( WP_Error::class, $controller->validate_optional_url( 'file:///etc/passwd' ) );
	}

	public function test_providers_list_never_contains_keys(): void {
		ApiKeys::save( 'openai', 'sk-very-secret-value-9876' );

		$data = ( new ProvidersController() )->get_providers()->get_data();
		$json = (string) wp_json_encode( $data );

		$this->assertStringNotContainsString( 'very-secret', $json );
		$this->assertCount( 11, $data['providers'] );

		$openai = $data['providers'][0];
		$this->assertSame( 'openai', $openai['id'] );
		$this->assertSame( 'settings', $openai['key']['source'] );
		$this->assertSame( '••••••••9876', $openai['key']['masked'] );
		$this->assertSame( 'untested', $openai['state'] );
		$this->assertSame( 'needs_key', $data['providers'][1]['state'] );
		$this->assertSame( 'needs_url', $data['providers'][10]['state'], 'The custom endpoint needs a base URL.' );
		$this->assertArrayHasKey( 'primary', $data['roles'] );
	}

	public function test_connection_test_records_status_only_for_saved_settings(): void {
		$this->queue_response( 401, array( 'error' => array( 'message' => 'Incorrect API key provided' ) ) );
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) ) );
		ApiKeys::save( 'openai', 'sk-saved' );

		$controller = new ProvidersController();

		$result = $controller->test_provider(
			new WP_REST_Request(
				array(
					'id'      => 'openai',
					'model'   => 'm',
					'api_key' => 'sk-unsaved',
				)
			)
		)->get_data();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'Bearer sk-unsaved', $this->http_requests[0][1]['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'openai', ProviderRegistry::last_tests(), 'Unsaved keys do not change the status.' );

		$result = $controller->test_provider(
			new WP_REST_Request(
				array(
					'id'    => 'openai',
					'model' => 'm',
				)
			)
		)->get_data();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Bearer sk-saved', $this->http_requests[1][1]['headers']['Authorization'] );
		$this->assertTrue( ProviderRegistry::last_tests()['openai']['ok'] );
	}

	public function test_saved_base_url_sent_again_still_records_status(): void {
		$this->options[ Settings::OPTION ] = array(
			'providers' => array( 'configs' => array( 'custom' => array( 'base_url' => 'http://llm.test/v1' ) ) ),
		);
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) ) );

		( new ProvidersController() )->test_provider(
			new WP_REST_Request(
				array(
					'id'       => 'custom',
					'model'    => 'm',
					'base_url' => 'http://llm.test/v1/',
				)
			)
		);

		$this->assertTrue( ProviderRegistry::last_tests()['custom']['ok'] );
	}

	public function test_providers_links_are_an_object_even_when_empty(): void {
		$data   = ( new ProvidersController() )->get_providers()->get_data();
		$custom = $data['providers'][10];

		$this->assertSame( 'custom', $custom['id'] );
		$this->assertSame( '{}', wp_json_encode( $custom['links'] ) );
	}

	public function test_models_route_filters_by_kind_and_maps_errors(): void {
		$this->queue_response( 200, array( 'data' => array( array( 'id' => 'chat-1' ), array( 'id' => 'text-embedding-1' ) ) ) );

		$controller = new ProvidersController();
		$data       = $controller->get_models(
			new WP_REST_Request(
				array(
					'id'      => 'custom',
					'refresh' => true,
					'kind'    => 'embedding',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $data, 'The custom endpoint has no base URL yet.' );
		$this->assertSame( 400, $data->get_error_data()['status'] );

		ApiKeys::save( 'openai', 'sk-x' );
		$data = $controller->get_models(
			new WP_REST_Request(
				array(
					'id'      => 'openai',
					'refresh' => true,
					'kind'    => 'embedding',
				)
			)
		)->get_data();
		$this->assertSame( array( 'text-embedding-1' ), array_column( $data['models'], 'id' ) );

		$this->queue_response( 401, array( 'error' => array( 'message' => 'Incorrect API key provided' ) ) );
		$error = $controller->get_models(
			new WP_REST_Request(
				array(
					'id'      => 'openai',
					'refresh' => true,
					'kind'    => 'all',
				)
			)
		);
		$this->assertSame( 'chatlume_provider_invalid_key', $error->get_error_code() );
		$this->assertSame( 502, $error->get_error_data()['status'] );
		$this->assertSame( 'invalid_key', $error->get_error_data()['provider']['type'] );
	}

	public function test_save_and_delete_key_return_only_masked_status(): void {
		$controller = new ProvidersController();

		$saved = $controller->save_key(
			new WP_REST_Request(
				array(
					'id'      => 'groq',
					'api_key' => 'gsk_live_secret_1111',
				)
			)
		)->get_data();
		$this->assertSame( '••••••••1111', $saved['key']['masked'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $saved ) );

		$deleted = $controller->delete_key( new WP_REST_Request( array( 'id' => 'groq' ) ) )->get_data();
		$this->assertSame( 'none', $deleted['key']['source'] );
	}
}
