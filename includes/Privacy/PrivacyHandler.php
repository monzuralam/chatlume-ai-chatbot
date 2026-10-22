<?php
/**
 * WordPress privacy tools.
 *
 * @package Chatlume
 */

namespace Chatlume\Privacy;

use Chatlume\Data\ConversationLog;
use Chatlume\Data\ConversationRepository;
use Chatlume\Data\MessageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects Chatlume to Tools > Export Personal Data and Tools > Erase
 * Personal Data, and suggests privacy policy text (Settings > Privacy).
 *
 * A person's conversations are the ones where they left their email in
 * the lead form, or chatted while logged in with that email. Erasing
 * deletes those conversations with all their messages.
 */
class PrivacyHandler {

	/**
	 * Conversations per export page.
	 */
	private const EXPORT_PAGE = 20;

	/**
	 * Conversations deleted per erase request.
	 */
	private const ERASE_BATCH = 50;

	/**
	 * Hooks the tools.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param mixed $exporters Exporters.
	 * @return mixed
	 */
	public function add_exporter( $exporters ) {
		if ( is_array( $exporters ) ) {
			$exporters['chatlume-ai-chatbot'] = array(
				'exporter_friendly_name' => __( 'Chatlume chat conversations', 'chatlume-ai-chatbot' ),
				'callback'               => array( $this, 'export' ),
			);
		}

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param mixed $erasers Erasers.
	 * @return mixed
	 */
	public function add_eraser( $erasers ) {
		if ( is_array( $erasers ) ) {
			$erasers['chatlume-ai-chatbot'] = array(
				'eraser_friendly_name' => __( 'Chatlume chat conversations', 'chatlume-ai-chatbot' ),
				'callback'             => array( $this, 'erase' ),
			);
		}

		return $erasers;
	}

	/**
	 * Exports a person's conversations, one page at a time.
	 *
	 * @param mixed $email Email address.
	 * @param mixed $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$page          = max( 1, (int) $page );
		$conversations = $this->conversations()->for_person( ...array_merge( self::person( $email ), array( self::EXPORT_PAGE, ( $page - 1 ) * self::EXPORT_PAGE ) ) );
		$messages      = $this->messages();
		$items         = array();

		foreach ( $conversations as $row ) {
			$id   = (int) $row['id'];
			$data = array(
				array(
					'name'  => __( 'Started', 'chatlume-ai-chatbot' ),
					'value' => self::time( (string) $row['created_at'] ),
				),
				array(
					'name'  => __( 'Last message', 'chatlume-ai-chatbot' ),
					'value' => self::time( (string) $row['updated_at'] ),
				),
				array(
					'name'  => __( 'Page', 'chatlume-ai-chatbot' ),
					'value' => (string) $row['page_url'],
				),
				array(
					'name'  => __( 'Language', 'chatlume-ai-chatbot' ),
					'value' => (string) $row['language'],
				),
			);
			if ( '' !== (string) $row['lead_email'] ) {
				$data[] = array(
					'name'  => __( 'Name', 'chatlume-ai-chatbot' ),
					'value' => (string) $row['lead_name'],
				);
				$data[] = array(
					'name'  => __( 'Email', 'chatlume-ai-chatbot' ),
					'value' => (string) $row['lead_email'],
				);
			}
			if ( null !== $row['rating'] && '' !== (string) $row['rating'] ) {
				$data[] = array(
					'name'  => __( 'Rating', 'chatlume-ai-chatbot' ),
					'value' => (int) $row['rating'] > 0 ? __( 'Helpful', 'chatlume-ai-chatbot' ) : __( 'Not helpful', 'chatlume-ai-chatbot' ),
				);
			}
			$data[] = array(
				'name'  => __( 'Messages', 'chatlume-ai-chatbot' ),
				'value' => self::transcript( $messages->transcript( $id, 500 ) ),
			);

			$items[] = array(
				'group_id'          => 'chatlume-conversations',
				'group_label'       => __( 'Chat conversations', 'chatlume-ai-chatbot' ),
				'group_description' => __( 'Conversations with the chatbot on this site.', 'chatlume-ai-chatbot' ),
				'item_id'           => 'chatlume-conversation-' . $id,
				'data'              => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $conversations ) < self::EXPORT_PAGE,
		);
	}

	/**
	 * Deletes a person's conversations, a batch at a time.
	 *
	 * @param mixed $email Email address.
	 * @param mixed $page  Page (unused: deleted conversations no longer match).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$conversations = $this->conversations()->for_person( ...array_merge( self::person( $email ), array( self::ERASE_BATCH, 0 ) ) );
		$removed       = $this->log()->delete( array_map( 'intval', array_column( $conversations, 'id' ) ) );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $conversations ) < self::ERASE_BATCH,
		);
	}

	/**
	 * Suggests privacy policy text (Settings > Privacy > Policy Guide).
	 *
	 * @return void
	 */
	public function add_policy_text(): void {
		// Core accepts suggested text only in wp-admin (admin_init can also be fired elsewhere, e.g. by tools).
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! is_admin() ) {
			return;
		}

		wp_add_privacy_policy_content( __( 'Chatlume – AI Chatbot', 'chatlume-ai-chatbot' ), wp_kses_post( self::policy_text() ) );
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return string HTML.
	 */
	public static function policy_text(): string {
		$paragraphs = array(
			'<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'chatlume-ai-chatbot' ) . '</strong> ' . __( 'This site has a chatbot that answers questions about our content, products, and services.', 'chatlume-ai-chatbot' ),
			__( 'When you use the chat, we receive the messages you write, the address and title of the page you are on, and your language. If you leave your name and email address in the chat, or rate an answer, we receive those too. To prevent abuse, your IP address is turned into a one-way code before it is used; the IP address itself is not stored.', 'chatlume-ai-chatbot' ),
			__( 'To answer you, your messages, the recent conversation, and the page address and title are sent to the AI service this site uses, together with excerpts from our website. That service processes them under its own terms and privacy policy. Some questions are answered directly from saved answers without contacting the AI service.', 'chatlume-ai-chatbot' ),
			__( 'Conversations are stored on this website so we can improve our answers, and are deleted automatically after the period set by the site owner. Your browser keeps your current conversation and a temporary chat session in its local storage, so the chat continues as you move between pages; the chat does not set cookies.', 'chatlume-ai-chatbot' ),
			__( 'If you ask to talk to a person or to receive the conversation by email, it is sent by email to our team or to the email address you gave. If bot protection is turned on, Cloudflare Turnstile checks that you are human when you start a chat.', 'chatlume-ai-chatbot' ),
			__( 'You can ask us for a copy of your conversations, or ask us to delete them, using the email address you gave in the chat.', 'chatlume-ai-chatbot' ),
		);

		return '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';
	}

	/**
	 * Email and matching user ID for a request.
	 *
	 * @param mixed $email Email address.
	 * @return array{0: string, 1: int}
	 */
	private static function person( $email ): array {
		$email = sanitize_email( is_string( $email ) ? $email : '' );
		$user  = '' !== $email ? get_user_by( 'email', $email ) : false;

		return array( $email, $user ? (int) $user->ID : 0 );
	}

	/**
	 * "Y-m-d H:i:s" (UTC) in the site's date and time format.
	 *
	 * @param string $mysql Time.
	 * @return string
	 */
	private static function time( string $mysql ): string {
		$timestamp = strtotime( $mysql . ' UTC' );

		return false === $timestamp ? $mysql : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * A conversation as plain text.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @return string
	 */
	private static function transcript( array $messages ): string {
		$lines = array();
		foreach ( $messages as $message ) {
			$label   = 'user' === $message['role'] ? __( 'Visitor', 'chatlume-ai-chatbot' ) : __( 'Chatbot', 'chatlume-ai-chatbot' );
			$lines[] = $label . ': ' . trim( wp_strip_all_tags( (string) $message['content'] ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Conversations (replaceable in tests).
	 *
	 * @return ConversationRepository
	 */
	protected function conversations(): ConversationRepository {
		return new ConversationRepository();
	}

	/**
	 * Messages (replaceable in tests).
	 *
	 * @return MessageRepository
	 */
	protected function messages(): MessageRepository {
		return new MessageRepository();
	}

	/**
	 * Deleting conversations (replaceable in tests).
	 *
	 * @return ConversationLog
	 */
	protected function log(): ConversationLog {
		return new ConversationLog();
	}
}
