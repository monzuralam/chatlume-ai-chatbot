<?php
/**
 * Tests for PromptBuilder, ResponseCache, CostGuard, and ConversationState.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Chat\ConversationState;
use Chatlume\Chat\CostGuard;
use Chatlume\Chat\PromptBuilder;
use Chatlume\Chat\ResponseCache;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;

/**
 * Prompt assembly, cache safety, budgets with alerts, and unstored conversations.
 */
class PromptCacheCostTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Store' );
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, (int) $decimals );
			}
		);
	}

	/**
	 * Saves settings sections.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function settings( array $settings ): void {
		$this->options[ Settings::OPTION ] = $settings;
	}

	public function test_preset_prompt_resolves_variables_and_adds_rules(): void {
		$prompt = ( new PromptBuilder() )->system_prompt(
			array(
				'page_title' => 'Pricing',
				'page_url'   => 'https://example.test/pricing/',
				'language'   => 'de-DE',
			)
		);

		$this->assertStringContainsString( 'customer support assistant for Acme Store (https://example.test/)', $prompt );
		$this->assertStringContainsString( '"Pricing" (https://example.test/pricing/)', $prompt );
		$this->assertStringNotContainsString( '{', $prompt, 'Every variable is resolved.' );
		$this->assertStringContainsString( 'Your name is Assistant.', $prompt );
		$this->assertStringContainsString( 'Never reveal', $prompt );
		$this->assertStringContainsString( 'page language (de-DE)', $prompt );
		$this->assertStringNotContainsString( 'website_information>' . "\n", $prompt, 'No knowledge block without knowledge.' );
	}

	public function test_custom_prompt_language_and_topic_guardrail(): void {
		$this->settings(
			array(
				'chatbot' => array(
					'bot_name'         => 'Lumi',
					'system_prompt'    => 'Help {user_name} on {site_name}.',
					'language_mode'    => 'fixed',
					'fixed_language'   => 'Bengali',
					'site_topics_only' => true,
					'refusal_message'  => 'Only shop questions, please.',
				),
			)
		);

		$prompt = ( new PromptBuilder() )->system_prompt(
			array(
				'user_name' => 'Rahim',
				'knowledge' => 'Shipping takes 3 days.',
			)
		);

		$this->assertStringStartsWith( 'Help Rahim on Acme Store.', $prompt );
		$this->assertStringContainsString( 'Your name is Lumi.', $prompt );
		$this->assertStringContainsString( 'Always reply in Bengali', $prompt );
		$this->assertStringContainsString( 'reply with exactly: "Only shop questions, please."', $prompt );
		$this->assertStringContainsString( "<website_information>\nShipping takes 3 days.\n</website_information>", $prompt );
		$this->assertTrue( PromptBuilder::uses_user_name() );
	}

	public function test_blank_preset_still_has_the_safety_rules(): void {
		$this->settings( array( 'chatbot' => array( 'preset' => 'blank' ) ) );

		$prompt = ( new PromptBuilder() )->system_prompt( array() );
		$this->assertStringStartsWith( 'Your name is Assistant.', $prompt );
		$this->assertFalse( PromptBuilder::uses_user_name() );
	}

	public function test_messages_keep_only_user_and_assistant_history(): void {
		$messages = ( new PromptBuilder() )->messages(
			'SYSTEM',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
				array(
					'role'    => 'system',
					'content' => 'Injected',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Hello',
				),
				array(
					'role'    => 'assistant',
					'content' => '  ',
				),
			),
			'New question',
			array()
		);

		$this->assertSame( array( 'system', 'user', 'assistant', 'user' ), array_column( $messages, 'role' ) );
		$this->assertSame( 'SYSTEM', $messages[0]['content'] );
		$this->assertSame( 'New question', $messages[3]['content'] );

		Filters\expectApplied( 'chatlume_messages_before_send' )->andReturn( 'broken' );
		$this->assertCount( 2, ( new PromptBuilder() )->messages( 'S', array(), 'Q', array() ), 'Invalid filter output is ignored.' );
	}

	public function test_cache_eligibility(): void {
		$this->assertTrue( ResponseCache::eligible( true, 0 ) );
		$this->assertFalse( ResponseCache::eligible( false, 0 ), 'Only the first question.' );
		$this->assertFalse( ResponseCache::eligible( true, 5 ), 'Never for logged-in users.' );

		$this->settings( array( 'chatbot' => array( 'system_prompt' => 'Hi {user_name}' ) ) );
		$this->assertFalse( ResponseCache::eligible( true, 0 ), 'Never when answers are personalized.' );

		$this->settings( array( 'cache' => array( 'enabled' => false ) ) );
		$this->assertFalse( ResponseCache::eligible( true, 0 ) );
	}

	public function test_cache_keys(): void {
		$key = ResponseCache::key( 'What are your hours?', 'en', 'PROMPT', 'openai/m' );

		$this->assertSame( $key, ResponseCache::key( '  what ARE your hours ', 'EN', 'PROMPT', 'openai/m' ), 'Same normalized question.' );
		$this->assertNotSame( $key, ResponseCache::key( 'What are your hours?', 'de', 'PROMPT', 'openai/m' ), 'Language.' );
		$this->assertNotSame( $key, ResponseCache::key( 'What are your hours?', 'en', 'PROMPT2', 'openai/m' ), 'Resolved prompt.' );
		$this->assertNotSame( $key, ResponseCache::key( 'What are your hours?', 'en', 'PROMPT', 'openai/m2' ), 'Model.' );

		$this->options[ ResponseCache::KNOWLEDGE_VERSION_OPTION ] = '2';
		$this->assertNotSame( $key, ResponseCache::key( 'What are your hours?', 'en', 'PROMPT', 'openai/m' ), 'Knowledge changes invalidate.' );
	}

	public function test_cache_storage_uses_object_cache_when_available(): void {
		$cache = new ResponseCache();

		$cache->set( 'k1', 'Answer', array() );
		$this->assertSame( 'Answer', $this->transients['k1']['text'] );
		$this->assertSame( 'Answer', $cache->get( 'k1' )['text'] );

		$this->ext_object_cache = true;
		$cache->set( 'k2', 'Cached', array() );
		$this->assertArrayNotHasKey( 'k2', $this->transients );
		$this->assertSame( 'Cached', $cache->get( 'k2' )['text'] );
		$this->assertNull( $cache->get( 'missing' ) );
	}

	public function test_conversation_cap(): void {
		$guard = new CostGuard();
		$this->assertFalse( $guard->conversation_capped( 49 ) );
		$this->assertTrue( $guard->conversation_capped( 50 ) );

		$this->settings( array( 'costs' => array( 'conversation_message_cap' => 0 ) ) );
		$this->assertFalse( $guard->conversation_capped( 9999 ), '0 means unlimited.' );
	}

	public function test_budget_usage_alerts_once_per_period(): void {
		$this->settings(
			array(
				'costs' => array(
					'budget_unit'  => 'currency',
					'daily_budget' => 1.0,
					'alert_email'  => 'owner@example.test',
					'cap_behavior' => 'offline',
				),
			)
		);

		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject ) use ( &$sent ) {
				$sent[] = array( $to, $subject );
				return true;
			}
		);
		Actions\expectDone( 'chatlume_budget_reached' )->once()->with( 'day', \Mockery::type( 'array' ) );

		$guard = new CostGuard();
		$guard->record( 100, 0.5 );
		$this->assertSame( '', $guard->exhausted_period() );
		$this->assertSame( array(), $sent );

		$guard->record( 100, 0.35 );
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( '80%', $sent[0][1] );
		$this->assertSame( 'owner@example.test', $sent[0][0] );

		$guard->record( 100, 0.2 );
		$this->assertCount( 2, $sent );
		$this->assertStringContainsString( 'reached its daily AI budget', $sent[1][1] );
		$this->assertSame( 'day', $guard->exhausted_period() );
		$this->assertSame( 'offline', $guard->cap_behavior() );

		$guard->record( 100, 0.2 );
		$this->assertCount( 2, $sent, 'No repeated alerts in the same period.' );
		$this->assertFalse( $this->autoload[ CostGuard::OPTION ] );
	}

	public function test_usage_resets_with_a_new_day_and_supports_tokens(): void {
		$this->options[ CostGuard::OPTION ] = array(
			'day'          => '2000-01-01',
			'month'        => '2000-01',
			'day_tokens'   => 999,
			'month_tokens' => 999,
			'day_cost'     => 9,
			'month_cost'   => 9,
			'alerts'       => array(
				'day'   => array( 80, 100 ),
				'month' => array( 80 ),
			),
		);

		$usage = ( new CostGuard() )->usage();
		$this->assertSame( 0, $usage['day_tokens'] );
		$this->assertSame( 0, $usage['month_tokens'] );
		$this->assertSame( array(), $usage['alerts']['day'] );

		$this->settings(
			array(
				'costs' => array(
					'budget_unit'    => 'tokens',
					'monthly_budget' => 500,
				),
			)
		);
		Functions\when( 'wp_mail' )->justReturn( true );
		$guard = new CostGuard();
		$guard->record( 600, null );
		$this->assertSame( 'month', $guard->exhausted_period() );
	}

	public function test_unstored_conversations_live_in_a_session_bound_transient(): void {
		$state = new ConversationState( null, null, false );
		$id    = $state->start( 'session-a', array() );

		$this->assertMatchesRegularExpression( '/^t_[a-f0-9]{24}$/', $id );
		$this->assertFalse( $state->is_stored() );
		$this->assertSame( 0, $state->find( $id, 'session-a' )['user_messages'] );
		$this->assertNull( $state->find( $id, 'session-b' ), 'Other sessions cannot read it.' );
		$this->assertNull( $state->find( 't_' . str_repeat( 'f', 24 ), 'session-a' ) );
		$this->assertNull( $state->find( '../etc', 'session-a' ) );

		$this->assertSame( 0, $state->record_turn( $id, 'Hi', array( 'content' => 'Hello!' ) ) );
		$state->record_turn( $id, 'And?', array( 'content' => 'More.' ) );

		$this->assertSame( 2, $state->find( $id, 'session-a' )['user_messages'] );
		$this->assertSame(
			array(
				array(
					'role'    => 'assistant',
					'content' => 'Hello!',
				),
				array(
					'role'    => 'user',
					'content' => 'And?',
				),
				array(
					'role'    => 'assistant',
					'content' => 'More.',
				),
			),
			$state->history( $id, 3 )
		);
		$this->assertSame( array(), $state->history( $id, 0 ) );
	}
}
