<?php
/**
 * Tests for the settings REST controller.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Plugin;
use Chatlume\REST\SettingsController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Permissions, route registration, and updates.
 */
class SettingsControllerTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	public function test_capability_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Plugin::capability() );
	}

	public function test_capability_is_filterable_and_guarded(): void {
		Filters\expectApplied( 'chatlume_capability' )->once()->andReturn( 'edit_pages' );
		$this->assertSame( 'edit_pages', Plugin::capability() );

		Filters\expectApplied( 'chatlume_capability' )->once()->andReturn( '' );
		$this->assertSame( 'manage_options', Plugin::capability() );
	}

	public function test_permissions_check_uses_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->assertTrue( ( new SettingsController() )->permissions_check() );
	}

	public function test_permissions_check_denies_other_users(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = ( new SettingsController() )->permissions_check();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
	}

	public function test_registers_read_and_write_routes_with_permissions_and_args(): void {
		$captured = null;
		Functions\expect( 'register_rest_route' )->once()->andReturnUsing(
			static function ( $route_namespace, $route, $endpoints ) use ( &$captured ) {
				$captured = array( $route_namespace, $route, $endpoints );
				return true;
			}
		);

		$controller = new SettingsController();
		$controller->register_routes();

		list( $namespace, $route, $endpoints ) = $captured;

		$this->assertSame( 'chatlume/v1', $namespace );
		$this->assertSame( '/settings', $route );
		$this->assertCount( 2, $endpoints );

		foreach ( $endpoints as $endpoint ) {
			$this->assertSame( array( $controller, 'permissions_check' ), $endpoint['permission_callback'] );
			$this->assertArrayHasKey( 'args', $endpoint );
		}

		$this->assertSame( 'GET', $endpoints[0]['methods'] );
		$this->assertSame( array( 'setup', 'privacy', 'providers', 'chatbot', 'quick_answers', 'security', 'costs', 'cache', 'display', 'streaming', 'debug', 'knowledge', 'leads', 'handoff' ), array_keys( $endpoints[1]['args'] ) );
	}

	public function test_get_item_returns_settings(): void {
		$response = ( new SettingsController() )->get_item();

		$data = $response->get_data();

		$this->assertSame( array( 'setup', 'privacy', 'providers', 'chatbot', 'quick_answers', 'security', 'costs', 'cache', 'display', 'streaming', 'debug', 'knowledge', 'leads', 'handoff' ), array_keys( $data ) );
		$this->assertFalse( $data['privacy']['delete_data_on_uninstall'] );
		// Settings never contain API keys (they are stored separately).
		$this->assertStringNotContainsString( 'api_key', (string) wp_json_encode( $data ) );
	}

	public function test_update_item_saves_partial_update(): void {
		$request  = new WP_REST_Request( array( 'privacy' => array( 'delete_data_on_uninstall' => true ) ) );
		$response = ( new SettingsController() )->update_item( $request );

		$this->assertTrue( $response->get_data()['privacy']['delete_data_on_uninstall'] );
		$this->assertTrue( $this->options[ Settings::OPTION ]['privacy']['delete_data_on_uninstall'] );
	}

	public function test_update_item_returns_400_for_invalid_data(): void {
		$request = new WP_REST_Request( array( 'privacy' => array( 'nope' => true ) ) );
		$result  = ( new SettingsController() )->update_item( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}
}
