<?php
/**
 * Tests for the WordPress privacy tools and the chat privacy notice.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Data\ConversationLog;
use Chatlume\Data\ConversationRepository;
use Chatlume\Data\MessageRepository;
use Chatlume\Privacy\PrivacyHandler;
use Chatlume\REST\ChatController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Exporting and erasing a person's conversations, the suggested policy
 * text, and the notice shown in the chat.
 */
class PrivacyTest extends TestCase {

	/**
	 * Requests made to the conversation lookup: [email, user ID, limit, offset].
	 *
	 * @var array<int, array>
	 */
	private array $lookups = array();

	protected function set_up(): void {
		parent::set_up();
		$this->lookups                = array();
		$this->options['date_format'] = 'Y-m-d';
		$this->options['time_format'] = 'H:i';
	}

	/**
	 * A handler with fake data access.
	 *
	 * @param array<int, array<string, mixed>> $rows    Conversations returned for each lookup, in order.
	 * @param int[]                            $deleted Receives deleted IDs.
	 * @return PrivacyHandler
	 */
	private function handler( array $rows, array &$deleted = array() ): PrivacyHandler {
		$lookups      = &$this->lookups;
		$conversation = $this->createMock( ConversationRepository::class );
		$conversation->method( 'for_person' )->willReturnCallback(
			static function ( ...$args ) use ( &$lookups, $rows ) {
				$lookups[] = $args;
				return array_slice( $rows, $args[3], $args[2] );
			}
		);
		$messages = $this->createMock( MessageRepository::class );
		$messages->method( 'transcript' )->willReturn(
			array(
				array(
					'role'    => 'user',
					'content' => 'Do you ship to Dhaka?',
				),
				array(
					'role'    => 'assistant',
					'content' => '<strong>Yes</strong>, in 2 days.',
				),
			)
		);
		$log = $this->createMock( ConversationLog::class );
		$log->method( 'delete' )->willReturnCallback(
			static function ( array $ids ) use ( &$deleted ) {
				$deleted = array_merge( $deleted, $ids );
				return count( $ids );
			}
		);

		return new class( $conversation, $messages, $log ) extends PrivacyHandler {
			/**
			 * Doubles.
			 *
			 * @var array
			 */
			private array $doubles;

			/**
			 * Constructor.
			 *
			 * @param mixed ...$doubles Conversations, messages, log.
			 */
			public function __construct( ...$doubles ) {
				$this->doubles = $doubles;
			}

			/**
			 * {@inheritDoc}
			 */
			protected function conversations(): ConversationRepository {
				return $this->doubles[0];
			}

			/**
			 * {@inheritDoc}
			 */
			protected function messages(): MessageRepository {
				return $this->doubles[1];
			}

			/**
			 * {@inheritDoc}
			 */
			protected function log(): ConversationLog {
				return $this->doubles[2];
			}
		};
	}

	/**
	 * A stored conversation row.
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>
	 */
	private static function row( int $id ): array {
		return array(
			'id'         => (string) $id,
			'page_url'   => 'https://example.test/shipping/',
			'language'   => 'en-US',
			'lead_name'  => 'Rina',
			'lead_email' => 'rina@example.test',
			'rating'     => '-1',
			'created_at' => '2026-10-20 09:00:00',
			'updated_at' => '2026-10-20 09:05:00',
		);
	}

	public function test_tools_are_registered(): void {
		$handler = new PrivacyHandler();
		$this->assertSame( array( $handler, 'export' ), $handler->add_exporter( array() )['chatlume-ai-chatbot']['callback'] );
		$this->assertSame( array( $handler, 'erase' ), $handler->add_eraser( array() )['chatlume-ai-chatbot']['callback'] );
		$this->assertSame( 'not an array', $handler->add_exporter( 'not an array' ) );
	}

	public function test_export_includes_the_conversation_and_matches_the_user(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );

		$export = $this->handler( array( self::row( 4 ) ) )->export( ' rina@example.test ', 1 );

