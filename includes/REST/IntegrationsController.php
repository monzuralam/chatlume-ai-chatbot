<?php
/**
 * REST: the Integrations page.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Engagement\Mailer;
use Chatlume\Integrations\Webhooks;
use Chatlume\Plugin;
use Chatlume\Providers\ApiKeys;
use Chatlume\Security\Turnstile;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin routes for webhooks (signing secret, test, delivery log), the
 * Turnstile secret key, and a test email. Settings themselves are saved
 * through /settings; secrets never are, and the Turnstile secret is only
 * ever returned masked.
 */
class IntegrationsController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permissions_check' );

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/integrations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/integrations/secret',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_secret' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/integrations/deliveries',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_deliveries' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/integrations/test-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_webhook' ),
				'permission_callback' => $permission,
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 500,
						'pattern'           => '^https?://\S+$',
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/integrations/test-email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_email' ),
				'permission_callback' => $permission,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/integrations/turnstile-key',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_turnstile_key' ),
					'permission_callback' => $permission,
					'args'                => array(
						'secret' => array(
							'type'              => 'string',
							'required'          => true,
							'minLength'         => 1,
							'maxLength'         => 200,
							'pattern'           => '^[A-Za-z0-9_-]+$',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_turnstile_key' ),
					'permission_callback' => $permission,
					'args'                => array(),
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
			__( 'Sorry, you are not allowed to manage integrations.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /integrations.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item(): WP_REST_Response {
		return self::no_store(
			array(
				'secret'      => Webhooks::secret(),
				'deliveries'  => Webhooks::deliveries(),
				'turnstile'   => ApiKeys::status( Turnstile::KEY_ID ),
				'team_email'  => Mailer::team_address(),
				'admin_email' => (string) get_option( 'admin_email', '' ),
			)
		);
	}

	/**
	 * POST /integrations/secret: a new signing secret.
	 *
	 * @return WP_REST_Response
	 */
	public function regenerate_secret(): WP_REST_Response {
		return self::no_store( array( 'secret' => Webhooks::regenerate_secret() ) );
	}

	/**
	 * DELETE /integrations/deliveries.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_deliveries(): WP_REST_Response {
		Webhooks::clear_log();

		return self::no_store( array( 'deliveries' => array() ) );
	}

	/**
	 * POST /integrations/test-webhook: sends a test event now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_webhook( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );
		if ( '' === $url ) {
			return new WP_Error( 'chatlume_invalid_url', __( 'Enter a web address starting with http:// or https://.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$result = ( new Webhooks() )->test( $url );

		return self::no_store( $result + array( 'deliveries' => Webhooks::deliveries() ) );
	}

	/**
	 * POST /integrations/test-email.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_email() {
		if ( ! Mailer::test() ) {
			return new WP_Error( 'chatlume_mail_failed', __( 'WordPress could not send the email. Check that your site can send email, for example with an SMTP plugin.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}

		return self::no_store( array( 'sent_to' => Mailer::team_address() ) );
	}

	/**
	 * POST /integrations/turnstile-key: stores the secret key encrypted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_turnstile_key( WP_REST_Request $request ) {
		$saved = ApiKeys::save( Turnstile::KEY_ID, (string) $request->get_param( 'secret' ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return self::no_store( array( 'turnstile' => ApiKeys::status( Turnstile::KEY_ID ) ) );
	}

	/**
	 * DELETE /integrations/turnstile-key.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_turnstile_key(): WP_REST_Response {
		ApiKeys::delete( Turnstile::KEY_ID );

		return self::no_store( array( 'turnstile' => ApiKeys::status( Turnstile::KEY_ID ) ) );
	}

	/**
	 * A response that is never cached.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return WP_REST_Response
	 */
	private static function no_store( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
