<?php
/**
 * REST: reference data for the Chatbot page.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Chat\BotText;
use Chatlume\Chat\CostGuard;
use Chatlume\Chat\PromptBuilder;
use Chatlume\Plugin;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET chatlume/v1/chatbot: the built-in texts per preset (shown as
 * placeholders, so empty settings keep using translated defaults), the
 * prompt variables, and current budget usage.
 */
class ChatbotController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/chatbot',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(),
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
			__( 'Sorry, you are not allowed to manage Chatlume.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /chatbot.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item(): WP_REST_Response {
		$usage = ( new CostGuard() )->usage();

		return new WP_REST_Response(
			array(
				'defaults'  => BotText::defaults(),
				'variables' => self::variables(),
				'usage'     => array(
					'day_tokens'   => $usage['day_tokens'],
					'month_tokens' => $usage['month_tokens'],
					'day_cost'     => round( $usage['day_cost'], 6 ),
					'month_cost'   => round( $usage['month_cost'], 6 ),
				),
			)
		);
	}

	/**
	 * Prompt variables with a short explanation each.
	 *
	 * @return array<int, array{name: string, description: string}>
	 */
	public static function variables(): array {
		$descriptions = array(
			'{site_name}'  => __( 'Your site title', 'chatlume-ai-chatbot' ),
			'{site_url}'   => __( 'Your site address', 'chatlume-ai-chatbot' ),
			'{page_title}' => __( 'Title of the page the visitor is on', 'chatlume-ai-chatbot' ),
			'{page_url}'   => __( 'Address of the page the visitor is on', 'chatlume-ai-chatbot' ),
			'{date}'       => __( 'Today’s date', 'chatlume-ai-chatbot' ),
			'{user_name}'  => __( 'Name of a logged-in visitor (turns off answer caching)', 'chatlume-ai-chatbot' ),
		);

		$variables = array();
		foreach ( PromptBuilder::VARIABLES as $name ) {
			$variables[] = array(
				'name'        => $name,
				'description' => $descriptions[ $name ] ?? '',
			);
		}

		return $variables;
	}
}
