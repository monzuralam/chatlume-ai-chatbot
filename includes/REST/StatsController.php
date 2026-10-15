<?php
/**
 * REST: Dashboard statistics.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Chat\CostGuard;
use Chatlume\Data\ConversationLog;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Data\Stats;
use Chatlume\Plugin;
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
 * GET chatlume/v1/stats?days=1|7|30: totals, a daily series, money saved,
 * unanswered questions, recent conversations, budget usage, and the setup
 * checklist.
 */
class StatsController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'default'           => 7,
						'enum'              => array( 1, 7, 30 ),
						'sanitize_callback' => 'absint',
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
			__( 'Sorry, you are not allowed to view Chatlume statistics.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /stats.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response {
		$log      = new ConversationLog();
		$recent   = $log->search( array(), 1, 5 );
		$usage    = ( new CostGuard() )->usage();
		$response = ( new Stats() )->summary( (int) $request->get_param( 'days' ) );

		$response['recent']    = $recent['items'];
		$response['budget']    = array(
			'unit'         => (string) Settings::get( 'costs.budget_unit', 'currency' ),
			'daily'        => (float) Settings::get( 'costs.daily_budget', 0 ),
			'monthly'      => (float) Settings::get( 'costs.monthly_budget', 0 ),
			'day_tokens'   => $usage['day_tokens'],
			'month_tokens' => $usage['month_tokens'],
			'day_cost'     => round( $usage['day_cost'], 6 ),
			'month_cost'   => round( $usage['month_cost'], 6 ),
		);
		$response['checklist'] = self::checklist( $recent['total'] > 0 );

		$response = new WP_REST_Response( $response );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Setup steps, done or not. The app links each step to its page.
	 *
	 * @param bool $has_conversations Whether visitors have chatted.
	 * @return array<int, array{id: string, done: bool, route: string}>
	 */
	public static function checklist( bool $has_conversations ): array {
		$chatbot      = (array) Settings::get( 'chatbot', array() );
		$personalized = 'support' !== ( $chatbot['preset'] ?? 'support' );
		foreach ( array( 'bot_name', 'welcome_message', 'system_prompt' ) as $field ) {
			if ( '' !== trim( (string) ( $chatbot[ $field ] ?? '' ) ) ) {
				$personalized = true;
			}
		}

		$quick_answers = ( new QuickAnswerRepository() )->search( array( 'per_page' => 1 ) );

		return array(
			array(
				'id'    => 'provider',
				'done'  => '' !== ProviderRegistry::role( 'primary' )['provider'],
				'route' => '/providers',
			),
			array(
				'id'    => 'personalize',
				'done'  => $personalized,
				'route' => '/chatbot',
			),
			array(
				'id'    => 'widget',
				'done'  => (bool) Settings::get( 'display.enabled', true ),
				'route' => '/display',
			),
			array(
				'id'    => 'quick_answers',
				'done'  => (int) $quick_answers['total'] > 0,
				'route' => '/knowledge',
			),
			array(
				'id'    => 'first_chat',
				'done'  => $has_conversations,
				'route' => '/conversations',
			),
		);
	}
}
