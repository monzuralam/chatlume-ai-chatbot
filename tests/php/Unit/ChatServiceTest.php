<?php
/**
 * Tests for the chat pipeline.
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
use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\Chat\ConversationState;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Knowledge\SiteSearchFallback;
use Chatlume\Providers\ProviderError;
use Chatlume\Settings\Settings;
use Chatlume\Tests\Support\ScriptedProvider;
use Chatlume\Tests\TestCase;

/**
 * Quick answers, guardrails, cache, no-key mode, provider failures, budgets,
 * limits, and hooks, using in-memory collaborators.
 */
class ChatServiceTest extends TestCase {

	/**
	 * Quick answer double.
	 *
	 * @var QuickAnswerRepository
	 */
	private QuickAnswerRepository $quick_answers;

	/**
	 * AI provider double.
	 *
	 * @var ScriptedProvider
	 */
	private ScriptedProvider $provider;

	protected function set_up(): void {
		parent::set_up();
		$this->ext_object_cache = true;

		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'number_format_i18n' )->returnArg();

		$this->quick_answers = new class() extends QuickAnswerRepository {
			/**
			 * Counters.
			 *
			 * @var array<string, int>
			 */
			public array $counts = array(
				'hit'  => 0,
				'miss' => 0,
			);

			/**
			 * Fixed quick answers.
			 *
			 * @param string $language Language.
			 * @return array
			 */
			public function enabled_for( string $language ): array {
				unset( $language );
				return array(
					array(
						'id'       => 7,
						'question' => 'What are your opening hours?',
						'answer'   => 'Nine to five.',
						'keywords' => '',
					),
				);
			}

			/**
			 * Counts hits.
			 *
			 * @param int $id ID.
			 * @return void
			 */
			public function record_hit( int $id ): void {
				unset( $id );
				++$this->counts['hit'];
			}

			/**
			 * Counts misses.
			 *
			 * @param int $id ID.
			 * @return void
			 */
			public function record_miss( int $id ): void {
				unset( $id );
				++$this->counts['miss'];
			}
		};

		$this->provider = new ScriptedProvider( 'alpha' );
		$provider       = $this->provider;
		Filters\expectApplied( 'chatlume_register_providers' )->andReturnUsing(
			static function ( $providers ) use ( $provider ) {
				$providers['alpha'] = $provider;
				return $providers;
			}
		);

