<?php
/**
 * Webhooks.
 *
 * @package Chatlume
 */

namespace Chatlume\Integrations;

use Chatlume\Data\ConversationRepository;
use Chatlume\Data\MessageRepository;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends signed JSON to the addresses the admin chose, for new leads, ended
 * conversations, negative feedback, and handoff requests. Deliveries run
 * in the background (WP-Cron), so visitors never wait; a failed delivery
 * is retried once after five minutes. The last 50 deliveries are logged
 * without their contents.
 *
 * Each request carries `X-Chatlume-Signature: sha256=<HMAC of the body>`
 * made with the site's webhook secret.
 */
class Webhooks {

	/**
	 * Events a webhook can subscribe to.
	 */
	public const EVENTS = array( 'lead_captured', 'conversation_ended', 'negative_feedback', 'handoff_requested' );

	/**
	 * Cron hook for one delivery.
	 */
	public const DELIVER_HOOK = 'chatlume_webhook_deliver';

	/**
	 * Cron hook for finding ended conversations.
	 */
	public const ENDED_HOOK = 'chatlume_conversations_ended';

	/**
	 * Signing secret option.
	 */
	public const SECRET_OPTION = 'chatlume_webhook_secret';

	/**
	 * Delivery log option (not autoloaded).
	 */
	public const LOG_OPTION = 'chatlume_webhook_log';

	/**
	 * Entries kept in the log.
	 */
	public const LOG_SIZE = 50;

	/**
	 * A conversation counts as ended after this long without messages.
	 */
	public const IDLE = 30 * MINUTE_IN_SECONDS;

	/**
	 * Seconds to wait for the receiving server.
	 */
	private const TIMEOUT = 5;

	/**
	 * Seconds before the one retry.
	 */
	private const RETRY_AFTER = 300;

	/**
	 * Hooks events and background jobs.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'chatlume_lead_captured', array( $this, 'on_lead' ), 10, 3 );
		add_action( 'chatlume_handoff_requested', array( $this, 'on_handoff' ), 10, 4 );
		add_action( 'chatlume_feedback_received', array( $this, 'on_feedback' ), 10, 3 );
		add_action( self::DELIVER_HOOK, array( $this, 'deliver' ), 10, 5 );
		add_action( self::ENDED_HOOK, array( $this, 'find_ended' ) );
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		// Checked when settings change and on admin pages; front-end requests never pay for it.
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'schedule_ended_check' ) );
		add_action( 'admin_init', array( self::class, 'schedule_ended_check' ) );
	}

	/**
	 * Adds a 15-minute WP-Cron schedule.
	 *
	 * @param mixed $schedules Schedules.
	 * @return mixed
	 */
	public static function schedules( $schedules ) {
		if ( is_array( $schedules ) ) {
			$schedules['chatlume_quarter_hour'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Chatlume)', 'chatlume-ai-chatbot' ),
			);
		}

