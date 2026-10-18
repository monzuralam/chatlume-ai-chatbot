<?php
/**
 * Human handoff and transcript emails.
 *
 * @package Chatlume
 */

namespace Chatlume\Engagement;

use Chatlume\Chat\ConversationState;
use Chatlume\REST\ChatController;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Talk to a person": links to the contact page, email, phone, and
 * WhatsApp, and "Email this conversation to the team". Visitors can also
 * email a conversation to themselves, but only to the email they gave.
 */
class Handoff {

	/**
	 * Ways to reach a person.
	 */
	public const CHANNELS = array( 'contact', 'email', 'phone', 'whatsapp', 'email_team' );

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
	 * Handoff options for the widget, or null when off.
	 *
	 * @return array{actions: array<int, array{type: string, label: string, href: string}>, email_team: bool}|null
	 */
	public static function config(): ?array {
		if ( ! Settings::get( 'handoff.enabled', false ) ) {
			return null;
		}

		$labels  = array(
			'contact'  => __( 'Contact us', 'chatlume-ai-chatbot' ),
			'email'    => __( 'Email us', 'chatlume-ai-chatbot' ),
			'phone'    => __( 'Call us', 'chatlume-ai-chatbot' ),
			'whatsapp' => __( 'WhatsApp', 'chatlume-ai-chatbot' ),
		);
		$actions = array();
		foreach ( $labels as $type => $label ) {
			$href = ChatController::action_href( 'contact' === $type ? 'url' : $type, (string) Settings::get( 'handoff.' . $type, '' ) );
			if ( '' !== $href ) {
				$actions[] = array(
					'type'  => $type,
					'label' => $label,
					'href'  => $href,
				);
			}
		}

		$email_team = (bool) Settings::get( 'handoff.email_team', true ) && is_email( Mailer::team_address() );
		if ( array() === $actions && ! $email_team ) {
			return null;
		}

		return array(
			'actions'    => $actions,
			'email_team' => $email_team,
		);
	}

	/**
	 * Whether visitors may email a conversation to themselves.
	 *
	 * @return bool
	 */
	public static function transcripts_allowed(): bool {
		return (bool) Settings::get( 'handoff.transcript', true );
	}

	/**
	 * Records a handoff and, for "Email this conversation to the team",
	 * sends the conversation to the team.
	 *
	 * @param string $session_id      Session ID.
	 * @param string $conversation_id Conversation ID (owned by the session).
	 * @param string $channel         One of CHANNELS.
	 * @param string $page_url        Page the visitor is on.
	 * @return true|string True, or the reason it failed: "no_lead" or "mail".
	 */
	public function request( string $session_id, string $conversation_id, string $channel, string $page_url = '' ) {
		$lead = Leads::for_session( $session_id );

		if ( 'email_team' === $channel ) {
			if ( null === $lead ) {
				return 'no_lead';
			}
			if ( ! Mailer::conversation_to_team( $this->state->transcript( $conversation_id ), $lead, $page_url ) ) {
				return 'mail';
			}
		}

		$this->state->note(
			$conversation_id,
			array(
				'handoff'    => $channel,
				'handoff_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);

		/**
		 * Fires when a visitor asks to talk to a person.
		 *
		 * @param string                                  $channel         contact, email, phone, whatsapp, or email_team.
		 * @param string                                  $conversation_id Conversation ID.
		 * @param array{name: string, email: string}|null $lead            The visitor's details, if given.
		 * @param string                                  $page_url        Page the visitor was on.
		 */
		do_action( 'chatlume_handoff_requested', $channel, $conversation_id, $lead, $page_url );

		return true;
	}

	/**
	 * Emails the conversation to the visitor's own lead email.
	 *
	 * @param string $session_id      Session ID.
	 * @param string $conversation_id Conversation ID (owned by the session).
	 * @return true|string True, or "off", "no_lead", or "mail".
	 */
	public function email_visitor( string $session_id, string $conversation_id ) {
		if ( ! self::transcripts_allowed() ) {
			return 'off';
		}
		$lead = Leads::for_session( $session_id );
		if ( null === $lead ) {
			return 'no_lead';
		}

		return Mailer::conversation_to_visitor( $this->state->transcript( $conversation_id ), $lead['email'] ) ? true : 'mail';
	}
}
