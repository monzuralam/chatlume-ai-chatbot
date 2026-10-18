<?php
/**
 * Tests for the WordPress Connectors bridge and the Abilities API.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/Support/ai-client-stubs.php';

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Abilities\AbilitiesRegistrar;
use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Data\Stats;
use Chatlume\Providers\CoreAiProvider;
use Chatlume\Providers\CoreConnectorsBridge;
use Chatlume\Providers\ProviderError;
use Chatlume\Providers\ProviderRegistry;
use Chatlume\REST\ChatController;
use Chatlume\REST\ProvidersController;
use Chatlume\Tests\TestCase;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WP_Error;
use WP_REST_Request;

/**
 * Requests through WordPress's AI Client, provider states, and abilities.
 */
class PlatformTest extends TestCase {

	/**
	 * Fake prompt builder: records calls and returns a set result.
	 *
	 * @var object
	 */
	private object $builder;

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );

		$this->builder = new class() {
			/**
			 * Calls: [method, args].
			 *
			 * @var array<int, array>
			 */
			public array $calls = array();

			/**
			 * What generate_text_result() returns.
			 *
			 * @var mixed
			 */
			public $result;

			/**
			 * Records a chained call.
			 *
			 * @param string $name Method.
			 * @param array  $args Arguments.
			 * @return $this
			 */
			public function __call( $name, $args ) {
				$this->calls[] = array( $name, $args );
				return $this;
			}

			/**
			 * The result.
			 *
			 * @return mixed
			 */
			public function generate_text_result() {
				return $this->result;
			}
		};

		Functions\when( 'wp_ai_client_prompt' )->alias(
			function ( $prompt ) {
				$this->builder->calls[] = array( 'prompt', array( $prompt ) );
				return $this->builder;
			}
		);
	}

	/**
	 * A provider that reports itself connected.
	 *
	 * @param bool $connected Whether WordPress has its key.
	 * @return CoreAiProvider
	 */
	private function provider( bool $connected = true ): CoreAiProvider {
		$provider             = new class( 'openai', 'OpenAI', 'https://platform.openai.com/api-keys', array( 'default_model' => 'gpt-test' ) ) extends CoreAiProvider {
			/**
			 * Whether connected.
			 *
			 * @var bool
			 */
			public static bool $connected = true;

			/**
			 * {@inheritDoc}
			 */
			public function is_connected(): bool {
				return self::$connected;
			}
		};
		$provider::$connected = $connected;

		return $provider;
	}

	/**
	 * A successful AI Client result.
	 *
	 * @return object
	 */
	private static function result(): object {
		return new class() {
			/**
			 * Text.
			 *
			 * @return string
			 */
			public function toText() {
				return 'We deliver in 2 days.';
			}

			/**
			 * Token usage.
			 *
			 * @return object
			 */
			public function getTokenUsage() {
				return new class() {
					/**
					 * Prompt tokens.
					 *
					 * @return int
					 */
					public function getPromptTokens() {
						return 120;
					}

					/**
					 * Completion tokens.
					 *
					 * @return int
					 */
					public function getCompletionTokens() {
						return 9;
					}
				};
			}

			/**
			 * Candidates.
			 *
			 * @return array
			 */
			public function getCandidates() {
				return array(
					new class() {
						/**
						 * Finish reason.
						 *
						 * @return object
						 */
						public function getFinishReason() {
							return (object) array( 'value' => 'length' );
						}
					},
				);
			}

			/**
			 * Model metadata.
			 *
			 * @return object
			 */
			public function getModelMetadata() {
				return new class() {
					/**
					 * Model ID.
					 *
					 * @return string
					 */
					public function getId() {
						return 'gpt-test-2026';
					}
				};
			}
		};
	}

	public function test_identity_and_info(): void {
		$provider = $this->provider();

		$this->assertSame( 'wp-openai', $provider->get_id() );
		$this->assertSame( 'wp-my-local-ai', CoreAiProvider::id_for( 'My_Local.AI' ) );
		$this->assertSame( 'OpenAI through WordPress', $provider->get_label() );
		$this->assertSame( 'core', $provider->get_info()['kind'] );
		$this->assertFalse( $provider->requires_key() );
		$this->assertFalse( $provider->supports( 'streaming' ) );
		$this->assertFalse( $provider->supports( 'embeddings' ) );
		$this->assertSame( 'https://example.test/wp-admin/options-connectors.php', $provider->get_links()['keys'] );
		$this->assertSame( 'openai', $provider->with_config( array() )->core_id() );
	}

	public function test_requests_go_through_the_ai_client(): void {
		$this->builder->result = self::result();

		$response = $this->provider()->chat(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are the shop assistant.',
				),
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Hello! How can I help?',
				),
				array(
					'role'    => 'user',
					'content' => 'When do you deliver?',
				),
			),
			array(
				'model'       => 'gpt-chosen',
				'max_tokens'  => 300,
				'temperature' => 0.4,
				'timeout'     => 20,
			)
		);

		$calls = array_column( $this->builder->calls, 1, 0 );
		$this->assertSame( array( 'When do you deliver?' ), $calls['prompt'] );
		$this->assertSame( array( 'openai' ), $calls['using_provider'] );
		$this->assertSame( array( array( 'openai', 'gpt-chosen' ) ), $calls['using_model_preference'], 'Provider first, then model.' );
		$this->assertSame( array( 'You are the shop assistant.' ), $calls['using_system_instruction'] );
		$this->assertSame( array( 300 ), $calls['using_max_tokens'] );
		$this->assertSame( array( 0.4 ), $calls['using_temperature'] );
		$this->assertCount( 2, $calls['with_history'] );
		$this->assertInstanceOf( UserMessage::class, $calls['with_history'][0] );
		$this->assertInstanceOf( ModelMessage::class, $calls['with_history'][1] );
		$this->assertSame( 'Hello! How can I help?', $calls['with_history'][1]->parts[0]->text );

		$this->assertSame( 'We deliver in 2 days.', $response->text );
		$this->assertSame( 120, $response->input_tokens );
		$this->assertSame( 9, $response->output_tokens );
		$this->assertSame( 'length', $response->finish_reason );
		$this->assertSame( 'wp-openai', $response->provider );
		$this->assertSame( 'gpt-test-2026', $response->model );
		$this->assertFalse( has_filter( 'wp_ai_client_default_request_timeout' ), 'The timeout filter is only used while the prompt is created.' );
	}

	public function test_the_whole_reply_is_streamed_as_one_piece(): void {
		$this->builder->result = self::result();
		$pieces                = array();

		$response = $this->provider()->stream(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			static function ( string $text ) use ( &$pieces ) {
				$pieces[] = $text;
			}
		);

		$this->assertSame( array( 'We deliver in 2 days.' ), $pieces );
		$this->assertSame( 'gpt-test', array_column( $this->builder->calls, 1, 0 )['using_model_preference'][0][1], 'The default model.' );
		$this->assertSame( 'We deliver in 2 days.', $response->text );
	}

	public function test_not_connected_and_empty_requests_are_refused(): void {
		try {
			$this->provider( false )->chat(
				array(
					array(
						'role'    => 'user',
						'content' => 'Hi',
					),
				),
				array()
			);
			$this->fail( 'Expected an error.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::NOT_CONFIGURED, $error->type() );
		}

		$this->expectException( ProviderError::class );
		$this->provider()->chat(
			array(
				array(
					'role'    => 'assistant',
					'content' => 'Hello',
				),
			),
			array()
		);
	}

	/**
	 * AI Client errors and the plain-language types they become.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function errors(): array {
		return array(
			'prevented'    => array( 'prompt_prevented', 503, ProviderError::NOT_CONFIGURED ),
			'token limit'  => array( 'prompt_token_limit_reached', 400, ProviderError::CONTEXT_TOO_LONG ),
			'upstream'     => array( 'prompt_upstream_server_error', 502, ProviderError::SERVER_ERROR ),
			'timeout'      => array( 'prompt_network_error', 0, ProviderError::TIMEOUT ),
			'bad key'      => array( 'prompt_client_error', 401, ProviderError::INVALID_KEY ),
			'rate limited' => array( 'prompt_client_error', 429, ProviderError::RATE_LIMITED ),
			'no model'     => array( 'prompt_client_error', 404, ProviderError::MODEL_NOT_FOUND ),
			'other client' => array( 'prompt_invalid_argument', 400, ProviderError::BAD_REQUEST ),
		);
	}

	/**
	 * Maps AI Client errors.
	 *
	 * @dataProvider errors
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @param string $type   Expected type.
	 */
	public function test_ai_client_errors_are_explained( string $code, int $status, string $type ): void {
		$this->builder->result = new WP_Error( $code, 'prompt_network_error' === $code ? 'Operation timed out after 20 seconds' : 'Failed', array( 'status' => $status ) );

		try {
			$this->provider()->chat(
				array(
					array(
						'role'    => 'user',
						'content' => 'Hi',
					),
				),
				array()
			);
			$this->fail( 'Expected an error.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( $type, $error->type() );
			$this->assertSame( 'wp-openai', $error->provider() );
		}
	}

	public function test_the_widget_knows_when_replies_do_not_stream(): void {
		$core = $this->provider();
		Filters\expectApplied( 'chatlume_register_providers' )->andReturnUsing(
			static function ( $providers ) use ( $core ) {
				return $providers + array( 'wp-openai' => $core );
			}
		);
		ProviderRegistry::reset();

		$this->options['chatlume_settings'] = array( 'providers' => array( 'primary' => array( 'provider' => 'openai' ) ) );
		$this->assertTrue( ChatController::primary_streams() );

		$this->options['chatlume_settings'] = array( 'providers' => array( 'primary' => array( 'provider' => 'wp-openai' ) ) );
		$this->assertFalse( ChatController::primary_streams(), 'Answers through WordPress arrive whole.' );

		$this->options['chatlume_settings'] = array();
		$this->assertTrue( ChatController::primary_streams(), 'No provider: nothing to wait for.' );
	}

	public function test_no_bridge_without_the_ai_client(): void {
		$this->assertFalse( CoreConnectorsBridge::available() );
		$this->assertSame( array(), CoreConnectorsBridge::connectors() );
		$this->assertSame( array( 'x' => 1 ), CoreConnectorsBridge::add_providers( array( 'x' => 1 ) ) );
	}

	public function test_providers_page_states_and_no_key_storage(): void {
		$connected = $this->provider();
		$waiting   = new class( 'anthropic', 'Anthropic' ) extends CoreAiProvider {
			/**
			 * {@inheritDoc}
			 */
			public function is_connected(): bool {
				return false;
			}
		};
		Filters\expectApplied( 'chatlume_register_providers' )->andReturnUsing(
			static function ( $providers ) use ( $connected, $waiting ) {
				return $providers + array(
					'wp-openai'    => $connected,
					'wp-anthropic' => $waiting,
				);
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		ProviderRegistry::reset();

		$controller = new ProvidersController();
		$states     = array_column( $controller->get_providers()->get_data()['providers'], 'state', 'id' );
		$this->assertSame( 'untested', $states['wp-openai'] );
		$this->assertSame( 'needs_connector', $states['wp-anthropic'] );

		$refused = $controller->save_key(
			new WP_REST_Request(
				array(
					'id'      => 'wp-openai',
					'api_key' => 'sk-should-not-be-stored',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertArrayNotHasKey( 'chatlume_api_keys', $this->options );
	}

	public function test_abilities_are_registered_with_permissions(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
				return true;
			}
		);
		Functions\expect( 'wp_register_ability_category' )->once()->with( 'chatlume', \Mockery::type( 'array' ) );

		$registrar = new AbilitiesRegistrar();
		$registrar->register_category();
		$registrar->register_abilities();

		$this->assertSame( array( 'chatlume/ask', 'chatlume/get-stats', 'chatlume/list-unanswered-questions', 'chatlume/create-quick-answer' ), array_keys( $registered ) );
		foreach ( $registered as $name => $args ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
			$this->assertSame( 'chatlume', $args['category'] );
			$this->assertSame( array( $registrar, 'permission' ), $args['permission_callback'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
			$this->assertSame( 'object', $args['input_schema']['type'] );
		}
		$this->assertTrue( $registered['chatlume/get-stats']['meta']['annotations']['readonly'] );
		$this->assertSame( array( 'question' ), $registered['chatlume/ask']['input_schema']['required'] );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $registrar->permission() );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( $registrar->permission() );
	}

	public function test_abilities_run_the_real_services(): void {
		$service = $this->createMock( ChatService::class );
		$service->method( 'handle' )->willReturnCallback(
			static function ( array $request ) {
				if ( 'fail' === $request['message'] ) {
					throw new ChatException( 'budget', 'The bot is resting.', 503 );
				}
				TestCase::assertTrue( $request['is_test'], 'Kept out of the statistics.' );
				TestCase::assertSame( 'bn-BD', $request['language'] );
				return array(
					'conversation_id' => '5',
					'message_id'      => 9,
					'reply'           => array(
						'text'    => 'We open at 9.',
						'source'  => 'ai',
						'sources' => array(
							array(
								'title' => 'Hours',
								'url'   => 'https://example.test/hours',
							),
						),
					),
					'details'         => array(),
				);
			}
		);
		$stats = $this->createMock( Stats::class );
		$stats->method( 'summary' )->willReturnCallback(
			static function ( int $days ) {
				return array(
					'days'       => $days,
					'totals'     => array(
						'conversations'     => 3,
						'questions'         => 7,
						'tokens'            => 900,
						'cost'              => 0.0123,
						'free_answers'      => 2,
						'saved'             => 0.004,
						'replies_by_source' => array( 'ai' => 5 ),
					),
					'unanswered' => array(
						array(
							'question'  => 'Do you ship abroad?',
							'count'     => 2,
							'reasons'   => array( 'site_search' ),
							'last_seen' => '2026-10-18T10:00:00Z',
							'language'  => 'en',
						),
					),
				);
			}
		);
		$repository = $this->createMock( QuickAnswerRepository::class );
		$repository->method( 'find_by_question' )->willReturnOnConsecutiveCalls( 0, 12 );
		$repository->expects( $this->once() )->method( 'create' )->with( $this->callback( static fn( $values ) => true === $values['enabled'] && 'Opening hours?' === $values['question'] ) )->willReturn( 12 );
		$repository->expects( $this->once() )->method( 'update' )->with( 12, $this->anything() );

		$registrar = new class( $service, $stats, $repository ) extends AbilitiesRegistrar {
			/**
			 * Doubles.
			 *
			 * @var array
			 */
			private array $doubles;

			/**
			 * Constructor.
			 *
			 * @param mixed ...$doubles Service, stats, repository.
			 */
			public function __construct( ...$doubles ) {
				$this->doubles = $doubles;
			}

			/**
			 * {@inheritDoc}
			 */
			protected function service(): ChatService {
				return $this->doubles[0];
			}

			/**
			 * {@inheritDoc}
			 */
			protected function stats(): Stats {
				return $this->doubles[1];
			}

			/**
			 * {@inheritDoc}
			 */
			protected function quick_answers(): QuickAnswerRepository {
				return $this->doubles[2];
			}
		};
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->assertSame(
			array(
				'answer'  => 'We open at 9.',
				'source'  => 'ai',
				'sources' => array(
					array(
						'title' => 'Hours',
						'url'   => 'https://example.test/hours',
					),
				),
			),
			$registrar->ask(
				array(
					'question' => 'When do you open?',
					'language' => 'bn-BD',
				)
			)
		);
		$failed = $registrar->ask(
			array(
				'question' => 'fail',
				'language' => 'bn-BD',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertSame( 'chatlume_budget', $failed->get_error_code() );

		$stats_result = $registrar->get_stats( array( 'days' => 30 ) );
		$this->assertSame( 30, $stats_result['days'] );
		$this->assertSame( 7, $stats_result['questions'] );
		$this->assertSame( 7, $registrar->get_stats( array( 'days' => 99 ) )['days'], 'Unknown periods use 7 days.' );
		$this->assertSame( 'Do you ship abroad?', $registrar->list_unanswered( array() )[0]['question'] );

		$created = $registrar->create_quick_answer(
			array(
				'question' => ' Opening hours? ',
				'answer'   => '9 to 5.',
			)
		);
		$this->assertSame( 12, $created['id'] );
		$this->assertFalse( $created['updated'] );
		$this->assertTrue(
			$registrar->create_quick_answer(
				array(
					'question' => 'Opening hours?',
					'answer'   => '10 to 6.',
				)
			)['updated']
		);
		$this->assertInstanceOf( WP_Error::class, $registrar->create_quick_answer( array( 'question' => 'x' ) ) );
	}
}