		$this->assertSame( array( 'rina@example.test', 7, 20, 0 ), $this->lookups[0], 'Lead email or the logged-in user with that email.' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$item = $export['data'][0];
		$this->assertSame( 'chatlume-conversations', $item['group_id'] );
		$this->assertSame( 'chatlume-conversation-4', $item['item_id'] );
		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( '2026-10-20 09:00', $values['Started'] );
		$this->assertSame( 'https://example.test/shipping/', $values['Page'] );
		$this->assertSame( 'rina@example.test', $values['Email'] );
		$this->assertSame( 'Not helpful', $values['Rating'] );
		$this->assertSame( "Visitor: Do you ship to Dhaka?\nChatbot: Yes, in 2 days.", $values['Messages'] );
	}

	public function test_export_pages(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$rows    = array_map( array( self::class, 'row' ), range( 1, 25 ) );
		$handler = $this->handler( $rows );

		$first = $handler->export( 'rina@example.test', 1 );
		$this->assertCount( 20, $first['data'] );
		$this->assertFalse( $first['done'] );

		$second = $handler->export( 'rina@example.test', 2 );
		$this->assertCount( 5, $second['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame( array( 'rina@example.test', 0, 20, 20 ), $this->lookups[1] );
	}

	public function test_invalid_email_finds_nothing(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$repository = new ConversationRepository();

		$this->assertSame( array(), $repository->for_person( '', 0, 20 ) );
		$this->assertTrue( $this->handler( array() )->export( 'not-an-email', 1 )['done'] );
	}

	public function test_erase_deletes_in_batches(): void {
		Functions\when( 'get_user_by' )->justReturn( false );
		$deleted = array();

		$result = $this->handler( array( self::row( 3 ), self::row( 9 ) ), $deleted )->erase( 'rina@example.test', 1 );

		$this->assertSame( array( 3, 9 ), $deleted );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( 'rina@example.test', 0, 50, 0 ), $this->lookups[0] );

		$nothing = $this->handler( array() )->erase( 'nobody@example.test', 1 );
		$this->assertFalse( $nothing['items_removed'] );
		$this->assertTrue( $nothing['done'] );
	}

	public function test_suggested_policy_text(): void {
		$added = array();
		Functions\when( 'wp_add_privacy_policy_content' )->alias(
			static function ( $name, $text ) use ( &$added ) {
				$added = array( $name, $text );
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();

		Functions\when( 'is_admin' )->justReturn( false );
		( new PrivacyHandler() )->add_policy_text();
		$this->assertSame( array(), $added, 'Only in wp-admin.' );

		Functions\when( 'is_admin' )->justReturn( true );
		( new PrivacyHandler() )->add_policy_text();

		$this->assertSame( 'Chatlume – AI Chatbot', $added[0] );
		$this->assertStringContainsString( 'privacy-policy-tutorial', $added[1] );
		$this->assertStringContainsString( 'the IP address itself is not stored', $added[1] );
		$this->assertStringContainsString( 'does not set cookies', $added[1] );
		$this->assertSame( 6, substr_count( $added[1], '<p>' ) );
	}

	public function test_the_privacy_notice_reaches_the_widget(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'rest_sanitize_value_from_schema' )->returnArg();
		$saved = Settings::update( array( 'privacy' => array( 'chat_notice' => 'By chatting, you agree to our <a href="https://example.test/privacy/">privacy policy</a>.<script>x</script>' ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $saved );
		$this->assertSame( 'By chatting, you agree to our <a href="https://example.test/privacy/">privacy policy</a>.x', Settings::get( 'privacy.chat_notice' ) );

		$this->assertSame( Settings::get( 'privacy.chat_notice' ), ChatController::behavior_config( 'en' )['privacy_notice'] );
		$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'privacy' => array( 'chat_notice' => str_repeat( 'a', 301 ) ) ) ) );
	}
}
