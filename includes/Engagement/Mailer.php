<?php
/**
 * Emails about leads and conversations.
 *
 * @package Chatlume
 */

namespace Chatlume\Engagement;

use Chatlume\Chat\BotText;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain-text emails through wp_mail(): new leads and conversations to the
 * team, and a conversation to the visitor who asked for it. Team emails
 * set Reply-To to the visitor, so the team can answer with one click.
 */
final class Mailer {

	/**
	 * The team's address: the setting, or the site admin email.
	 *
	 * @return string
	 */
	public static function team_address(): string {
		$email = sanitize_email( (string) Settings::get( 'handoff.team_email', '' ) );

		return is_email( $email ) ? $email : (string) get_option( 'admin_email', '' );
	}

	/**
	 * Tells the team about a new lead.
	 *
	 * @param array{name: string, email: string} $lead     Lead.
	 * @param string                             $page_url Page the visitor was on.
	 * @return bool Whether the email was handed to the mail system.
	 */
	public static function new_lead( array $lead, string $page_url ): bool {
		$lines = array(
			__( 'A visitor left their details in the chat.', 'chatlume-ai-chatbot' ),
			/* translators: %s: Visitor name. */
			sprintf( __( 'Name: %s', 'chatlume-ai-chatbot' ), '' !== $lead['name'] ? $lead['name'] : '—' ),
			/* translators: %s: Visitor email. */
			sprintf( __( 'Email: %s', 'chatlume-ai-chatbot' ), $lead['email'] ),
		);
		if ( '' !== $page_url ) {
			/* translators: %s: Page address. */
			$lines[] = sprintf( __( 'Page: %s', 'chatlume-ai-chatbot' ), $page_url );
		}
		/* translators: %s: Admin URL. */
		$lines[] = sprintf( __( 'See the conversation in Chatlume: %s', 'chatlume-ai-chatbot' ), admin_url( 'admin.php?page=chatlume#/conversations' ) );

		return self::send(
			self::team_address(),
			/* translators: 1: Site name, 2: Visitor name or email. */
			sprintf( __( '[%1$s] New chat lead: %2$s', 'chatlume-ai-chatbot' ), self::site(), '' !== $lead['name'] ? $lead['name'] : $lead['email'] ),
			$lines,
			$lead['email']
		);
	}

	/**
	 * Sends a conversation to the team (handoff).
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @param array{name: string, email: string}               $lead     Visitor.
	 * @param string                                           $page_url Page the visitor was on.
	 * @return bool
	 */
	public static function conversation_to_team( array $messages, array $lead, string $page_url ): bool {
		$who   = '' !== $lead['name'] ? $lead['name'] . ' <' . $lead['email'] . '>' : $lead['email'];
		$lines = array(
			/* translators: %s: Visitor name and email. */
			sprintf( __( '%s asked to talk to a person. Reply to this email to answer them.', 'chatlume-ai-chatbot' ), $who ),
		);
		if ( '' !== $page_url ) {
			/* translators: %s: Page address. */
			$lines[] = sprintf( __( 'Page: %s', 'chatlume-ai-chatbot' ), $page_url );
		}
		$lines[] = self::transcript( $messages );

		return self::send(
			self::team_address(),
			/* translators: 1: Site name, 2: Visitor name or email. */
			sprintf( __( '[%1$s] Chat handoff: %2$s', 'chatlume-ai-chatbot' ), self::site(), '' !== $lead['name'] ? $lead['name'] : $lead['email'] ),
			$lines,
			$lead['email']
		);
	}

	/**
	 * Sends a conversation to the visitor who asked for it.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @param string                                           $email    The visitor's lead email.
	 * @return bool
	 */
	public static function conversation_to_visitor( array $messages, string $email ): bool {
		return self::send(
			$email,
			/* translators: %s: Site name. */
			sprintf( __( 'Your conversation with %s', 'chatlume-ai-chatbot' ), self::site() ),
			array(
				/* translators: %s: Site name. */
				sprintf( __( 'Here is your conversation on %s.', 'chatlume-ai-chatbot' ), self::site() ),
				self::transcript( $messages ),
				home_url( '/' ),
			)
		);
	}

	/**
	 * A test email to the team address.
	 *
	 * @return bool
	 */
	public static function test(): bool {
		return self::send(
			self::team_address(),
			/* translators: %s: Site name. */
			sprintf( __( '[%s] Chatlume test email', 'chatlume-ai-chatbot' ), self::site() ),
			array(
				__( 'This is a test from Chatlume. Emails about new leads and conversations will reach this address.', 'chatlume-ai-chatbot' ),
				/* translators: %s: Admin URL. */
				sprintf( __( 'Change the address in Chatlume: %s', 'chatlume-ai-chatbot' ), admin_url( 'admin.php?page=chatlume#/integrations' ) ),
			)
		);
	}

	/**
	 * A conversation as plain text.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @return string
	 */
	public static function transcript( array $messages ): string {
		$you   = __( 'You', 'chatlume-ai-chatbot' );
		$bot   = BotText::bot_name();
		$lines = array();
		foreach ( $messages as $message ) {
			$text = trim( wp_strip_all_tags( (string) $message['content'] ) );
			if ( '' === $text ) {
				continue;
			}
			$lines[] = ( 'user' === $message['role'] ? $you : $bot ) . ":\n" . $text;
		}

		return array() === $lines ? __( '(No messages yet.)', 'chatlume-ai-chatbot' ) : implode( "\n\n", $lines );
	}

	/**
	 * Sends a plain-text email.
	 *
	 * @param string   $to       Recipient.
	 * @param string   $subject  Subject.
	 * @param string[] $lines    Paragraphs.
	 * @param string   $reply_to Reply-To address (validated), or ''.
	 * @return bool
	 */
	private static function send( string $to, string $subject, array $lines, string $reply_to = '' ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$reply   = sanitize_email( $reply_to );
		if ( '' !== $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		return (bool) wp_mail( $to, wp_strip_all_tags( $subject ), implode( "\n\n", $lines ), $headers );
	}

	/**
	 * Site name for subjects.
	 *
	 * @return string
	 */
	private static function site(): string {
		return wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
	}
}