		$this->options[ Settings::OPTION ] = array(
			'providers' => array(
				'primary' => array(
					'provider' => 'alpha',
					'model'    => 'm1',
				),
			),
		);
	}

	/**
	 * Service with in-memory collaborators.
	 *
	 * @return ChatService
	 */
	private function service(): ChatService {
		$site_search = new class() extends SiteSearchFallback {
			/**
			 * Fixed results.
			 *
			 * @param string $question Question.
			 * @param int    $limit    Limit.
			 * @return array
			 */
			public function search( string $question, int $limit = 3 ): array {
				unset( $question, $limit );
				return array(
					array(
						'title' => 'Help',
						'url'   => 'https://example.test/help/',
					),
				);
			}
		};

		return new ChatService(
			array(
				'state'         => new ConversationState( null, null, false ),
				'quick_answers' => $this->quick_answers,
				'site_search'   => $site_search,
			)
		);
	}

	/**
	 * Request.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function request( array $overrides = array() ): array {
		return array_merge(
			array(
				'session_id'   => 's1',
				'message'      => 'Tell me about your products',
				'language'     => 'en',
				'visitor_hash' => 'hash1',
			),
			$overrides
		);
	}

	/**
	 * Changes settings while keeping the provider routing.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function settings( array $settings ): void {
		$this->options[ Settings::OPTION ] = array_merge( $this->options[ Settings::OPTION ], $settings );
	}

	public function test_ai_answer_with_hooks_and_history(): void {
		Actions\expectDone( 'chatlume_conversation_started' )->once();
		Actions\expectDone( 'chatlume_message_received' )->twice();
		Actions\expectDone( 'chatlume_response_sent' )->twice();

		$service = $this->service();
		$first   = $service->handle( $this->request() );

		$this->assertSame( 'ai', $first['reply']['source'] );
		$this->assertSame( 'ok', $first['reply']['text'] );
		$this->assertStringStartsWith( 't_', $first['conversation_id'] );

		$second = $service->handle(
			$this->request(
				array(
					'conversation_id' => $first['conversation_id'],
					'message'         => 'And prices?',
				)
			)
		);
		$this->assertSame( $first['conversation_id'], $second['conversation_id'] );
		$this->assertCount( 2, $this->provider->calls );
	}

	public function test_quick_answer_skips_the_ai_and_ask_ai_counts_a_miss(): void {
		$service = $this->service();

		$reply = $service->handle( $this->request( array( 'message' => 'what are your opening hours' ) ) )['reply'];
		$this->assertSame( 'quick_answer', $reply['source'] );
		$this->assertSame( 'Nine to five.', $reply['text'] );
		$this->assertSame( 7, $reply['quick_answer_id'] );
		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( 1, $this->quick_answers->counts['hit'] );

		$reply = $service->handle(
			$this->request(
				array(
					'message'            => 'what are your opening hours',
					'skip_quick_answers' => true,
					'quick_answer_id'    => 7,
				)
			)
		)['reply'];
		$this->assertSame( 'ai', $reply['source'] );
		$this->assertSame( 1, $this->quick_answers->counts['miss'] );
	}

	public function test_blocked_words_get_the_refusal(): void {
		$this->settings( array( 'chatbot' => array( 'blocked_words' => array( 'casino' ) ) ) );

		$reply = $this->service()->handle( $this->request( array( 'message' => 'Any CASINO tips?' ) ) )['reply'];

		$this->assertSame( 'guardrail', $reply['source'] );
		$this->assertSame( array(), $this->provider->calls );
	}

	public function test_cache_serves_the_second_visitor(): void {
		$service = $this->service();

		$this->assertSame( 'ai', $service->handle( $this->request( array( 'session_id' => 'a' ) ) )['reply']['source'] );
		$this->assertSame(
			'cache',
			$service->handle(
				$this->request(
					array(
						'session_id' => 'b',
						'message'    => 'tell me ABOUT your products!',
					)
				)
			)['reply']['source']
		);
		$this->assertCount( 1, $this->provider->calls );

		$this->assertSame(
			'ai',
			$service->handle(
				$this->request(
					array(
						'session_id' => 'c',
						'user_id'    => 3,
					)
				)
			)['reply']['source'],
			'Logged-in visitors bypass the cache.'
		);
	}

	public function test_no_provider_and_provider_failure_fall_back_to_site_search(): void {
		$this->options[ Settings::OPTION ]['providers']['primary'] = array(
			'provider' => '',
			'model'    => '',
		);
		$reply = $this->service()->handle( $this->request() )['reply'];
		$this->assertSame( 'site_search', $reply['source'] );
		$this->assertSame( 'https://example.test/help/', $reply['sources'][0]['url'] );

		$this->options[ Settings::OPTION ]['providers']['primary'] = array(
			'provider' => 'alpha',
			'model'    => 'broken',
		);
		$this->provider->failing                                   = array( 'broken' => ProviderError::QUOTA );

		$result = $this->service()->handle( $this->request() );
		$this->assertSame( 'site_search', $result['reply']['source'] );
		$this->assertSame( 'quota', $result['details']['error_type'] );
	}

	public function test_budget_behaviors(): void {
		Functions\when( 'wp_mail' )->justReturn( true );
		$this->options['chatlume_usage'] = array(
			'day'        => gmdate( 'Y-m-d' ),
			'month'      => gmdate( 'Y-m' ),
			'day_cost'   => 5,
			'month_cost' => 5,
			'alerts'     => array(
				'day'   => array( 80, 100 ),
				'month' => array(),
			),
		);

		$this->settings(
			array(
				'costs' => array(
					'daily_budget' => 1,
					'cap_behavior' => 'offline',
				),
			)
		);
		$this->assertSame( 'offline', $this->service()->handle( $this->request() )['reply']['source'] );

		$this->settings(
			array(
				'costs' => array(
					'daily_budget' => 1,
					'cap_behavior' => 'site_search',
				),
			)
		);
		$this->assertSame( 'site_search', $this->service()->handle( $this->request() )['reply']['source'] );

		$this->options[ Settings::OPTION ]['providers']['fallback'] = array(
			'provider' => 'alpha',
			'model'    => 'cheap',
		);
		$this->settings(
			array(
				'costs' => array(
					'daily_budget' => 1,
					'cap_behavior' => 'fallback_model',
				),
			)
		);
		$reply = $this->service()->handle( $this->request() )['reply'];
		$this->assertSame( 'fallback_model', $reply['source'] );
		$this->assertSame( array( 'chat', 'cheap' ), end( $this->provider->calls ) );
		$this->assertCount( 1, $this->provider->calls, 'The primary model is not used while over budget.' );
	}

	/**
	 * Rejections.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: int}>
	 */
	public function rejection_cases(): array {
		return array(
			'empty'    => array( array(), array( 'message' => '   ' ), 'empty_message', 400 ),
			'too long' => array( array( 'chatbot' => array( 'max_message_length' => 50 ) ), array( 'message' => str_repeat( 'ü', 51 ) ), 'message_too_long', 400 ),
		);
	}

	/**
	 * Visitor-safe rejections.
	 *
	 * @dataProvider rejection_cases
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param array<string, mixed> $request  Request overrides.
	 * @param string               $code     Code.
	 * @param int                  $status   Status.
	 */
	public function test_rejections( array $settings, array $request, string $code, int $status ): void {
		$this->settings( $settings );

		try {
			$this->service()->handle( $this->request( $request ) );
			$this->fail( 'Expected ChatException.' );
		} catch ( ChatException $exception ) {
			$this->assertSame( $code, $exception->error_code() );
			$this->assertSame( $status, $exception->status() );
		}
	}

	public function test_rate_limit_and_conversation_cap(): void {
		$this->settings(
			array(
				'security' => array( 'rate_limit_messages' => 2 ),
				'costs'    => array( 'conversation_message_cap' => 0 ),
			)
		);
		$service = $this->service();
		$service->handle( $this->request( array( 'session_id' => 'r' ) ) );
		$service->handle( $this->request( array( 'session_id' => 'r' ) ) );

		try {
			$service->handle( $this->request( array( 'session_id' => 'r' ) ) );
			$this->fail( 'Expected rate limit.' );
		} catch ( ChatException $exception ) {
			$this->assertSame( 'rate_limited', $exception->error_code() );
			$this->assertSame( 429, $exception->status() );
			$this->assertGreaterThan( 0, $exception->retry_after() );
		}

		$this->settings( array( 'costs' => array( 'conversation_message_cap' => 1 ) ) );
		$service = $this->service();
		$first   = $service->handle(
			$this->request(
				array(
					'session_id'   => 'cap',
					'visitor_hash' => 'other',
				)
			)
		);

		try {
			$service->handle(
				$this->request(
					array(
						'session_id'      => 'cap',
						'visitor_hash'    => 'other',
						'conversation_id' => $first['conversation_id'],
					)
				)
			);
			$this->fail( 'Expected conversation cap.' );
		} catch ( ChatException $exception ) {
			$this->assertSame( 'conversation_limit', $exception->error_code() );
		}
	}

	public function test_knowledge_context_and_sources(): void {
		Filters\expectApplied( 'chatlume_retrieved_context' )->andReturn(
			array(
				array(
					'content' => 'We ship worldwide.',
					'title'   => 'Shipping',
					'url'     => 'https://example.test/shipping/',
				),
				array( 'content' => '' ),
			)
		);
		Filters\expectApplied( 'chatlume_response_text' )->andReturnUsing(
			static function ( $text ) {
				return $text . ' (filtered)';
			}
		);

		$reply = $this->service()->handle( $this->request() )['reply'];

		$this->assertSame( 'ok (filtered)', $reply['text'] );
		$this->assertSame(
			array(
				array(
					'title' => 'Shipping',
					'url'   => 'https://example.test/shipping/',
				),
			),
			$reply['sources']
		);
	}

	public function test_streaming_forwards_tokens_and_reports_the_conversation(): void {
		$tokens  = array();
		$started = array();
		$result  = $this->service()->handle(
			$this->request(),
			array(
				'on_start'     => static function ( string $id ) use ( &$started ) {
					$started[] = $id;
				},
				'on_token'     => static function ( string $text ) use ( &$tokens ) {
					$tokens[] = $text;
				},
				'should_abort' => static function () {
					return '';
				},
			)
		);

		$this->assertSame( array( 'ok' ), $tokens );
		$this->assertSame( array( $result['conversation_id'] ), $started );
		$this->assertSame( array( array( 'stream', 'm1' ) ), $this->provider->calls );
		$this->assertArrayNotHasKey( 'interrupted', $result['reply'] );
	}

	public function test_quick_answers_are_not_streamed(): void {
		$tokens = array();
		$reply  = $this->service()->handle(
			$this->request( array( 'message' => 'What are your opening hours?' ) ),
			array(
				'on_token' => static function ( string $text ) use ( &$tokens ) {
					$tokens[] = $text;
				},
			)
		)['reply'];

		$this->assertSame( 'quick_answer', $reply['source'] );
		$this->assertSame( array(), $tokens, 'The stream handler sends whole replies itself.' );
	}

	public function test_a_broken_stream_keeps_the_part_already_shown(): void {
		$this->options[ Settings::OPTION ]['providers']['primary']['model'] = 'broken';
		$this->provider->failing               = array( 'broken' => ProviderError::SERVER_ERROR );
		$this->provider->tokens_before_failure = array( 'Half ', 'an answer' );

		$result = $this->service()->handle(
			$this->request(),
			array( 'on_token' => static function () {} )
		);

		$this->assertSame( 'ai', $result['reply']['source'] );
		$this->assertSame( 'Half an answer', $result['reply']['text'] );
		$this->assertSame( 'error', $result['reply']['interrupted'] );
		$this->assertSame( 'server_error', $result['details']['error_type'] );
	}

	public function test_stopped_replies_are_kept_but_never_cached(): void {
		$this->provider->finish_reason = 'aborted';
		$service                       = $this->service();

		$first = $service->handle( $this->request( array( 'session_id' => 'a' ) ), array( 'on_token' => static function () {} ) );
		$this->assertSame( 'stopped', $first['reply']['interrupted'] );

		$this->provider->finish_reason = 'stop';
		$second                        = $service->handle( $this->request( array( 'session_id' => 'b' ) ) );
		$this->assertSame( 'ai', $second['reply']['source'], 'A stopped answer is not served from the cache.' );
		$this->assertCount( 2, $this->provider->calls );
	}

	public function test_debug_requests_return_a_trace_of_the_pipeline(): void {
		Filters\expectApplied( 'chatlume_retrieved_context' )->andReturn(
			array(
				array(
					'content' => 'We ship worldwide.',
					'title'   => 'Shipping',
					'url'     => 'https://example.test/shipping/',
					'score'   => 0.81234,
				),
			)
		);
		$service = $this->service();

		$plain = $service->handle( $this->request( array( 'session_id' => 'a' ) ) );
		$this->assertArrayNotHasKey( 'trace', $plain['details'], 'Visitors never get a trace.' );

		$first = $service->handle(
			$this->request(
				array(
					'session_id' => 'b',
					'message'    => 'Do you ship abroad?',
					'debug'      => true,
				)
			)
		);
		$trace = $first['details']['trace'];

		$this->assertSame( 'ai', $trace['route'] );
		$this->assertSame( 'stored', $trace['cache'] );
		$this->assertSame(
			array(
				'title'   => 'Shipping',
				'url'     => 'https://example.test/shipping/',
				'score'   => 0.812,
				'match'   => '',
				'excerpt' => 'We ship worldwide.',
			),
			$trace['knowledge'][0]
		);
		$this->assertSame( 'system', $trace['prompt']['messages'][0]['role'] );
		$this->assertStringContainsString( 'We ship worldwide.', $trace['prompt']['messages'][0]['content'] );
		$this->assertSame(
			array(
				'role'    => 'user',
				'content' => 'Do you ship abroad?',
			),
			end( $trace['prompt']['messages'] )
		);

		$again = $service->handle(
			$this->request(
				array(
					'session_id' => 'c',
					'message'    => 'Do you ship abroad?',
					'debug'      => true,
				)
			)
		);
		$this->assertSame( 'cache', $again['details']['trace']['route'] );
		$this->assertSame( 'hit', $again['details']['trace']['cache'] );
	}

	public function test_trace_names_the_quick_answer_guardrail_and_site_search_routes(): void {
		$service = $this->service();

		$trace = $service->handle(
			$this->request(
				array(
					'message' => 'what are your opening hours',
					'debug'   => true,
				)
			)
		)['details']['trace'];
		$this->assertSame( 'quick_answer', $trace['route'] );
		$this->assertSame( 7, $trace['quick_answer']['id'] );
		$this->assertSame( 'What are your opening hours?', $trace['quick_answer']['question'] );
		$this->assertGreaterThan( 0, $trace['quick_answer']['score'] );
		$this->assertSame( array(), $trace['prompt'], 'No prompt was built.' );

		$this->settings( array( 'chatbot' => array( 'blocked_words' => array( 'casino' ) ) ) );
		$this->assertSame(
			'blocked_word',
			$service->handle(
				$this->request(
					array(
						'message' => 'casino?',
						'debug'   => true,
					)
				)
			)['details']['trace']['route']
		);

		$this->options[ Settings::OPTION ]['providers']['primary'] = array(
			'provider' => '',
			'model'    => '',
		);
		$this->assertSame( 'site_search', $this->service()->handle( $this->request( array( 'debug' => true ) ) )['details']['trace']['route'] );
	}

	public function test_outside_business_hours_the_bot_sends_the_offline_message(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		$closed = array(
			'open'  => false,
			'start' => '09:00',
			'end'   => '17:00',
		);
		$this->settings(
			array(
				'display' => array(
					'hours' => array(
						'enabled' => true,
						'days'    => array_fill_keys( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), $closed ),
					),
				),
				'chatbot' => array( 'offline_message' => 'Back tomorrow.' ),
			)
		);

		$result = $this->service()->handle(
			$this->request(
				array(
					'message' => 'what are your opening hours',
					'debug'   => true,
				)
			)
		);

		$this->assertSame( 'offline', $result['reply']['source'] );
		$this->assertSame( 'Back tomorrow.', $result['reply']['text'] );
		$this->assertTrue( $result['details']['meta']['closed'] );
		$this->assertSame( 'offline', $result['details']['trace']['route'] );
		$this->assertSame( 0, $this->quick_answers->counts['hit'], 'Quick answers wait for opening hours too.' );
		$this->assertSame( array(), $this->provider->calls );
	}

	public function test_admin_tests_can_skip_the_visitor_rate_limit(): void {
		$this->settings( array( 'security' => array( 'rate_limit_messages' => 1 ) ) );
		$service = $this->service();

		for ( $index = 0; $index < 3; $index++ ) {
			$result = $service->handle(
				$this->request(
					array(
						'session_id'      => 'admin-playground-1',
						'skip_rate_limit' => true,
					)
				)
			);
			$this->assertSame( 'ok', $result['reply']['text'] );
		}
	}
}