		return $schedules;
	}

	/**
	 * Runs the ended-conversation check only while a webhook listens for it.
	 *
	 * @return void
	 */
	public static function schedule_ended_check(): void {
		$next = wp_next_scheduled( self::ENDED_HOOK );
		if ( self::listens( 'conversation_ended' ) ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'chatlume_quarter_hour', self::ENDED_HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::ENDED_HOOK );
		}
	}

	/**
	 * Webhooks with a usable address and their events.
	 *
	 * @return array<int, array{url: string, events: string[]}>
	 */
	public static function endpoints(): array {
		$endpoints = array();
		foreach ( (array) Settings::get( 'integrations.webhooks', array() ) as $webhook ) {
			$url = esc_url_raw( trim( (string) ( $webhook['url'] ?? '' ) ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				continue;
			}
			$endpoints[] = array(
				'url'    => $url,
				'events' => array_values( array_intersect( self::EVENTS, (array) ( $webhook['events'] ?? array() ) ) ),
			);
		}

		return $endpoints;
	}

	/**
	 * Whether any webhook listens for an event.
	 *
	 * @param string $event Event.
	 * @return bool
	 */
	public static function listens( string $event ): bool {
		foreach ( self::endpoints() as $endpoint ) {
			if ( in_array( $event, $endpoint['events'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A lead was captured.
	 *
	 * @param mixed $lead            {name, email}.
	 * @param mixed $conversation_id Conversation ID.
	 * @param mixed $page_url        Page.
	 * @return void
	 */
	public function on_lead( $lead, $conversation_id = '', $page_url = '' ): void {
		$this->queue(
			'lead_captured',
			array(
				'lead'            => self::lead( $lead ),
				'conversation_id' => (string) $conversation_id,
				'page_url'        => (string) $page_url,
			)
		);
	}

	/**
	 * A visitor asked for a person.
	 *
	 * @param mixed $channel         contact, email, phone, whatsapp, or email_team.
	 * @param mixed $conversation_id Conversation ID.
	 * @param mixed $lead            {name, email} or null.
	 * @param mixed $page_url        Page.
	 * @return void
	 */
	public function on_handoff( $channel, $conversation_id = '', $lead = null, $page_url = '' ): void {
		$this->queue(
			'handoff_requested',
			array(
				'channel'         => (string) $channel,
				'conversation_id' => (string) $conversation_id,
				'lead'            => is_array( $lead ) ? self::lead( $lead ) : null,
				'page_url'        => (string) $page_url,
			)
		);
	}

	/**
	 * A visitor rated an answer.
	 *
	 * @param mixed $conversation_id Conversation ID.
	 * @param mixed $message_id      Message ID.
	 * @param mixed $rating          1, -1, or 0.
	 * @return void
	 */
	public function on_feedback( $conversation_id, $message_id, $rating ): void {
		if ( -1 !== (int) $rating || ! self::listens( 'negative_feedback' ) ) {
			return;
		}

		$message = ( new MessageRepository() )->find( (int) $message_id, (int) $conversation_id );
		$this->queue(
			'negative_feedback',
			array(
				'conversation_id' => (string) $conversation_id,
				'message_id'      => (int) $message_id,
				'answer'          => null !== $message ? $message['content'] : '',
			)
		);
	}

	/**
	 * Reports stored conversations that went quiet (WP-Cron, every 15 minutes).
	 *
	 * @return int Conversations reported.
	 */
	public function find_ended(): int {
		if ( ! self::listens( 'conversation_ended' ) ) {
			return 0;
		}

		$conversations = new ConversationRepository();
		$messages      = new MessageRepository();
		$now           = time();
		$count         = 0;
		// Only the last two days, so switching the event on does not send old history.
		$rows = $conversations->idle_between( gmdate( 'Y-m-d H:i:s', $now - self::IDLE ), gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ) );

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$conversations->merge_meta( $id, array( 'ended_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now ) ) );
			$this->queue(
				'conversation_ended',
				array(
					'conversation_id' => (string) $id,
					'page_url'        => (string) $row['page_url'],
					'language'        => (string) $row['language'],
					'lead'            => '' !== (string) $row['lead_email'] ? self::lead(
						array(
							'name'  => $row['lead_name'],
							'email' => $row['lead_email'],
						)
					) : null,
					'rating'          => null === $row['rating'] ? null : (int) $row['rating'],
					'started_at'      => self::iso( (string) $row['created_at'] ),
					'last_message_at' => self::iso( (string) $row['updated_at'] ),
					'messages'        => $messages->transcript( $id, 100 ),
				)
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Queues an event for every webhook that listens for it.
	 *
	 * @param string               $event Event.
	 * @param array<string, mixed> $data  Event data.
	 * @return int Deliveries queued.
	 */
	public function queue( string $event, array $data ): int {
		$queued = 0;
		foreach ( self::endpoints() as $endpoint ) {
			if ( ! in_array( $event, $endpoint['events'], true ) ) {
				continue;
			}
			$delivery = wp_generate_uuid4();
			wp_schedule_single_event( time(), self::DELIVER_HOOK, array( md5( $endpoint['url'] ), $event, self::payload( $event, $data, $delivery ), 1, $delivery ) );
			++$queued;
		}

		if ( $queued > 0 && function_exists( 'spawn_cron' ) ) {
			// Start the background run now rather than on the next page view.
			spawn_cron();
		}

		return $queued;
	}

	/**
	 * Sends one queued delivery (WP-Cron); retries once on failure.
	 *
	 * @param mixed $url_hash md5 of the webhook address.
	 * @param mixed $event    Event.
	 * @param mixed $payload  Payload.
	 * @param mixed $attempt  1 or 2.
	 * @param mixed $delivery Delivery ID.
	 * @return array{ok: bool, status: int, error: string}
	 */
	public function deliver( $url_hash, $event, $payload, $attempt = 1, $delivery = '' ): array {
		$url = '';
		foreach ( self::endpoints() as $endpoint ) {
			if ( hash_equals( md5( $endpoint['url'] ), (string) $url_hash ) && in_array( $event, $endpoint['events'], true ) ) {
				$url = $endpoint['url'];
			}
		}
		// The webhook was removed or no longer wants this event.
		if ( '' === $url || ! is_array( $payload ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => 'removed',
			);
		}
		// Overlapping WP-Cron runs can pick up the same event; send each attempt once.
		if ( self::logged( (string) $delivery, (int) $attempt ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => 'duplicate',
			);
		}

		$result = $this->send( $url, (string) $event, $payload, (string) $delivery, (int) $attempt );
		if ( ! $result['ok'] && (int) $attempt < 2 ) {
			wp_schedule_single_event( time() + self::RETRY_AFTER, self::DELIVER_HOOK, array( $url_hash, $event, $payload, 2, $delivery ) );
		}

		return $result;
	}

	/**
	 * Sends a test event to an address right away.
	 *
	 * @param string $url Address.
	 * @return array{ok: bool, status: int, error: string, ms: int}
	 */
	public function test( string $url ): array {
		$delivery = wp_generate_uuid4();

		return $this->send(
			$url,
			'test',
			self::payload( 'test', array( 'message' => __( 'This is a test from Chatlume.', 'chatlume-ai-chatbot' ) ), $delivery ),
			$delivery,
			1
		);
	}

	/**
	 * Posts signed JSON and logs the result.
	 *
	 * @param string               $url      Address.
	 * @param string               $event    Event.
	 * @param array<string, mixed> $payload  Payload.
	 * @param string               $delivery Delivery ID.
	 * @param int                  $attempt  Attempt.
	 * @return array{ok: bool, status: int, error: string, ms: int}
	 */
	private function send( string $url, string $event, array $payload, string $delivery, int $attempt ): array {
		$body    = (string) wp_json_encode( $payload );
		$started = microtime( true );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'Chatlume/' . CHATLUME_VERSION . '; ' . home_url( '/' ),
				'headers'     => array(
					'Content-Type'         => 'application/json',
					'X-Chatlume-Event'     => $event,
					'X-Chatlume-Delivery'  => $delivery,
					'X-Chatlume-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, self::secret() ),
				),
				'body'        => $body,
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$result = array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'error'  => is_wp_error( $response ) ? mb_substr( $response->get_error_message(), 0, 200 ) : '',
			'ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);

		self::log(
			array(
				'id'      => $delivery,
				'event'   => $event,
				'host'    => (string) wp_parse_url( $url, PHP_URL_HOST ),
				'status'  => $result['status'],
				'ok'      => $result['ok'],
				'error'   => $result['error'],
				'attempt' => $attempt,
				'ms'      => $result['ms'],
				'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);

		return $result;
	}

	/**
	 * The request body.
	 *
	 * @param string               $event    Event.
	 * @param array<string, mixed> $data     Event data.
	 * @param string               $delivery Delivery ID.
	 * @return array<string, mixed>
	 */
	public static function payload( string $event, array $data, string $delivery ): array {
		$payload = array(
			'event'      => $event,
			'id'         => $delivery,
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'       => array(
				'name' => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'url'  => home_url( '/' ),
			),
			'data'       => $data,
		);

		/**
		 * Filters a webhook body before it is signed and sent.
		 *
		 * @param array<string, mixed> $payload Body.
		 * @param string               $event   Event.
		 */
		$filtered = apply_filters( 'chatlume_webhook_payload', $payload, $event );

		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * The signing secret (created on first use).
	 *
	 * @return string
	 */
	public static function secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			$secret = self::regenerate_secret();
		}

		return $secret;
	}

	/**
	 * Replaces the signing secret.
	 *
	 * @return string The new secret.
	 */
	public static function regenerate_secret(): string {
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( self::SECRET_OPTION, $secret, false );

		return $secret;
	}

	/**
	 * The delivery log, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function deliveries(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Empties the delivery log.
	 *
	 * @return void
	 */
	public static function clear_log(): void {
		update_option( self::LOG_OPTION, array(), false );
	}

	/**
	 * Whether an attempt of a delivery was already sent.
	 *
	 * @param string $delivery Delivery ID.
	 * @param int    $attempt  Attempt.
	 * @return bool
	 */
	private static function logged( string $delivery, int $attempt ): bool {
		if ( '' === $delivery ) {
			return false;
		}
		foreach ( self::deliveries() as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $delivery && (int) ( $entry['attempt'] ?? 0 ) === $attempt ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds a log entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return void
	 */
	private static function log( array $entry ): void {
		$log = self::deliveries();
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/**
	 * A lead as sent.
	 *
	 * @param mixed $lead {name, email}.
	 * @return array{name: string, email: string}
	 */
	private static function lead( $lead ): array {
		$lead = is_array( $lead ) ? $lead : array();

		return array(
			'name'  => (string) ( $lead['name'] ?? '' ),
			'email' => (string) ( $lead['email'] ?? '' ),
		);
	}

	/**
	 * UTC "Y-m-d H:i:s" as ISO 8601.
	 *
	 * @param string $mysql Time.
	 * @return string
	 */
	private static function iso( string $mysql ): string {
		return '' === $mysql ? '' : str_replace( ' ', 'T', $mysql ) . 'Z';
	}
}
