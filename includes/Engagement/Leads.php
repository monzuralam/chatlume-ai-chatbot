<?php
/**
 * Lead capture.
 *
 * @package Chatlume
 */

namespace Chatlume\Engagement;

use Chatlume\Chat\ConversationState;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A visitor's name and email, given once per chat session (before the
 * first message or after a few). The lead is kept with the session, so a
 * form filled in before any conversation exists, and every later
 * conversation of the session, carry it. With conversation storage off it
 * lives only as long as a temporary conversation (30 idle minutes).
 */
class Leads {

	/**
	 * Conversations.
	 *
	 * @var ConversationState
	 */
	private ConversationState $state;

	/**
	 * Constructor.
	 *
	 * @param ConversationState|null $state Conversations (tests).
	 */
	public function __construct( ?ConversationState $state = null ) {
		$this->state = $state ?? new ConversationState();
	}

	/**
	 * Form settings for the widget, or null when lead capture is off.
	 *
	 * @param string $session_id Session ID.
	 * @return array{timing: string, after: int, required: bool, ask_name: bool, consent: string, given: bool}|null
	 */
	public static function config( string $session_id ): ?array {
		if ( ! Settings::get( 'leads.enabled', false ) ) {
			return null;
		}

		$timing = 'before' === Settings::get( 'leads.timing', 'after' ) ? 'before' : 'after';

		return array(
			'timing'   => $timing,
			'after'    => max( 1, (int) Settings::get( 'leads.after', 2 ) ),
			'required' => 'before' === $timing && (bool) Settings::get( 'leads.required', false ),
			'ask_name' => (bool) Settings::get( 'leads.ask_name', true ),
			'consent'  => (string) Settings::get( 'leads.consent', '' ),
			'given'    => null !== self::for_session( $session_id ),
		);
	}

	/**
	 * The lead given in a session, or null.
	 *
	 * @param string $session_id Session ID.
	 * @return array{name: string, email: string}|null
	 */
	public static function for_session( string $session_id ): ?array {
		$lead = get_transient( self::key( $session_id ) );

		return is_array( $lead ) && is_email( (string) ( $lead['email'] ?? '' ) )
			? array(
				'name'  => (string) ( $lead['name'] ?? '' ),
				'email' => (string) $lead['email'],
			)
			: null;
	}

	/**
	 * Saves a lead for the session (and the conversation, if there is one),
	 * then tells the team and add-ons about a new lead.
	 *
	 * @param string $session_id      Session ID.
	 * @param string $conversation_id Conversation ID ('' before the first message).
	 * @param string $name            Name.
	 * @param string $email           Email (already validated).
	 * @param string $page_url        Page the visitor is on.
	 * @return bool Whether this was a new lead for the session.
	 */
	public function save( string $session_id, string $conversation_id, string $name, string $email, string $page_url = '' ): bool {
		$previous = self::for_session( $session_id );
		$lead     = array(
			'name'  => $name,
			'email' => $email,
		);
		set_transient( self::key( $session_id ), $lead, $this->ttl() );

		if ( '' !== $conversation_id ) {
			$this->state->set_lead( $conversation_id, $name, $email );
		}

		$new = null === $previous || strtolower( $previous['email'] ) !== strtolower( $email );
		if ( $new ) {
			if ( Settings::get( 'leads.notify', true ) ) {
				Mailer::new_lead( $lead, $page_url );
			}

			/**
			 * Fires when a visitor leaves their name and email.
			 *
			 * @param array{name: string, email: string} $lead            Lead.
			 * @param string                             $conversation_id Conversation ID ('' before the first message).
			 * @param string                             $page_url        Page the visitor was on.
			 */
			do_action( 'chatlume_lead_captured', $lead, $conversation_id, $page_url );
		}

		return $new;
	}

	/**
	 * Copies the session's lead to a new conversation.
	 *
	 * @param string $conversation_id Conversation ID.
	 * @param string $session_id      Session ID.
	 * @return void
	 */
	public function attach( string $conversation_id, string $session_id ): void {
		$lead = self::for_session( $session_id );
		if ( null !== $lead && '' !== $conversation_id ) {
			$this->state->set_lead( $conversation_id, $lead['name'], $lead['email'] );
		}
	}

	/**
	 * How long a lead is kept with the session.
	 *
	 * @return int Seconds.
	 */
	private function ttl(): int {
		return $this->state->is_stored()
			? max( 1, (int) Settings::get( 'security.session_hours', 24 ) ) * HOUR_IN_SECONDS
			: ConversationState::TRANSIENT_TTL;
	}

	/**
	 * Transient key of a session's lead.
	 *
	 * @param string $session_id Session ID.
	 * @return string
	 */
	private static function key( string $session_id ): string {
		return 'chatlume_lead_' . substr( hash( 'sha256', $session_id ), 0, 32 );
	}
}
