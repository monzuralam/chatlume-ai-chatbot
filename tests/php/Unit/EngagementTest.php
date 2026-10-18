<?php
/**
 * Tests for lead capture, handoff, and transcript emails.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Chatlume\Chat\ConversationState;
use Chatlume\Engagement\Handoff;
use Chatlume\Engagement\Leads;
use Chatlume\Engagement\Mailer;
use Chatlume\REST\EngagementController;
use Chatlume\Security\SessionManager;
use Chatlume\Settings\Settings;
use Chatlume\Tests\Support\HeaderRequest;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Leads per session, emails, handoff rules, and the public routes.
 */
class EngagementTest extends TestCase {

	/**
	 * Emails sent: [to, subject, body, headers].
	 *
	 * @var array<int, array>
	 */
	private array $mail = array();

	/**
	 * Fake conversations.
	 *
	 * @var object
	 */
	private object $state;

	protected function set_up(): void {
		parent::set_up();
		$this->ext_object_cache = true;
		$this->mail             = array();

		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test/' );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.test/wp' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return preg_match( '#^https?://#i', (string) $url ) ? (string) $url : '';
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Mug Shop' );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array() ) {
				$this->mail[] = array( $to, $subject, $body, $headers );
				return true;
			}
		);
		$this->options['admin_email'] = 'owner@example.test';

		$this->state = new class() extends ConversationState {
			/**
			 * Leads set: id => [name, email].
			 *
			 * @var array<string, array>
			 */
			public array $leads = array();

			/**
			 * Notes: id => meta.
			 *
			 * @var array<string, array>
			 */
			public array $notes = array();

			/**
			 * Conversation owned by session "a…a".
			 */
			public function __construct() {
				parent::__construct( null, null, true );
			}

			/** {@inheritDoc} */
			public function find( string $id, string $session_id ): ?array {
				return '7' === $id && str_repeat( 'a', 32 ) === $session_id
					? array(
						'id'            => '7',
						'user_messages' => 1,
					)
					: null;
			}

			/** {@inheritDoc} */
			public function transcript( string $id ): array {
				return array(
					array(
						'role'    => 'user',
						'content' => 'Do you ship to Canada?',
					),
					array(
						'role'    => 'assistant',
						'content' => 'Yes, in **7 days**.',
					),
				);
			}

			/** {@inheritDoc} */
			public function set_lead( string $id, string $name, string $email ): void {
				$this->leads[ $id ] = array( $name, $email );
			}

			/** {@inheritDoc} */
			public function note( string $id, array $meta ): void {
				$this->notes[ $id ] = $meta;
			}
		};
	}

	/**
	 * Stores settings sections.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function settings( array $settings ): void {
		$this->options[ Settings::OPTION ] = $settings;
	}

	/**
	 * A request from this site with a valid session token.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @param string               $sid    Session ID.
	 * @return HeaderRequest
	 */
	private function request( array $params, string $sid = '' ): HeaderRequest {
		$sid    = '' !== $sid ? $sid : str_repeat( 'a', 32 );
		$token  = ( new SessionManager() )->start()['token'];
		$verify = ( new SessionManager() )->verify( $token );
		// Sign a token for the chosen session ID.
		$token = ( function () use ( $sid ) {
			$payload = array(
				'v'   => 1,
				'sid' => $sid,
				'iat' => time(),
				'exp' => time() + 3600,
			);
			return $this->sign( $payload );
		} )->call( new SessionManager() );
		unset( $verify );

		return new HeaderRequest( $params + array( 'token' => $token ), array( 'origin' => 'https://shop.example.test' ) );
	}

	public function test_lead_config_follows_settings_and_the_session(): void {
		$sid = str_repeat( 'b', 32 );
		$this->assertNull( Leads::config( $sid ) );

		$this->settings(
			array(
				'leads' => array(
					'enabled'  => true,
					'timing'   => 'after',
					'required' => true,
					'consent'  => 'I agree to the <a href="https://shop.example.test/privacy">privacy policy</a>.',
				),
			)
		);
		$config = Leads::config( $sid );
		$this->assertSame( 'after', $config['timing'] );
		// "Required" only applies before the first message.
		$this->assertFalse( $config['required'] );
		$this->assertFalse( $config['given'] );

		( new Leads( $this->state ) )->save( $sid, '', 'Ana', 'ana@example.test' );
		$this->assertTrue( Leads::config( $sid )['given'] );
		$this->assertSame(
			array(
				'name'  => 'Ana',
				'email' => 'ana@example.test',
			),
			Leads::for_session( $sid )
		);
	}

	public function test_new_leads_notify_once_and_reach_later_conversations(): void {
		$this->settings( array( 'leads' => array( 'enabled' => true ) ) );
		$sid   = str_repeat( 'a', 32 );
		$leads = new Leads( $this->state );
		Actions\expectDone( 'chatlume_lead_captured' )->once();

		$this->assertTrue( $leads->save( $sid, '', 'Ana', 'ana@example.test', 'https://shop.example.test/mugs' ) );
		$this->assertFalse( $leads->save( $sid, '7', 'Ana B', 'ANA@example.test' ) );

		$this->assertCount( 1, $this->mail );
		list( $to, $subject, $body, $headers ) = $this->mail[0];
		$this->assertSame( 'owner@example.test', $to );
		$this->assertSame( '[Mug Shop] New chat lead: Ana', $subject );
		$this->assertStringContainsString( 'Email: ana@example.test', $body );
		$this->assertStringContainsString( 'Page: https://shop.example.test/mugs', $body );
		$this->assertContains( 'Reply-To: ana@example.test', $headers );
		$this->assertSame( array( 'Ana B', 'ANA@example.test' ), $this->state->leads['7'] );

		$leads->attach( '8', $sid );
		$this->assertSame( array( 'Ana B', 'ANA@example.test' ), $this->state->leads['8'] );
	}

	public function test_notifications_can_be_off_and_use_the_team_address(): void {
		$this->settings(
			array(
				'leads'   => array( 'notify' => false ),
				'handoff' => array( 'team_email' => 'team@example.test' ),
			)
		);
		( new Leads( $this->state ) )->save( str_repeat( 'c', 32 ), '', '', 'bo@example.test' );
		$this->assertSame( array(), $this->mail );
		$this->assertSame( 'team@example.test', Mailer::team_address() );
	}

	public function test_handoff_config_lists_only_usable_ways(): void {
		$this->assertNull( Handoff::config() );

		$this->settings(
			array(
				'handoff' => array(
					'enabled'    => true,
					'contact'    => 'https://shop.example.test/contact',
					'email'      => 'help@example.test',
					'phone'      => 'x',
					'whatsapp'   => '+44 20 7946 0000',
					'email_team' => true,
				),
			)
		);
		$config = Handoff::config();

		$this->assertSame( array( 'contact', 'email', 'whatsapp' ), array_column( $config['actions'], 'type' ) );
		$this->assertSame( 'mailto:help@example.test', $config['actions'][1]['href'] );
		$this->assertSame( 'https://wa.me/442079460000', $config['actions'][2]['href'] );
		$this->assertTrue( $config['email_team'] );

		$this->settings(
			array(
				'handoff' => array(
					'enabled'    => true,
					'email_team' => false,
				),
			)
		);
		$this->assertNull( Handoff::config(), 'Nothing to offer.' );
	}

	public function test_email_to_the_team_needs_the_visitors_email(): void {
		$this->settings( array( 'handoff' => array( 'enabled' => true ) ) );
		$sid     = str_repeat( 'a', 32 );
		$handoff = new Handoff( $this->state );

		$this->assertSame( 'no_lead', $handoff->request( $sid, '7', 'email_team' ) );
		$this->assertSame( array(), $this->mail );

		( new Leads( $this->state ) )->save( $sid, '7', 'Ana', 'ana@example.test' );
		$this->mail = array();
		Actions\expectDone( 'chatlume_handoff_requested' )->twice();

		$this->assertTrue( $handoff->request( $sid, '7', 'email_team', 'https://shop.example.test/' ) );
		list( $to, $subject, $body, $headers ) = $this->mail[0];
		$this->assertSame( 'owner@example.test', $to );
		$this->assertSame( '[Mug Shop] Chat handoff: Ana', $subject );
		$this->assertStringContainsString( "You:\nDo you ship to Canada?", $body );
		$this->assertStringContainsString( 'Yes, in **7 days**.', $body );
		$this->assertContains( 'Reply-To: ana@example.test', $headers );
		$this->assertSame( 'email_team', $this->state->notes['7']['handoff'] );

		$this->assertTrue( $handoff->request( $sid, '7', 'phone' ) );
		$this->assertCount( 1, $this->mail, 'Links are only recorded.' );
	}

	public function test_visitors_get_transcripts_only_at_their_own_email(): void {
		$sid     = str_repeat( 'a', 32 );
		$handoff = new Handoff( $this->state );

		$this->assertSame( 'no_lead', $handoff->email_visitor( $sid, '7' ) );

		( new Leads( $this->state ) )->save( $sid, '7', '', 'ana@example.test' );
		$this->mail = array();
		$this->assertTrue( $handoff->email_visitor( $sid, '7' ) );
		$this->assertSame( 'ana@example.test', $this->mail[0][0] );
		$this->assertSame( 'Your conversation with Mug Shop', $this->mail[0][1] );

		$this->settings( array( 'handoff' => array( 'transcript' => false ) ) );
		$this->assertSame( 'off', $handoff->email_visitor( $sid, '7' ) );
	}

	public function test_lead_route_checks_email_consent_and_availability(): void {
		$controller = new EngagementController( $this->state );

		$off = $controller->lead( $this->request( array( 'email' => 'ana@example.test' ) ) );
		$this->assertSame( 'chatlume_disabled', $off->get_error_code() );

		$this->settings(
			array(
				'leads' => array(
					'enabled' => true,
					'consent' => 'I agree.',
				),
			)
		);
		$this->assertSame( 'chatlume_invalid_email', $controller->lead( $this->request( array( 'email' => 'nope' ) ) )->get_error_code() );
		$this->assertSame( 'chatlume_consent', $controller->lead( $this->request( array( 'email' => 'ana@example.test' ) ) )->get_error_code() );

		$saved = $controller->lead(
			$this->request(
				array(
					'email'           => 'ana@example.test',
					'name'            => ' Ana ',
					'consent'         => true,
					'conversation_id' => '7',
				)
			)
		);
		$this->assertSame( array( 'saved' => true ), $saved->get_data() );
		$this->assertSame( array( 'Ana', 'ana@example.test' ), $this->state->leads['7'] );
	}

	public function test_routes_refuse_other_sites_bad_sessions_and_floods(): void {
		$this->settings( array( 'leads' => array( 'enabled' => true ) ) );
		$controller = new EngagementController( $this->state );

		$foreign = new HeaderRequest( array( 'email' => 'a@example.test' ), array( 'origin' => 'https://evil.test' ) );
		$this->assertSame( 'chatlume_forbidden_origin', $controller->lead( $foreign )->get_error_code() );

		$expired = new HeaderRequest(
			array(
				'email' => 'a@example.test',
				'token' => 'abc.def',
			),
			array( 'origin' => 'https://shop.example.test' )
		);
		$this->assertSame( 'chatlume_invalid_session', $controller->lead( $expired )->get_error_code() );

		// Mistyped addresses do not use up the limit.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 'chatlume_invalid_email', $controller->lead( $this->request( array( 'email' => 'typo' ) ) )->get_error_code() );
		}
		for ( $i = 0; $i < EngagementController::EMAIL_LIMIT; $i++ ) {
			$this->assertNotInstanceOf( WP_Error::class, $controller->lead( $this->request( array( 'email' => "v$i@example.test" ) ) ) );
		}
		$flood = $controller->lead( $this->request( array( 'email' => 'more@example.test' ) ) );
		$this->assertSame( 429, $flood->get_error_data()['status'] );
	}

	public function test_handoff_and_transcript_routes_need_an_owned_conversation(): void {
		$this->settings(
			array(
				'handoff' => array(
					'enabled' => true,
					'phone'   => '+1 555 0100',
				),
			)
		);
		$controller = new EngagementController( $this->state );

		$missing = $controller->handoff(
			$this->request(
				array(
					'conversation_id' => '99',
					'channel'         => 'phone',
				)
			)
		);
		$this->assertSame( 'chatlume_not_found', $missing->get_error_code() );

		$unavailable = $controller->handoff(
			$this->request(
				array(
					'conversation_id' => '7',
					'channel'         => 'whatsapp',
				)
			)
		);
		$this->assertSame( 'chatlume_disabled', $unavailable->get_error_code() );

		$recorded = $controller->handoff(
			$this->request(
				array(
					'conversation_id' => '7',
					'channel'         => 'phone',
				)
			)
		);
		$this->assertSame( array( 'sent' => false ), $recorded->get_data() );

		$no_lead = $controller->transcript( $this->request( array( 'conversation_id' => '7' ) ) );
		$this->assertSame( 409, $no_lead->get_error_data()['status'] );
	}
}
